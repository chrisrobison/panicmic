<?php

declare(strict_types=1);

namespace PanicMic\Services;

use PanicMic\Support\Cli;
use PanicMic\Support\Env;
use PDO;

/**
 * Mirrors a request's YouTube video to this tenant's own content
 * directory so playback runs off a same-origin <video> element instead
 * of an embedded YouTube iframe. The actual download happens in a
 * detached CLI worker (scripts/download-video.php, via yt-dlp) spawned
 * from trigger() — never inline in a request/response cycle.
 *
 * Off by default (VIDEO_CACHE_ENABLED=true to opt in): it shells out to a
 * third-party binary against a video host's own content, which is a
 * meaningfully different risk/resource profile than embedding YouTube's
 * own player, and this box's disk is tight enough that it's worth a
 * deliberate opt-in rather than turning on for every install.
 */
final class VideoCacheService
{
    /** Refuse to keep any single cached video past this size. */
    private const MAX_FILE_BYTES = 150 * 1024 * 1024; // 150MB

    /** Don't start a new download when free space would drop below this. */
    private const MIN_FREE_BYTES = 2 * 1024 * 1024 * 1024; // 2GB

    /** Sweep cache files older than this on every trigger — covers crashed/orphaned downloads. */
    private const STALE_SECONDS = 6 * 3600;

    public static function isEnabled(): bool
    {
        return strtolower((string)(Env::get('VIDEO_CACHE_ENABLED', 'false') ?? 'false')) === 'true'
            && self::ytDlpPath() !== null;
    }

    public static function ytDlpPath(): ?string
    {
        $override = Env::get('YTDLP_PATH');
        if ($override && is_executable($override)) {
            return $override;
        }
        foreach (['/usr/local/bin/yt-dlp', '/usr/bin/yt-dlp'] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    public static function maxFileBytes(): int
    {
        return self::MAX_FILE_BYTES;
    }

    /**
     * Kick off a background download for $requestId if it has a YouTube
     * video attached and isn't already downloading/cached. Every
     * precondition (feature disabled, no yt-dlp, no video attached,
     * already in flight, disk too full) is a silent no-op — callers don't
     * branch on this, playback just keeps using the YouTube iframe until
     * (if ever) a cached copy shows up.
     *
     * @param array<string,mixed> $tenant
     */
    public static function trigger(PDO $db, array $tenant, int $sessionId, int $requestId): void
    {
        if (!self::isEnabled() || !function_exists('exec')) {
            return;
        }
        $stmt = $db->prepare(
            'SELECT youtube_video_id, cached_video_status FROM song_requests WHERE id = ? AND session_id = ? LIMIT 1'
        );
        $stmt->execute([$requestId, $sessionId]);
        $row = $stmt->fetch();
        if (!$row || empty($row['youtube_video_id'])) {
            return;
        }
        if (!in_array($row['cached_video_status'], ['none', 'failed'], true)) {
            return; // already pending or ready
        }

        $cacheDir = ContentService::ensureTenantDirectory((string)$tenant['slug']) . '/kj-cache';
        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
            return;
        }
        self::sweepStale($cacheDir);

        $free = @disk_free_space($cacheDir);
        if ($free !== false && $free < self::MIN_FREE_BYTES) {
            $db->prepare("UPDATE song_requests SET cached_video_status = 'failed' WHERE id = ?")->execute([$requestId]);
            return;
        }

        // Claim it atomically so a second near-simultaneous trigger
        // (e.g. up_next then now_singing moments later) doesn't spawn a
        // duplicate download.
        $claim = $db->prepare(
            "UPDATE song_requests SET cached_video_status = 'pending', cached_video_requested_at = NOW()
             WHERE id = ? AND cached_video_status IN ('none', 'failed')"
        );
        $claim->execute([$requestId]);
        if ($claim->rowCount() === 0) {
            return;
        }

        $php = Cli::phpBinary();
        $script = dirname(__DIR__, 2) . '/scripts/download-video.php';
        if (!is_file($script)) {
            return;
        }
        $logDir = dirname(__DIR__, 2) . '/storage/logs';
        @mkdir($logDir, 0755, true);
        $logFile = $logDir . '/video-cache.log';

        $cmd = $php . ' ' . escapeshellarg($script)
             . ' ' . escapeshellarg((string)$tenant['database_name'])
             . ' ' . escapeshellarg((string)$tenant['slug'])
             . ' ' . escapeshellarg((string)$requestId)
             . ' ' . escapeshellarg((string)$row['youtube_video_id'])
             . ' >> ' . escapeshellarg($logFile) . ' 2>&1 &';
        exec($cmd);
    }

    /**
     * Delete a request's cached video (if any) once it leaves the stage
     * (completed/skipped/canceled) and reset its cache state, so a
     * re-queued/duplicate request starts clean.
     *
     * @param array<string,mixed> $tenant
     */
    public static function cleanup(PDO $db, array $tenant, int $requestId): void
    {
        $stmt = $db->prepare('SELECT cached_video_path FROM song_requests WHERE id = ? LIMIT 1');
        $stmt->execute([$requestId]);
        $path = $stmt->fetchColumn();
        if ($path) {
            self::deleteCachedFile((string)$tenant['slug'], (string)$path);
        }
        $db->prepare(
            "UPDATE song_requests SET cached_video_status = 'none', cached_video_path = NULL, cached_video_requested_at = NULL WHERE id = ?"
        )->execute([$requestId]);
    }

    /** $relativeUrl looks like "/files/kj-cache/req-123-ab12cd.mp4". */
    public static function deleteCachedFile(string $tenantSlug, string $relativeUrl): void
    {
        $prefix = '/files/';
        if (!str_starts_with($relativeUrl, $prefix)) {
            return;
        }
        $dir = realpath(ContentService::tenantDirectory($tenantSlug));
        if (!$dir) {
            return;
        }
        $target = realpath($dir . '/' . substr($relativeUrl, strlen($prefix)));
        if ($target && str_starts_with($target, $dir . DIRECTORY_SEPARATOR) && is_file($target)) {
            @unlink($target);
        }
    }

    /** Remove cache files older than STALE_SECONDS — covers crashed/orphaned downloads. */
    public static function sweepStale(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $cutoff = time() - self::STALE_SECONDS;
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (is_file($file) && (int)filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }
}
