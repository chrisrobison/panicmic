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
            'order' => 'viewCount',
            'maxResults' => '1',
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
        $item = is_array($data) ? ($data['items'][0] ?? null) : null;
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
}
