<?php

declare(strict_types=1);

/**
 * Test bootstrap.
 *
 * - Loads .env so DB credentials are available.
 * - Ensures nextup_test_super and nextup_test_tenant schemas exist and
 *   migrations have been applied to them.
 * - Skips DB-dependent tests gracefully if MySQL is unreachable.
 *
 * Tests that need a clean DB extend NextUp\Tests\DatabaseTestCase, which
 * truncates tables in setUp().
 */

require dirname(__DIR__) . '/src/autoload.php';

// Test-only autoloader: NextUp\Tests\ → tests/
spl_autoload_register(static function (string $class): void {
    $prefix = 'NextUp\\Tests\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

use NextUp\Support\Env;

Env::load(dirname(__DIR__) . '/.env');

// Disable secure session cookies during tests since there's no HTTP layer.
$_ENV['APP_ENV'] = 'test';

const TEST_SUPER_DB  = 'nextup_test_super';
const TEST_TENANT_DB = 'nextup_test_tenant';
const TEST_TENANT_SLUG   = 'testbar';
const TEST_TENANT_DOMAIN = 'test.local';

try {
    $rootDsn = sprintf(
        'mysql:host=%s;port=%s;charset=utf8mb4',
        Env::get('SUPER_DB_HOST', '127.0.0.1'),
        Env::get('SUPER_DB_PORT', '3306'),
    );
    $rootPdo = new PDO(
        $rootDsn,
        Env::get('SUPER_DB_USER', 'root') ?? 'root',
        Env::get('SUPER_DB_PASSWORD', '') ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
} catch (Throwable $e) {
    fwrite(STDERR, "WARNING: MySQL unreachable, DB-backed tests will be skipped: " . $e->getMessage() . "\n");
    define('NEXTUP_TEST_DB_UNAVAILABLE', $e->getMessage());
    return;
}

// Create test schemas and run migrations against them.
$rootPdo->exec("CREATE DATABASE IF NOT EXISTS `" . TEST_SUPER_DB . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$rootPdo->exec("CREATE DATABASE IF NOT EXISTS `" . TEST_TENANT_DB . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

applyMigrations($rootPdo, TEST_SUPER_DB, dirname(__DIR__) . '/migrations/super');
applyMigrations($rootPdo, TEST_TENANT_DB, dirname(__DIR__) . '/migrations/tenant');

// Register a tenant row so TenantContext::resolve has data to find.
$super = connectTestDb($rootPdo, TEST_SUPER_DB);
$super->exec(
    "INSERT IGNORE INTO tenants (slug, venue_name, night_name, database_name, status)
     VALUES ('" . TEST_TENANT_SLUG . "', 'Test Bar', 'Test Night', '" . TEST_TENANT_DB . "', 'active')"
);
$super->exec(
    "INSERT IGNORE INTO tenant_domains (tenant_id, domain)
     SELECT id, '" . TEST_TENANT_DOMAIN . "' FROM tenants WHERE slug = '" . TEST_TENANT_SLUG . "' LIMIT 1"
);

// Ensure the singleton Connection class will use the test DBs in tests
// that touch it. We rewire env so Connection::super() opens the test super.
$_ENV['SUPER_DB_NAME'] = TEST_SUPER_DB;
putenv('SUPER_DB_NAME=' . TEST_SUPER_DB);

define('NEXTUP_TEST_DB_AVAILABLE', true);

/* -------------------------------------------------------------- */

function applyMigrations(PDO $rootPdo, string $database, string $dir): void
{
    $files = glob($dir . '/*.sql') ?: [];
    sort($files, SORT_STRING);
    $rootPdo->exec("USE `{$database}`");
    foreach ($files as $file) {
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException("Cannot read {$file}");
        }
        $rootPdo->exec($sql);
    }
}

function connectTestDb(PDO $rootPdo, string $database): PDO
{
    $rootPdo->exec("USE `{$database}`");
    return $rootPdo;
}
