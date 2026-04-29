<?php

declare(strict_types=1);

namespace NextUp\Tenant;

use NextUp\Database\Connection;
use NextUp\Support\Env;
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
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $host = self::host();
        if ($host === '' || !self::allowed($host)) {
            Response::json(['error' => 'Unrecognized host'], 400);
        }
        if (str_starts_with($path, '/super') || str_starts_with($path, '/api/super') || str_starts_with($path, '/assets') || $path === '/health') {
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
            Response::json(['error' => 'Tenant not found for host'], 404);
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
        foreach (Env::list('ALLOWED_HOSTS', 'localhost,127.0.0.1') as $allowed) {
            if ($host === $allowed || (str_starts_with($allowed, '*.') && str_ends_with($host, substr($allowed, 1)))) {
                return true;
            }
        }
        return false;
    }
}
