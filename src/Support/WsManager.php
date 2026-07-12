<?php

declare(strict_types=1);

namespace PanicMic\Support;

/**
 * WsManager — on-demand lifecycle helper for the WebSocket daemon.
 *
 * The daemon (scripts/ws-server.php) is an optional sidecar. Rather than
 * requiring operators to manage it as a system service, WsManager starts it
 * lazily from within a normal PHP page render and lets it exit on its own
 * once all clients have disconnected.
 *
 * Flow:
 *   1. PageRenderer calls WsManager::ensureRunning() when WEBSOCKET_ENABLED=true.
 *   2. ensureRunning() reads storage/ws-server.pid and checks whether that PID
 *      is alive.  If it is, we're done.
 *   3. If not, we spawn the daemon as a fully-detached background process and
 *      return immediately — we don't wait for it to be ready.  The browser
 *      will retry the WebSocket connection with exponential back-off (ws.js),
 *      so a 200–400 ms startup lag is fine in practice.
 *   4. The daemon writes its own PID file on startup and removes it on exit,
 *      so the next check will see the real PID.
 *
 * Race condition: two simultaneous page renders may both see no PID and both
 * try to spawn a daemon.  The second daemon will fail to bind the port, log the
 * error, and exit immediately — harmless.  We don't bother with a lock file
 * because the downside of an extra launch attempt is negligible.
 */
final class WsManager
{
    /**
     * Ensure the WebSocket daemon is running. If WEBSOCKET_ENABLED is false,
     * or exec() is unavailable, this is a no-op. Returns true when the daemon
     * is (or was just started), false when it cannot be managed.
     */
    public static function ensureRunning(): bool
    {
        if (strtolower((string)(Env::get('WEBSOCKET_ENABLED', 'true') ?? 'true')) !== 'true') {
            return false;
        }

        if (self::isRunning()) {
            return true;
        }

        return self::spawn();
    }

    /**
     * Check whether the daemon is currently alive.
     * Reads the PID file and probes the process with signal 0.
     */
    public static function isRunning(): bool
    {
        $pidFile = self::pidFile();
        if (!is_file($pidFile)) {
            return false;
        }
        $pid = (int)@file_get_contents($pidFile);
        if ($pid <= 0) {
            return false;
        }
        return self::pidAlive($pid);
    }

    // ---------------------------------------------------------------- private

    private static function pidFile(): string
    {
        // src/Support/ → up two → project root → storage/ws-server.pid
        return dirname(__DIR__, 2) . '/storage/ws-server.pid';
    }

    private static function logFile(): string
    {
        return dirname(__DIR__, 2) . '/storage/logs/ws-server.log';
    }

    /**
     * Check whether a process with this PID exists.
     * Uses POSIX signal 0 when available, falls back to /proc on Linux.
     */
    private static function pidAlive(int $pid): bool
    {
        if (function_exists('posix_kill')) {
            // signal 0 = existence check, no signal sent
            return posix_kill($pid, 0);
        }
        // Fallback for systems without the POSIX extension (e.g. some shared hosts).
        if (is_dir("/proc/{$pid}")) {
            return true;
        }
        return false;
    }

    /**
     * Spawn the daemon as a fully-detached background process.
     * Returns true if the exec was attempted, false if exec() is unavailable.
     */
    private static function spawn(): bool
    {
        if (!function_exists('exec')) {
            return false;
        }

        $php    = self::cliBinary();
        $script = dirname(__DIR__, 2) . '/scripts/ws-server.php';

        if (!is_file($script)) {
            return false;
        }

        // Ensure the log directory exists before redirecting output there.
        $logFile = self::logFile();
        @mkdir(dirname($logFile), 0755, true);

        // The trailing & fully backgrounds the child. PHP-FPM / the built-in
        // server will not wait for it. Stderr (where ws_log() writes) is
        // appended to the log file so KJs can inspect it when debugging.
        $cmd = $php . ' ' . escapeshellarg($script)
             . ' >> ' . escapeshellarg($logFile) . ' 2>&1 &';

        exec($cmd);
        return true;
    }

    /**
     * Resolve a real PHP *CLI* binary to exec() the daemon with.
     *
     * PHP_BINARY is only the CLI interpreter when this code is itself
     * running under the cli SAPI. Under php-fpm (the normal case — this
     * runs from an ordinary page render), PHP_BINARY resolves to the
     * php-fpm binary itself (e.g. /usr/sbin/php-fpm8.2). exec()-ing that
     * against a script path doesn't run the script at all: php-fpm just
     * tries to parse it as one of its own CLI flags, fails, and dumps its
     * usage/help text to stderr (which is exactly what ends up in
     * storage/logs/ws-server.log) — the daemon never actually starts, and
     * every request silently "retries" this no-op forever.
     */
    private static function cliBinary(): string
    {
        $override = Env::get('PHP_CLI_BINARY');
        if ($override && is_executable($override)) {
            return $override;
        }
        if (PHP_SAPI === 'cli') {
            return PHP_BINARY;
        }
        foreach (['/usr/local/bin/php', '/usr/bin/php', PHP_BINDIR . '/php'] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }
        // Last resort: hope `php` resolves on PATH.
        return 'php';
    }
}
