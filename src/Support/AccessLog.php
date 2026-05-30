<?php

declare(strict_types=1);

namespace PanicMic\Support;

/**
 * Structured request access log.
 *
 * PLAN.md Phase 7 (observability) calls for request log aggregation in
 * addition to error capture. This writes one JSON line per request to
 * storage/access.log so an external aggregator (Vector, Fluent Bit,
 * Logtail's agent, etc.) can tail the file and forward it. Lightweight
 * by design — no PSR-3, no daemonised tail, no SDK dependency. If
 * LOG_AGGREGATOR_URL is set, the line is also POSTed best-effort
 * (fire-and-forget) so the file isn't the only path off the box.
 *
 * The log is enabled when ACCESS_LOG=1 (default off in dev, on in prod
 * via .env). Registered from public/index.php via begin()/end() so the
 * timing covers the full request including controller + view render.
 */
final class AccessLog
{
    private static ?float $start = null;
    private static bool $enabled = false;

    public static function begin(): void
    {
        // Resolve the toggle once per request.
        $flag = (string)(Env::get('ACCESS_LOG', '0') ?? '0');
        self::$enabled = $flag === '1' || strtolower($flag) === 'true';
        if (!self::$enabled) {
            return;
        }
        self::$start = microtime(true);
        // Hook the end-of-request so any response status (including
        // 4xx/5xx + fatal errors) is captured. http_response_code()
        // reflects whatever the controller last set.
        register_shutdown_function(static function (): void {
            self::end();
        });
    }

    public static function end(): void
    {
        if (!self::$enabled || self::$start === null) {
            return;
        }
        $start = self::$start;
        self::$start = null; // guard against double-fire

        $tenantUser = $_SESSION['tenant_user'] ?? null;
        $superUser  = $_SESSION['super_admin'] ?? null;

        $entry = [
            'ts'      => date(DATE_ATOM),
            'method'  => $_SERVER['REQUEST_METHOD'] ?? null,
            'path'    => self::scrubPath((string)($_SERVER['REQUEST_URI'] ?? '')),
            'status'  => http_response_code() ?: 0,
            'ms'      => (int)round((microtime(true) - $start) * 1000),
            'host'    => $_SERVER['HTTP_HOST'] ?? null,
            'ip'      => $_SERVER['REMOTE_ADDR'] ?? null,
            'ua'      => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200),
            'tenant'  => self::tenantLabel(),
            'user'    => is_array($tenantUser) ? ($tenantUser['email'] ?? null)
                       : (is_array($superUser) ? ($superUser['email'] ?? null) : null),
            'is_super' => is_array($superUser) ? 1 : 0,
        ];

        self::writeLocal($entry);
        self::forwardIfConfigured($entry);
    }

    private static function tenantLabel(): ?string
    {
        // The front controller stashes the resolved tenant slug in $_SERVER
        // for downstream use; fall back to host header if not set.
        $slug = $_SERVER['PANICMIC_TENANT_SLUG'] ?? null;
        return is_string($slug) && $slug !== '' ? $slug : null;
    }

    /** Strip query strings that may contain tokens. */
    private static function scrubPath(string $uri): string
    {
        $q = strpos($uri, '?');
        return $q === false ? $uri : substr($uri, 0, $q);
    }

    /** @param array<string,mixed> $entry */
    private static function writeLocal(array $entry): void
    {
        $dir = dirname(__DIR__, 2) . '/storage';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents(
            $dir . '/access.log',
            json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n",
            FILE_APPEND | LOCK_EX,
        );
    }

    /** @param array<string,mixed> $entry */
    private static function forwardIfConfigured(array $entry): void
    {
        $endpoint = (string)(Env::get('LOG_AGGREGATOR_URL', '') ?? '');
        if ($endpoint === '') {
            return;
        }
        $body = json_encode($entry, JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            return;
        }
        $token = (string)(Env::get('LOG_AGGREGATOR_TOKEN', '') ?? '');
        $headers = "Content-Type: application/json\r\n";
        if ($token !== '') {
            $headers .= "Authorization: Bearer {$token}\r\n";
        }
        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => $headers,
                'content'       => $body,
                'timeout'       => 1,
                'ignore_errors' => true,
            ],
        ]);
        @file_get_contents($endpoint, false, $context);
    }
}
