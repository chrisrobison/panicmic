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
        // Infer extension from the URL path; default to jpg.
        $urlPath = (string)(parse_url($url, PHP_URL_PATH) ?? '');
        $ext = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION));
        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }
        if (!in_array($ext, self::CANDIDATE_EXTENSIONS, true)) {
            $ext = 'jpg';
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => self::DOWNLOAD_TIMEOUT,
                'ignore_errors' => true,
                'header' => "User-Agent: PanicMic/1.0\r\n",
            ],
        ]);
        $data = @file_get_contents($url, false, $context);
        if ($data === false || strlen($data) < 100) {
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
