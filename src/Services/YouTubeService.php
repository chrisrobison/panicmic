<?php

declare(strict_types=1);

namespace NextUp\Services;

use NextUp\Support\Env;
use PDO;

final class YouTubeService
{
    public static function isEnabled(): bool
    {
        return (Env::get('YOUTUBE_AUTO_ATTACH', 'true') !== 'false') && (Env::get('YOUTUBE_API_KEY', '') !== '');
    }

    /** @param array<string,mixed> $song @return array<string,string>|null */
    public static function findKaraokeVideo(array $song): ?array
    {
        $apiKey = Env::get('YOUTUBE_API_KEY', '');
        if (!$apiKey) {
            return null;
        }

        $query = trim((string)$song['artist'] . ' ' . (string)$song['title'] . ' karaoke');
        $params = http_build_query([
            'part' => 'snippet',
            'q' => $query,
            'type' => 'video',
            'videoEmbeddable' => 'true',
            'order' => 'relevance',
            'maxResults' => '25',
            'safeSearch' => 'none',
            'key' => $apiKey,
        ]);

        $context = stream_context_create([
            'http' => [
                'timeout' => 4,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\n",
            ],
        ]);
        $raw = @file_get_contents('https://www.googleapis.com/youtube/v3/search?' . $params, false, $context);
        if (!$raw) {
            return null;
        }

        $data = json_decode($raw, true);
        $items = is_array($data) && is_array($data['items'] ?? null) ? $data['items'] : [];
        $item = self::bestKaraokeItem($items, $song, $apiKey);
        $videoId = $item['id']['videoId'] ?? null;
        if (!is_string($videoId) || $videoId === '') {
            return null;
        }

        return [
            'video_id' => $videoId,
            'title' => (string)($item['snippet']['title'] ?? ''),
            'channel_title' => (string)($item['snippet']['channelTitle'] ?? ''),
            'url' => 'https://www.youtube.com/watch?v=' . rawurlencode($videoId),
            'query' => $query,
        ];
    }

    /** @param list<array<string,mixed>> $items */
    private static function bestKaraokeItem(array $items, array $song, string $apiKey): ?array
    {
        $titleTokens = self::tokens((string)($song['title'] ?? ''));
        $artistTokens = self::tokens((string)($song['artist'] ?? ''));
        $candidates = [];

        foreach ($items as $item) {
            $snippet = $item['snippet'] ?? [];
            $videoId = $item['id']['videoId'] ?? null;
            if (!is_string($videoId) || $videoId === '') {
                continue;
            }
            $videoTitle = self::normalize((string)($snippet['title'] ?? ''));
            $channelTitle = self::normalize((string)($snippet['channelTitle'] ?? ''));
            $hasKaraokeSignal = str_contains($videoTitle, 'karaoke') || str_contains($channelTitle, 'karaoke');
            if (!$hasKaraokeSignal) {
                continue;
            }

            $titleMatches = 0;
            $score = str_contains($videoTitle, 'karaoke') ? 100 : 60;
            foreach ($titleTokens as $token) {
                if (str_contains($videoTitle, $token)) {
                    $titleMatches++;
                    $score += 20;
                }
            }
            $requiredTitleMatches = count($titleTokens) > 1 ? 2 : 1;
            if ($titleMatches < $requiredTitleMatches) {
                continue;
            }
            foreach ($artistTokens as $token) {
                $score += str_contains($videoTitle . ' ' . $channelTitle, $token) ? 8 : 0;
            }
            $candidates[$videoId] = ['item' => $item, 'score' => $score, 'view_count' => 0];
        }

        if (!$candidates) {
            return null;
        }

        $stats = self::videoStats(array_keys($candidates), $apiKey);
        foreach ($stats as $videoId => $stat) {
            if (isset($candidates[$videoId])) {
                $candidates[$videoId]['view_count'] = $stat['view_count'];
                $candidates[$videoId]['item']['snippet'] = $stat['snippet'] ?: $candidates[$videoId]['item']['snippet'];
            }
        }

        usort($candidates, static function (array $a, array $b): int {
            return [$b['view_count'], $b['score']] <=> [$a['view_count'], $a['score']];
        });

        return $candidates[0]['item'];
    }

    /** @param list<string> $videoIds @return array<string,array{view_count:int,snippet:array<string,mixed>}> */
    private static function videoStats(array $videoIds, string $apiKey): array
    {
        $params = http_build_query([
            'part' => 'snippet,statistics',
            'id' => implode(',', $videoIds),
            'key' => $apiKey,
        ]);
        $context = stream_context_create([
            'http' => [
                'timeout' => 4,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\n",
            ],
        ]);
        $raw = @file_get_contents('https://www.googleapis.com/youtube/v3/videos?' . $params, false, $context);
        $data = $raw ? json_decode($raw, true) : null;
        $stats = [];
        foreach (is_array($data) ? ($data['items'] ?? []) : [] as $item) {
            $videoId = $item['id'] ?? null;
            if (!is_string($videoId)) {
                continue;
            }
            $stats[$videoId] = [
                'view_count' => (int)($item['statistics']['viewCount'] ?? 0),
                'snippet' => is_array($item['snippet'] ?? null) ? $item['snippet'] : [],
            ];
        }
        return $stats;
    }

    /** @return list<string> */
    private static function tokens(string $value): array
    {
        $tokens = preg_split('/\s+/', self::normalize($value)) ?: [];
        $stopwords = ['a', 'an', 'and', 'by', 'feat', 'ft', 'in', 'of', 'on', 'the', 'to', 'with'];
        return array_values(array_filter($tokens, static fn (string $token): bool => strlen($token) > 2 && !in_array($token, $stopwords, true)));
    }

    private static function normalize(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';
        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }
    /** @param array<string,string> $video */
    public static function attachToRequest(PDO $db, int $requestId, array $video): void
    {
        $stmt = $db->prepare(
            'UPDATE song_requests
             SET youtube_video_id = ?, youtube_title = ?, youtube_channel_title = ?, youtube_url = ?, youtube_matched_at = NOW()
             WHERE id = ?'
        );
        $stmt->execute([
            $video['video_id'],
            $video['title'],
            $video['channel_title'],
            $video['url'],
            $requestId,
        ]);
    }

    public static function parsePlaylistId(string $input): ?string
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }
        if (preg_match('/[?&]list=([A-Za-z0-9_-]+)/', $input, $m)) {
            return $m[1];
        }
        if (preg_match('/^[A-Za-z0-9_-]{10,}$/', $input)) {
            return $input;
        }
        return null;
    }

    /** @return \Generator<int,array{video_id:string,video_title:string,channel:string}> */
    public static function fetchPlaylist(string $playlistId): \Generator
    {
        $apiKey = Env::get('YOUTUBE_API_KEY', '');
        if (!$apiKey) {
            throw new \RuntimeException('YouTube API key not configured');
        }
        $pageToken = '';
        do {
            $params = http_build_query(array_filter([
                'part' => 'snippet,contentDetails',
                'maxResults' => '50',
                'playlistId' => $playlistId,
                'pageToken' => $pageToken !== '' ? $pageToken : null,
                'key' => $apiKey,
            ], static fn ($v) => $v !== null));
            $context = stream_context_create([
                'http' => [
                    'timeout' => 8,
                    'ignore_errors' => true,
                    'header' => "Accept: application/json\r\n",
                ],
            ]);
            $raw = @file_get_contents('https://www.googleapis.com/youtube/v3/playlistItems?' . $params, false, $context);
            if (!$raw) {
                throw new \RuntimeException('Failed to fetch YouTube playlist (network)');
            }
            $data = json_decode($raw, true);
            if (isset($data['error'])) {
                throw new \RuntimeException('YouTube API error: ' . ($data['error']['message'] ?? 'unknown'));
            }
            foreach ($data['items'] ?? [] as $item) {
                $title = (string)($item['snippet']['title'] ?? '');
                $videoId = (string)($item['contentDetails']['videoId'] ?? '');
                if ($videoId === '' || $title === '' || in_array($title, ['Deleted video', 'Private video'], true)) {
                    continue;
                }
                yield [
                    'video_id' => $videoId,
                    'video_title' => $title,
                    'channel' => (string)($item['snippet']['videoOwnerChannelTitle'] ?? $item['snippet']['channelTitle'] ?? ''),
                ];
            }
            $pageToken = (string)($data['nextPageToken'] ?? '');
        } while ($pageToken !== '');
    }

    /** @return array{artist:string,title:string} */
    public static function parseSongTitle(string $videoTitle): array
    {
        $clean = preg_replace('/\s*[\(\[][^()\[\]]*?(karaoke|instrumental|cover|lyrics|lyric|hd|4k|official(\s+(audio|video|music\s+video))?|video|backing\s+track|sing[ -]?along)\b[^()\[\]]*?[\)\]]\s*/i', ' ', $videoTitle);
        $clean = preg_replace('/\bkaraoke\b/i', '', (string)$clean);
        $clean = trim(preg_replace('/\s+/', ' ', (string)$clean) ?? $videoTitle);
        if ($clean === '') {
            $clean = $videoTitle;
        }

        foreach ([' - ', ' – ', ' — ', ' | ', ' : '] as $sep) {
            if (str_contains($clean, $sep)) {
                [$left, $right] = explode($sep, $clean, 2);
                $left = trim($left);
                $right = trim($right);
                if ($left !== '' && $right !== '') {
                    return ['artist' => $left, 'title' => $right];
                }
            }
        }
        return ['artist' => '', 'title' => $clean];
    }
}
