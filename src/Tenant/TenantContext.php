<?php

declare(strict_types=1);

namespace NextUp\Tenant;

use NextUp\Database\Connection;
use NextUp\Support\Env;
use NextUp\Support\Request;
use NextUp\Support\Response;
use PDO;

final class TenantContext
{
    /** @param array<string,mixed> $tenant */
    public function __construct(public array $tenant, public PDO $db)
    {
    }

    public static function resolve(): ?self
    {
        $path = Request::path();
        $host = self::host();
        if ($host === '' || !self::allowed($host)) {
            self::respondNotConfigured($host, 'unrecognized', 400);
        }
        if (str_starts_with($path, '/super')
            || str_starts_with($path, '/api/super')
            || str_starts_with($path, '/signup')
            || str_starts_with($path, '/api/signup')
            || str_starts_with($path, '/assets')
            || $path === '/health'
        ) {
            return null;
        }

        $stmt = Connection::super()->prepare(
            "SELECT t.*, d.domain
             FROM tenant_domains d
             JOIN tenants t ON t.id = d.tenant_id
             WHERE d.domain = ? AND t.status = 'active'
             LIMIT 1"
        );
        $stmt->execute([$host]);
        $tenant = $stmt->fetch();
        if (!$tenant) {
            self::respondNotConfigured($host, 'unknown', 404);
        }
        return new self($tenant, Connection::tenant($tenant['database_name']));
    }

    public static function host(): string
    {
        $header = (Env::get('TRUST_PROXY') === 'true')
            ? ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? '')
            : ($_SERVER['HTTP_HOST'] ?? '');
        $host = strtolower(trim(explode(',', $header)[0]));
        if (str_starts_with($host, '[')) {
            return substr($host, 1, strpos($host, ']') - 1);
        }
        return explode(':', $host)[0];
    }

    private static function allowed(string $host): bool
    {
        return self::isAllowedHost($host);
    }

    /**
     * Public test seam for the allow-list check. The full resolve()
     * flow side-effects an HTTP response via Response::json, which is
     * awkward to unit-test; isAllowedHost is the pure predicate behind
     * the unknown-host rejection branch.
     */
    public static function isAllowedHost(string $host): bool
    {
        foreach (Env::list('ALLOWED_HOSTS', 'localhost,127.0.0.1') as $allowed) {
            if ($host === $allowed || (str_starts_with($allowed, '*.') && str_ends_with($host, substr($allowed, 1)))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Render a friendly "nothing here" page for browser visitors, JSON
     * for API clients. Used when the request's Host either isn't in
     * ALLOWED_HOSTS at all ($reason = 'unrecognized') or is allowed but
     * has no matching active tenant ($reason = 'unknown').
     */
    private static function respondNotConfigured(string $host, string $reason, int $status): never
    {
        $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
        $wantsHtml = str_contains($accept, 'text/html');
        if (!$wantsHtml) {
            $error = $reason === 'unrecognized' ? 'Unrecognized host' : 'Tenant not found for host';
            Response::json(['error' => $error], $status);
        }

        $marketingHost = (string)(Env::get('MARKETING_HOST', 'panicmic.com') ?? 'panicmic.com');
        $signupHost = (string)(Env::get('SIGNUP_HOST', '') ?? '');
        $signupLink = $signupHost !== '' ? "https://{$signupHost}/" : "https://{$marketingHost}/";
        $safeHost = htmlspecialchars($host !== '' ? $host : 'this address', ENT_QUOTES, 'UTF-8');
        $marketing = htmlspecialchars($marketingHost, ENT_QUOTES, 'UTF-8');
        $signup = htmlspecialchars($signupLink, ENT_QUOTES, 'UTF-8');

        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        echo <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>NextUp - nothing here yet</title>
<style>
  :root { color-scheme: light dark; }
  body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
         margin: 0; min-height: 100vh; display: grid; place-items: center;
         background: linear-gradient(135deg, #1a1d3a 0%, #2d1b4e 100%);
         color: #f5f5fa; padding: 2rem; }
  main { max-width: 30rem; text-align: center; }
  h1 { font-size: 2rem; margin: 0 0 1rem; letter-spacing: -0.02em; }
  p  { line-height: 1.5; margin: 0.75rem 0; opacity: 0.85; }
  code { background: rgba(255,255,255,0.1); padding: 0.1em 0.4em;
         border-radius: 0.25em; font-family: ui-monospace, monospace; }
  .actions { margin-top: 2rem; display: flex; gap: 0.75rem; justify-content: center; flex-wrap: wrap; }
  a.button { display: inline-block; padding: 0.65rem 1.25rem;
             border-radius: 0.5rem; text-decoration: none; font-weight: 600;
             background: #ffd166; color: #1a1d3a; }
  a.button.secondary { background: transparent; color: #f5f5fa;
                       border: 1px solid rgba(255,255,255,0.3); }
</style>
</head>
<body>
<main>
  <h1>Nothing here yet</h1>
  <p>No NextUp venue is configured at <code>{$safeHost}</code>.</p>
  <p>If you're a KJ trying to set up a new venue, you can start a free trial. If you're a singer, double-check the link your venue gave you.</p>
  <div class="actions">
    <a class="button" href="{$signup}">Start a venue</a>
    <a class="button secondary" href="https://{$marketing}/">About NextUp</a>
  </div>
</main>
</body>
</html>
HTML;
        exit;
    }
}
