<?php

declare(strict_types=1);

namespace PanicMic\Services;

use PanicMic\Support\Env;

/**
 * Last.fm enrichment for the shared catalog: album art, album name, top
 * tags, MBID, canonical URL, and listener/playcount popularity.
 *
 * Mirrors YouTubeService: HTTP via file_get_contents + stream context,
 * gated on an API key, with a human-readable lastError(). The response
 * parsing lives in the pure parseTrackInfo() so it can be unit-tested
 * without the network.
 */
final class LastfmService
{
    private const ENDPOINT = 'https://ws.audioscrobbler.com/2.0/';

    /**
     * Last.fm serves this image hash as a "no cover available" placeholder
     * star. Treat it as empty so we fall back to a generated cover.
     */
    private const PLACEHOLDER_HASH = '2a96cbd8b46e442fc41c2b86b821562f';

    /** Largest-first preference when choosing among the image sizes. */
    private const IMAGE_RANK = ['mega' => 5, 'extralarge' => 4, 'large' => 3, 'medium' => 2, 'small' => 1, '' => 0];

    private static ?string $lastError = null;

    public static function isEnabled(): bool
    {
        return Env::get('LASTFM_API_KEY', '') !== '';
    }

    public static function lastError(): ?string
    {
        return self::$lastError;
    }

    /**
     * Fetch enrichment data for one track. Returns the parsed payload, or
     * null when Last.fm is unconfigured / unreachable / has no such track.
     *
     * @return array<string,mixed>|null
     */
    public static function trackInfo(string $artist, string $title): ?array
    {
        self::$lastError = null;
        $apiKey = (string)Env::get('LASTFM_API_KEY', '');
        if ($apiKey === '') {
            self::$lastError = 'Last.fm is not configured (LASTFM_API_KEY is empty).';
            return null;
        }
        $artist = trim($artist);
        $title = trim($title);
        if ($artist === '' || $title === '') {
            self::$lastError = 'Both artist and title are required for a Last.fm lookup.';
            return null;
        }

        $params = http_build_query([
            'method' => 'track.getInfo',
            'api_key' => $apiKey,
            'artist' => $artist,
            'track' => $title,
            'autocorrect' => '1',
            'format' => 'json',
        ]);
        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\n",
            ],
        ]);
        $raw = @file_get_contents(self::ENDPOINT . '?' . $params, false, $context);
        if ($raw === false || $raw === '') {
            self::$lastError = 'Could not reach the Last.fm API (network error or timeout).';
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            self::$lastError = 'Last.fm returned an unreadable response.';
            return null;
        }
        $info = self::parseTrackInfo($data);
        if ($info === null) {
            self::$lastError = isset($data['message'])
                ? 'Last.fm: ' . (string)$data['message']
                : 'No Last.fm data found for "' . $artist . ' - ' . $title . '".';
        }
        return $info;
    }

    /**
     * Pure parser for a track.getInfo response. Returns null on an API
     * error / missing track, otherwise a normalized payload.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>|null
     */
    public static function parseTrackInfo(array $data): ?array
    {
        if (isset($data['error']) || !isset($data['track']) || !is_array($data['track'])) {
            return null;
        }
        $track = $data['track'];
        $album = is_array($track['album'] ?? null) ? $track['album'] : [];

        $images = is_array($album['image'] ?? null) ? $album['image'] : [];
        $albumArt = self::pickImage($images);

        $tags = [];
        $tagList = $track['toptags']['tag'] ?? null;
        if (is_array($tagList)) {
            foreach ($tagList as $tag) {
                $name = trim((string)($tag['name'] ?? ''));
                if ($name !== '') {
                    $tags[] = $name;
                }
            }
        }
        $tags = array_slice(array_values(array_unique($tags)), 0, 5);

        $albumName = trim((string)($album['title'] ?? $album['name'] ?? ''));
        $mbid = trim((string)($track['mbid'] ?? ($album['mbid'] ?? '')));
        $url = trim((string)($track['url'] ?? ''));

        return [
            'album' => $albumName !== '' ? $albumName : null,
            'album_art_url' => $albumArt,
            'mbid' => $mbid !== '' ? $mbid : null,
            'lastfm_url' => $url !== '' ? $url : null,
            'listeners' => isset($track['listeners']) ? (int)$track['listeners'] : null,
            'playcount' => isset($track['playcount']) ? (int)$track['playcount'] : null,
            'tags' => $tags,
            'genre' => $tags[0] ?? null,
        ];
    }

    /**
     * Choose the largest usable image URL, filtering empty entries and the
     * Last.fm "no cover" placeholder star.
     *
     * @param list<array<string,mixed>> $images
     */
    private static function pickImage(array $images): ?string
    {
        $best = null;
        $bestRank = -1;
        foreach ($images as $image) {
            $url = trim((string)($image['#text'] ?? ''));
            if ($url === '' || str_contains($url, self::PLACEHOLDER_HASH)) {
                continue;
            }
            $size = (string)($image['size'] ?? '');
            $rank = self::IMAGE_RANK[$size] ?? 0;
            if ($rank > $bestRank) {
                $best = $url;
                $bestRank = $rank;
            }
        }
        return $best;
    }
}
