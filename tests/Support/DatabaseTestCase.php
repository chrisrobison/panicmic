<?php

declare(strict_types=1);

namespace NextUp\Tests\Support;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Base class for tests that need real PDO connections to the test
 * MySQL schemas. Skips the test if MySQL is unreachable.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected PDO $superDb;
    protected PDO $tenantDb;
    protected int $tenantId;
    protected int $sessionId;

    protected function setUp(): void
    {
        if (!defined('NEXTUP_TEST_DB_AVAILABLE')) {
            self::markTestSkipped('MySQL test schemas unavailable: ' . (defined('NEXTUP_TEST_DB_UNAVAILABLE') ? constant('NEXTUP_TEST_DB_UNAVAILABLE') : 'unknown'));
        }
        $this->superDb = $this->connect(TEST_SUPER_DB);
        $this->tenantDb = $this->connect(TEST_TENANT_DB);
        $this->truncate();
        $this->seedTenant();
    }

    private function connect(string $database): PDO
    {
        $host = \NextUp\Support\Env::get('SUPER_DB_HOST', '127.0.0.1');
        $port = \NextUp\Support\Env::get('SUPER_DB_PORT', '3306');
        $user = \NextUp\Support\Env::get('SUPER_DB_USER', 'root') ?? 'root';
        $pass = \NextUp\Support\Env::get('SUPER_DB_PASSWORD', '') ?? '';
        return new PDO(
            "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
    }

    private function truncate(): void
    {
        // Tenant tables — order matters for FKs.
        foreach (['queue_items', 'song_requests', 'announcements', 'singers', 'songs', 'audit_log', 'settings', 'payments_tips', 'display_state', 'realtime_events', 'karaoke_sessions', 'users'] as $table) {
            $this->tenantDb->exec("DELETE FROM `{$table}`");
        }
        // Super tables (leave tenants/tenant_domains alone — we re-seed them).
        foreach (['shared_songs', 'provisioning_jobs', 'super_admin_users'] as $table) {
            try {
                $this->superDb->exec("DELETE FROM `{$table}`");
            } catch (\Throwable) {
                // Older test schemas may not have all tables yet.
            }
        }
        $this->superDb->exec('DELETE FROM tenant_domains');
        $this->superDb->exec('DELETE FROM tenants');
    }

    private function seedTenant(): void
    {
        $this->superDb
            ->prepare("INSERT INTO tenants (slug, venue_name, night_name, database_name, status) VALUES (?, ?, ?, ?, 'active')")
            ->execute([TEST_TENANT_SLUG, 'Test Bar', 'Test Night', TEST_TENANT_DB]);
        $this->tenantId = (int)$this->superDb->lastInsertId();
        $this->superDb
            ->prepare('INSERT INTO tenant_domains (tenant_id, domain) VALUES (?, ?)')
            ->execute([$this->tenantId, TEST_TENANT_DOMAIN]);

        // Each tenant session is the per-night scope for queue items.
        $this->tenantDb->exec("INSERT INTO karaoke_sessions (name, starts_at, status) VALUES ('Test Night Session', NOW(), 'active')");
        $this->sessionId = (int)$this->tenantDb->lastInsertId();
        $this->tenantDb
            ->prepare("INSERT INTO display_state (session_id, mode) VALUES (?, 'idle')")
            ->execute([$this->sessionId]);
    }
}
