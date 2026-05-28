<?php

declare(strict_types=1);

namespace NextUp\Support;

/**
 * Local-only observability layer.
 *
 * Uncaught exceptions and explicit report() calls are written as
 * structured JSON lines to storage/logs/errors-YYYY-MM-DD.log. One file
 * per day keeps individual files browsable without external tooling,
 * and an admin can prune old days with `find storage/logs -mtime +N
 * -delete` (or a cron).
 *
 * No third-party services, no SDK dependency — just the local disk.
 */
final class ErrorReporter
{
    private static bool $installed = false;

    public static function install(): void
    {
        if (self::$installed) {
            return;
        }
        self::$installed = true;
        set_exception_handler(static function (\Throwable $e): void {
            self::report($e);
        });
        // Convert E_USER_ERROR to exceptions so they surface here; leave
        // PHP warnings on the default handler (they're already in the
        // PHP error log).
        set_error_handler(static function (int $level, string $message, string $file, int $line): bool {
            if ($level === E_USER_ERROR) {
                self::report(new \ErrorException($message, 0, $level, $file, $line));
                return true;
            }
            return false;
        });
    }

    public static function report(\Throwable $error, ?string $note = null): void
    {
        self::writeLocal(self::serialize($error, $note));
    }

    /**
     * Free-form structured log entry. Useful for non-exception events
     * (auth failures, billing webhooks, etc.) that you still want a
     * record of.
     *
     * @param array<string,mixed> $context
     */
    public static function log(string $event, array $context = []): void
    {
        self::writeLocal([
            'timestamp' => date(DATE_ATOM),
            'env' => (string)(Env::get('APP_ENV', 'production') ?? 'production'),
            'event' => $event,
            'host' => $_SERVER['HTTP_HOST'] ?? null,
            'uri' => $_SERVER['REQUEST_URI'] ?? null,
            'method' => $_SERVER['REQUEST_METHOD'] ?? null,
            'context' => $context,
        ]);
    }

    /** @return array<string,mixed> */
    private static function serialize(\Throwable $error, ?string $note): array
    {
        return [
            'timestamp' => date(DATE_ATOM),
            'env' => (string)(Env::get('APP_ENV', 'production') ?? 'production'),
            'note' => $note,
            'type' => get_class($error),
            'message' => $error->getMessage(),
            'file' => $error->getFile(),
            'line' => $error->getLine(),
            'trace' => array_slice(explode("\n", $error->getTraceAsString()), 0, 20),
            'host' => $_SERVER['HTTP_HOST'] ?? null,
            'uri' => $_SERVER['REQUEST_URI'] ?? null,
            'method' => $_SERVER['REQUEST_METHOD'] ?? null,
        ];
    }

    /** @param array<string,mixed> $payload */
    private static function writeLocal(array $payload): void
    {
        $dir = dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $file = $dir . '/errors-' . date('Y-m-d') . '.log';
        @file_put_contents(
            $file,
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n",
            FILE_APPEND | LOCK_EX,
        );
    }
}
