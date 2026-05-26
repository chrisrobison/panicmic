<?php

declare(strict_types=1);

namespace NextUp\Support;

/**
 * Minimal observability layer. Forwards uncaught errors and explicit
 * report() calls to Sentry via their HTTP API when SENTRY_DSN is set;
 * otherwise logs structured JSON to storage/errors.log.
 *
 * No SDK dependency — we POST a Sentry envelope directly so the SaaS
 * stays free of composer packages.
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
        $payload = self::serialize($error, $note);
        $dsn = (string)(Env::get('SENTRY_DSN', '') ?? '');
        if ($dsn === '') {
            self::writeLocal($payload);
            return;
        }
        self::shipToSentry($dsn, $payload);
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
        $dir = dirname(__DIR__, 2) . '/storage';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents(
            $dir . '/errors.log',
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n",
            FILE_APPEND | LOCK_EX,
        );
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function shipToSentry(string $dsn, array $payload): void
    {
        // Parse DSN: https://<key>@<host>/<project>
        if (!preg_match('#^(https?)://([^:@]+)(?::[^@]+)?@([^/]+)/(\d+)$#', $dsn, $m)) {
            self::writeLocal($payload + ['_sentry_error' => 'malformed DSN']);
            return;
        }
        [, $scheme, $key, $host, $project] = $m;
        $endpoint = "{$scheme}://{$host}/api/{$project}/store/";

        $body = json_encode([
            'event_id' => bin2hex(random_bytes(16)),
            'timestamp' => $payload['timestamp'],
            'platform' => 'php',
            'environment' => $payload['env'],
            'message' => $payload['message'],
            'logentry' => ['formatted' => $payload['message']],
            'exception' => [
                'values' => [[
                    'type' => $payload['type'],
                    'value' => $payload['message'],
                    'stacktrace' => ['frames' => array_map(static fn ($f) => ['filename' => $f], $payload['trace'])],
                ]],
            ],
            'request' => [
                'url' => $payload['uri'],
                'method' => $payload['method'],
            ],
            'tags' => ['host' => $payload['host']],
        ], JSON_THROW_ON_ERROR);

        $auth = sprintf(
            'Sentry sentry_version=7, sentry_client=nextup-bare/1, sentry_key=%s',
            $key,
        );

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nX-Sentry-Auth: {$auth}\r\n",
                'content' => $body,
                'timeout' => 3,
                'ignore_errors' => true,
            ],
        ]);
        @file_get_contents($endpoint, false, $context);
    }
}
