<?php

declare(strict_types=1);

namespace PanicMic\Services;

/**
 * Album art fetcher + local disk cache.
 *
 * Two public entry points:
 *
 *   AlbumArtService::fetch()          — calls LastfmService to look up art,
 *                                       then downloads + caches the image.
 *   AlbumArtService::cacheRemoteUrl() — downloads a caller-supplied URL
 *                                       (e.g. from the Spotify-backed album-art
 *                                       JS library on the frontend) and caches it.
 *
 * Both methods check the local disk cache first; if a file already exists for
 * the (artist, title) pair the download is skipped.
 *
 * Cache key : md5(lower(artist) . '|' . lower(title))
 * Cache dir : content/{tenant-slug}/album-art/
 * Served via : /files/album-art/{key}.{ext}
 */
final class AlbumArtService
{
    private const SUBDIR = 'album-art';
    private const CANDIDATE_EXTENSIONS = ['jpg', 'png', 'webp', 'gif'];
    private const DOWNLOAD_TIMEOUT = 10;
    private const MAX_DOWNLOAD_BYTES = 5_242_880;
    /** @var array<string,string> */
    private const IMAGE_MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    // ------------------------------------------------------------------ //
    //  Public API                                                          //
    // ------------------------------------------------------------------ //

    /**
     * Fetch album art for (artist, title), using the local cache when available.
     * Falls back to LastfmService for the remote URL.
     *
     * Returns a /files/… path on success, null when nothing could be found.
     */
    public static function fetch(string $tenantSlug, string $artist, string $title): ?string
    {
        $key = self::cacheKey($artist, $title);

        $cached = self::findCached($tenantSlug, $key);
        if ($cached !== null) {
            return $cached;
        }

        if (!LastfmService::isEnabled()) {
            return null;
        }

        $info = LastfmService::trackInfo($artist, $title);
        if (!$info || empty($info['album_art_url'])) {
            return null;
        }

        return self::downloadAndCache($tenantSlug, $key, (string)$info['album_art_url']);
    }

    /**
     * Download a caller-supplied remote image URL and store it in the cache.
     * Useful when the frontend has already obtained a URL (e.g. from Spotify
     * via the album-art JS library) and we just need to localise it.
     *
     * Returns the local /files/… path, or null on download failure.
     */
    public static function cacheRemoteUrl(
        string $tenantSlug,
        string $artist,
        string $title,
        string $remoteUrl
    ): ?string {
        $key = self::cacheKey($artist, $title);

        // Already cached — return the existing file.
        $cached = self::findCached($tenantSlug, $key);
        if ($cached !== null) {
            return $cached;
        }

        return self::downloadAndCache($tenantSlug, $key, $remoteUrl);
    }

    /**
     * Deterministic cache key for an (artist, title) pair.
     */
    public static function cacheKey(string $artist, string $title): string
    {
        return md5(strtolower(trim($artist)) . '|' . strtolower(trim($title)));
    }

    // ------------------------------------------------------------------ //
    //  Internals                                                           //
    // ------------------------------------------------------------------ //

    /**
     * Return the local /files/… URL if a cached image exists, null otherwise.
     */
    private static function findCached(string $tenantSlug, string $key): ?string
    {
        $dir = self::cacheDir($tenantSlug);
        foreach (self::CANDIDATE_EXTENSIONS as $ext) {
            $path = $dir . '/' . $key . '.' . $ext;
            if (is_file($path) && filesize($path) > 0) {
                return '/files/' . self::SUBDIR . '/' . $key . '.' . $ext;
            }
        }
        return null;
    }

    /**
     * Download $url and write it to the cache directory.
     */
    private static function downloadAndCache(string $tenantSlug, string $key, string $url): ?string
    {
        $data = self::fetchValidated($url);
        if ($data === null || strlen($data) < 100) {
            return null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo !== false ? (string)finfo_buffer($finfo, $data) : '';
        $ext = self::IMAGE_MIME_EXTENSIONS[$mime] ?? null;
        if ($ext === null) {
            return null;
        }

        $dir = self::ensureCacheDir($tenantSlug);
        $filePath = $dir . '/' . $key . '.' . $ext;
        if (@file_put_contents($filePath, $data) === false) {
            return null;
        }
        @chmod($filePath, 0664);

        return '/files/' . self::SUBDIR . '/' . $key . '.' . $ext;
    }

    /**
     * Fetch a public HTTP(S) image without following an unvalidated redirect.
     * Each hop is DNS-checked to block loopback, private, link-local, and
     * reserved networks. The byte ceiling prevents a remote host from filling
     * tenant storage or exhausting PHP memory.
     */
    private static function fetchValidated(string $url): ?string
    {
        for ($redirects = 0; $redirects <= 3; $redirects++) {
            if (!self::isPublicImageUrl($url)) {
                return null;
            }
            $context = stream_context_create([
                'http' => [
                    'timeout' => self::DOWNLOAD_TIMEOUT,
                    'ignore_errors' => true,
                    'follow_location' => 0,
                    'max_redirects' => 0,
                    'header' => "User-Agent: PanicMic/1.0\r\nAccept: image/*\r\n",
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ],
            ]);
            $data = @file_get_contents(
                $url,
                false,
                $context,
                0,
                self::MAX_DOWNLOAD_BYTES + 1,
            );
            /** @var list<string> $http_response_header */
            $headers = $http_response_header;
            $status = self::responseStatus($headers);
            if ($status >= 300 && $status < 400) {
                $location = self::responseHeader($headers, 'location');
                if ($location === null) {
                    return null;
                }
                $url = self::resolveRedirect($url, $location);
                continue;
            }
            if ($status < 200 || $status >= 300 || $data === false
                || strlen($data) > self::MAX_DOWNLOAD_BYTES
            ) {
                return null;
            }
            return $data;
        }
        return null;
    }

    private static function isPublicImageUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string)($parts['host'] ?? ''), '.'));
        $port = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);
        if (!in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || !in_array($port, [80, 443], true)
        ) {
            return false;
        }

        $addresses = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $addresses[] = $host;
        } else {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            foreach (is_array($records) ? $records : [] as $record) {
                $address = $record['ip'] ?? $record['ipv6'] ?? null;
                if (is_string($address)) {
                    $addresses[] = $address;
                }
            }
        }
        if ($addresses === []) {
            return false;
        }
        foreach (array_unique($addresses) as $address) {
            if (filter_var(
                $address,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) === false) {
                return false;
            }
        }
        return true;
    }

    /** @param list<string> $headers */
    private static function responseStatus(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $header, $match)) {
                return (int)$match[1];
            }
        }
        return 0;
    }

    /** @param list<string> $headers */
    private static function responseHeader(array $headers, string $name): ?string
    {
        $prefix = strtolower($name) . ':';
        foreach ($headers as $header) {
            if (str_starts_with(strtolower($header), $prefix)) {
                return trim(substr($header, strlen($prefix)));
            }
        }
        return null;
    }

    private static function resolveRedirect(string $baseUrl, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $base = parse_url($baseUrl);
        if (!is_array($base) || empty($base['scheme']) || empty($base['host'])) {
            return '';
        }
        if (str_starts_with($location, '//')) {
            return $base['scheme'] . ':' . $location;
        }
        $authority = $base['scheme'] . '://' . $base['host'];
        if (isset($base['port'])) {
            $authority .= ':' . $base['port'];
        }
        if (str_starts_with($location, '/')) {
            return $authority . $location;
        }
        $directory = rtrim(str_replace('\\', '/', dirname((string)($base['path'] ?? '/'))), '/');
        return $authority . ($directory !== '' ? $directory : '') . '/' . $location;
    }

    private static function cacheDir(string $tenantSlug): string
    {
        return ContentService::tenantDirectory($tenantSlug) . '/' . self::SUBDIR;
    }

    private static function ensureCacheDir(string $tenantSlug): string
    {
        $dir = self::cacheDir($tenantSlug);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create album art cache directory: ' . $dir);
        }
        return $dir;
    }
}
