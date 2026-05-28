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
    // Tests do DROP DATABASE / CREATE DATABASE for the test schemas,
    // which needs DDL privileges. Use PROVISION_DB_* when set, otherwise
    // fall back to SUPER_DB_* (the old dev pattern where root was used
    // for everything). The test schemas (nextup_test_*) match the
    // nextup_% pattern, so the provisioning user can drop/create them.
    $dbaUser = (string)(Env::get('PROVISION_DB_USER', '') ?? '');
    $prefix = $dbaUser !== '' ? 'PROVISION_DB' : 'SUPER_DB';
    $rootDsn = sprintf(
        'mysql:host=%s;port=%s;charset=utf8mb4',
        Env::get("{$prefix}_HOST", '127.0.0.1'),
        Env::get("{$prefix}_PORT", '3306'),
    );
    $rootPdo = new PDO(
        $rootDsn,
        Env::get("{$prefix}_USER", 'root') ?? 'root',
        Env::get("{$prefix}_PASSWORD", '') ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
} catch (Throwable $e) {
    fwrite(STDERR, "WARNING: MySQL unreachable, DB-backed tests will be skipped: " . $e->getMessage() . "\n");
    define('NEXTUP_TEST_DB_UNAVAILABLE', $e->getMessage());
    return;
}

// Drop and recreate test schemas so non-idempotent migrations (e.g. raw
// CREATE INDEX) re-apply cleanly on every test run. The test schemas are
// namespaced (nextup_test_*) so this only nukes test data.
$rootPdo->exec("DROP DATABASE IF EXISTS `" . TEST_SUPER_DB . "`");
$rootPdo->exec("DROP DATABASE IF EXISTS `" . TEST_TENANT_DB . "`");
$rootPdo->exec("CREATE DATABASE `" . TEST_SUPER_DB . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$rootPdo->exec("CREATE DATABASE `" . TEST_TENANT_DB . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

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
