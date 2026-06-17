<?php

declare(strict_types=1);

namespace PanicMic\Services\CatalogImport;

use PanicMic\Support\Env;

/**
 * Last.fm tag.getTopTracks adapter.
 *
 * Uses the same HTTP style as LastfmService (file_get_contents + stream context).
 * Requires LASTFM_API_KEY in .env; fails gracefully if missing.
 *
 * Fetches up to $limit tracks per tag using pagination (page size = 50).
 */
final class LastfmTopTracksAdapter implements SourceAdapter
{
    private const ENDPOINT  = 'https://ws.audioscrobbler.com/2.0/';
    private const PAGE_SIZE = 50;
    private const RATE_LIMIT_US = 250_000; // 4 req/s

    /** @param list<array<string,mixed>> $lists */
    public function __construct(
        private readonly array $lists,
        private readonly string $cacheDir,
        private readonly bool $forceFetch = false,
    ) {}

    public function sourceSlug(): string
    {
        return 'lastfm';
    }

    /** @return list<array<string,mixed>> */
    public function lists(): array
    {
        return $this->lists;
    }

    /**
     * Fetch all pages for a tag and return the raw concatenated JSON as a string.
     * The result is a JSON-encoded array of track objects.
     *
     * @param array<string,mixed> $list
     */
    public function fetch(array $list): string
    {
        $apiKey = Env::get('LASTFM_API_KEY', '');
        if ($apiKey === '') {
            throw new \RuntimeException('LASTFM_API_KEY is not configured — skipping Last.fm import');
        }

        $tag   = (string)($list['tag'] ?? '');
        $limit = max(1, min(1000, (int)($list['limit'] ?? 500)));

        $cacheFile = $this->cacheFile($list);
        if (!$this->forceFetch && is_readable($cacheFile)) {
            return file_get_contents($cacheFile) ?: '';
        }

        $tracks  = [];
        $page    = 1;
        $fetched = 0;

        while ($fetched < $limit) {
            $pageSize = min(self::PAGE_SIZE, $limit - $fetched);
            $params   = http_build_query([
                'method'  => 'tag.getTopTracks',
                'tag'     => $tag,
                'api_key' => $apiKey,
                'limit'   => $pageSize,
                'page'    => $page,
                'format'  => 'json',
            ]);

            $ctx = stream_context_create([
                'http' => [
                    'timeout'       => 10,
                    'ignore_errors' => true,
                    'header'        => "Accept: application/json\r\n",
                ],
            ]);

            $raw = @file_get_contents(self::ENDPOINT . '?' . $params, false, $ctx);
            usleep(self::RATE_LIMIT_US);

            if ($raw === false || $raw === '') {
                break;
            }

            $data = json_decode($raw, true);
            if (!is_array($data) || isset($data['error'])) {
                break;
            }

            $items = $data['tracks']['track'] ?? [];
            if (!is_array($items) || $items === []) {
                break;
            }

            foreach ($items as $item) {
                $tracks[] = $item;
                $fetched++;
                if ($fetched >= $limit) {
                    break;
                }
            }

            $totalPages = (int)($data['tracks']['@attr']['totalPages'] ?? 1);
            if ($page >= $totalPages) {
                break;
            }
            $page++;
        }

        $result = json_encode($tracks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Cache
        $dir = dirname($cacheFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($cacheFile, $result);

        return $result;
    }

    /**
     * @param array<string,mixed> $list
     * @return list<array<string,mixed>>
     */
    public function parse(string $raw, array $list): array
    {
        if (trim($raw) === '') {
            return [];
        }
        $tracks = json_decode($raw, true);
        if (!is_array($tracks)) {
            return [];
        }

        $rows = [];
        foreach ($tracks as $rank => $track) {
            if (!is_array($track)) {
                continue;
            }
            $artist = trim((string)($track['artist']['name'] ?? $track['artist'] ?? ''));
            $title  = trim((string)($track['name'] ?? ''));
            if ($artist === '' || $title === '') {
                continue;
            }
            $rows[] = [
                'source_name'  => (string)($list['source_name'] ?? 'Last.fm'),
                'source_slug'  => (string)($list['source_slug'] ?? 'lastfm'),
                'source_type'  => 'api',
                'station'      => null,
                'market'       => null,
                'list_title'   => (string)($list['list_title'] ?? 'Last.fm Top Tracks'),
                'list_slug'    => (string)($list['list_slug']  ?? 'lastfm'),
                'list_type'    => 'genre',
                'year'         => null,
                'decade'       => null,
                'genre_hint'   => ($list['genre_hint'] ?? null) ?: null,
                'url'          => ($track['url'] ?? null) ?: null,
                'rank'         => $rank + 1,
                'artist'       => $artist,
                'title'        => $title,
                'listeners'    => isset($track['listeners']) ? (int)$track['listeners'] : null,
                'mbid'         => ($track['mbid'] ?? '') ?: null,
                'lastfm_url'   => ($track['url'] ?? '') ?: null,
                'raw'          => $track,
            ];
        }
        return $rows;
    }

    private function cacheFile(array $list): string
    {
        $slug = (string)($list['list_slug'] ?? $list['tag'] ?? 'unknown');
        $date = date('Y-m-d');
        return rtrim($this->cacheDir, '/') . '/lastfm/' . $slug . '-' . $date . '.json';
    }
}
