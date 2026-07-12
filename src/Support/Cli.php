<?php

declare(strict_types=1);

namespace PanicMic\Support;

/**
 * Shared helper for code that exec()s a detached PHP CLI worker from
 * within a normal request (WsManager's daemon spawn, VideoCacheService's
 * per-request download worker, ...).
 */
final class Cli
{
    /**
     * Resolve a real PHP *CLI* binary to exec() a worker script with.
     *
     * PHP_BINARY is only the CLI interpreter when this code is itself
     * running under the cli SAPI. Under php-fpm (the normal case — this
     * runs from an ordinary page render), PHP_BINARY resolves to the
     * php-fpm binary itself (e.g. /usr/sbin/php-fpm8.2). exec()-ing that
     * against a script path doesn't run the script at all: php-fpm just
     * tries to parse it as one of its own CLI flags, fails, and dumps its
     * usage/help text to stderr — the worker never actually starts, and
     * every trigger silently no-ops forever. (This exact bug previously
     * broke the WS daemon's auto-spawn under php-fpm.)
     */
    public static function phpBinary(): string
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
