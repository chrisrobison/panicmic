<?php

declare(strict_types=1);

use PanicMic\Database\Connection;
use PanicMic\Services\ContentService;
use PanicMic\Services\EventBus;
use PanicMic\Services\VideoCacheService;
use PanicMic\Support\Env;

require dirname(__DIR__) . '/src/autoload.php';

Env::load(dirname(__DIR__) . '/.env');

/**
 * Background worker: mirror one YouTube video to a tenant's content
 * directory via yt-dlp, then flip the request's cache columns to 'ready'
 * (or 'failed'). Always spawned detached by VideoCacheService::trigger()
 * — never run this synchronously from a request.
 *
 * Usage: php scripts/download-video.php <tenantDbName> <tenantSlug> <requestId> <youtubeVideoId>
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "download-video.php must be run from the command line.\n");
    exit(1);
}

/** @var list<string> $argv */
$argv = $_SERVER['argv'] ?? [];
[$tenantDb, $tenantSlug, $requestIdRaw, $videoId] = [$argv[1] ?? '', $argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? ''];
$requestId = (int)$requestIdRaw;

if ($tenantDb === '' || $tenantSlug === '' || $requestId <= 0 || !preg_match('/^[A-Za-z0-9_-]{6,20}$/', $videoId)) {
    fwrite(STDERR, "Usage: download-video.php <tenantDbName> <tenantSlug> <requestId> <youtubeVideoId>\n");
    exit(1);
}

function log_line(string $message): void
{
    fwrite(STDOUT, '[' . date('c') . "] {$message}\n");
}

try {
    $db = Connection::tenant($tenantDb);
} catch (Throwable $e) {
    log_line('Cannot connect to tenant DB: ' . $e->getMessage());
    exit(1);
}

// Bail quietly if the request moved on (canceled/skipped/cleaned up)
// before the download even started.
$check = $db->prepare("SELECT cached_video_status FROM song_requests WHERE id = ? LIMIT 1");
$check->execute([$requestId]);
$status = $check->fetchColumn();
if ($status !== 'pending') {
    log_line("Request {$requestId} is no longer pending cache ({$status}) — skipping download.");
    exit(0);
}

$ytDlp = VideoCacheService::ytDlpPath();
$ffmpegDir = dirname((string)(shell_exec('command -v ffmpeg') ?: '/usr/bin/ffmpeg'));
if (!$ytDlp) {
    log_line('yt-dlp not found.');
    markFailed($db, $requestId);
    exit(1);
}

$cacheDir = ContentService::ensureTenantDirectory($tenantSlug) . '/kj-cache';
if (!is_dir($cacheDir) && !mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
    log_line("Cannot create cache directory: {$cacheDir}");
    markFailed($db, $requestId);
    exit(1);
}

$basename = 'req-' . $requestId . '-' . bin2hex(random_bytes(4));
$outputTemplate = $cacheDir . '/' . $basename . '.%(ext)s';
$maxBytes = VideoCacheService::maxFileBytes();
$maxSizeArg = (string)(int)round($maxBytes / (1024 * 1024)) . 'M';

$cmd = 'timeout 180 ' . escapeshellarg($ytDlp)
     . ' --no-playlist --quiet --no-progress --no-warnings'
     . ' --max-filesize ' . escapeshellarg($maxSizeArg)
     . ' --ffmpeg-location ' . escapeshellarg($ffmpegDir)
     . ' -f ' . escapeshellarg('bestvideo[height<=480][ext=mp4]+bestaudio[ext=m4a]/best[height<=480][ext=mp4]/best[height<=480]/best')
     . ' --merge-output-format mp4'
     . ' -o ' . escapeshellarg($outputTemplate)
     . ' -- ' . escapeshellarg('https://www.youtube.com/watch?v=' . $videoId)
     . ' 2>&1';

log_line("Downloading {$videoId} for request {$requestId}: {$cmd}");
$output = [];
$exitCode = 0;
exec($cmd, $output, $exitCode);
foreach ($output as $line) {
    log_line('yt-dlp: ' . $line);
}

$finalFile = $cacheDir . '/' . $basename . '.mp4';
if ($exitCode !== 0 || !is_file($finalFile) || filesize($finalFile) <= 0) {
    log_line("Download failed (exit {$exitCode}).");
    @unlink($finalFile);
    // Sweep any partial/leftover files this attempt created.
    foreach (glob($cacheDir . '/' . $basename . '.*') ?: [] as $stray) {
        @unlink($stray);
    }
    markFailed($db, $requestId);
    exit(1);
}
if (filesize($finalFile) > $maxBytes) {
    log_line('Download exceeded the size cap after the fact — discarding.');
    @unlink($finalFile);
    markFailed($db, $requestId);
    exit(1);
}
chmod($finalFile, 0664);

// The request may have left the stage while the download was running —
// don't resurrect it as "ready" if so; just discard the file.
$recheck = $db->prepare("SELECT cached_video_status FROM song_requests WHERE id = ? LIMIT 1");
$recheck->execute([$requestId]);
if ($recheck->fetchColumn() !== 'pending') {
    log_line("Request {$requestId} moved on while downloading — discarding cached file.");
    @unlink($finalFile);
    exit(0);
}

$relativeUrl = '/files/kj-cache/' . basename($finalFile);
$db->prepare("UPDATE song_requests SET cached_video_status = 'ready', cached_video_path = ? WHERE id = ?")
   ->execute([$relativeUrl, $requestId]);
EventBus::publish($db, 'request:video_cached', ['requestId' => $requestId]);
log_line("Cached {$relativeUrl} for request {$requestId}.");
exit(0);

function markFailed(PDO $db, int $requestId): void
{
    $db->prepare("UPDATE song_requests SET cached_video_status = 'failed' WHERE id = ? AND cached_video_status = 'pending'")
       ->execute([$requestId]);
    EventBus::publish($db, 'request:video_cache_failed', ['requestId' => $requestId]);
}
