<?php

declare(strict_types=1);

use NextUp\Database\Connection;
use NextUp\Support\Env;

require dirname(__DIR__) . '/src/autoload.php';

Env::load(dirname(__DIR__) . '/.env');

/**
 * Migration runner with a schema_migrations ledger.
 *
 * Usage:
 *   php scripts/migrate.php super [--dry-run]
 *   php scripts/migrate.php tenant <database> [--dry-run]
 *   php scripts/migrate.php tenants [--dry-run]
 *   php scripts/migrate.php status (super | tenant <database> | tenants)
 *
 * First run against a non-empty schema (no schema_migrations table yet) is
 * a "bootstrap": every file present on disk is marked applied without being
 * executed, on the assumption that all current migrations are idempotent and
 * have already been run by the legacy runner. Subsequent runs only apply
 * files not yet in the ledger, in sorted order.
 */

const MIGRATIONS_TABLE_DDL = <<<'SQL'
CREATE TABLE IF NOT EXISTS schema_migrations (
  filename VARCHAR(255) NOT NULL PRIMARY KEY,
  checksum CHAR(64) NOT NULL,
  applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "scripts/migrate.php must be run from the command line.\n");
    exit(1);
}

/** @var list<string> $cliArgs */
$cliArgs = $_SERVER['argv'] ?? [];

[$command, $argTenant, $flags] = parseArgs($cliArgs);
$dryRun = in_array('--dry-run', $flags, true);

try {
    match ($command) {
        'super'   => runScope('super', $dryRun),
        'tenant'  => runScope('tenant', $dryRun, requireTenantArg($argTenant)),
        'tenants' => runAllTenants($dryRun),
        'status'  => statusReport($argTenant, $cliArgs),
        default   => usage(1),
    };
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(2);
}

/* ------------------------------------------------------------------ */

/**
 * @param list<string> $argv
 * @return array{0:string,1:?string,2:list<string>}
 */
function parseArgs(array $argv): array
{
    $positional = [];
    $flags = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--')) {
            $flags[] = $arg;
        } else {
            $positional[] = $arg;
        }
    }
    $command = $positional[0] ?? '';
    $tenant = $positional[1] ?? null;
    return [$command, $tenant, $flags];
}

function usage(int $code = 0): never
{
    fwrite($code === 0 ? STDOUT : STDERR, <<<TXT
Usage:
  php scripts/migrate.php super [--dry-run]
  php scripts/migrate.php tenant <database> [--dry-run]
  php scripts/migrate.php tenants [--dry-run]
  php scripts/migrate.php status super
  php scripts/migrate.php status tenant <database>
  php scripts/migrate.php status tenants

TXT);
    exit($code);
}

function requireTenantArg(?string $name): string
{
    if (!$name) {
        fwrite(STDERR, "Tenant database name is required.\n");
        usage(1);
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new RuntimeException("Invalid tenant database name: {$name}");
    }
    return $name;
}

function migrationsDir(string $scope): string
{
    return dirname(__DIR__) . '/migrations/' . $scope;
}

/** @return list<string> absolute paths, sorted */
function listMigrationFiles(string $scope): array
{
    $files = glob(migrationsDir($scope) . '/*.sql') ?: [];
    sort($files, SORT_STRING);
    return $files;
}

function dbForScope(string $scope, ?string $tenantDatabase = null): PDO
{
    // Migrations execute DDL, so always use the elevated provisioning
    // credentials (PROVISION_DB_* — falls back to SUPER_DB_* in
    // Connection::provisioner() if unset).
    if ($scope === 'super') {
        $name = (string)(Env::get('SUPER_DB_NAME', 'nextup_super') ?? 'nextup_super');
        ensureDatabaseExists($name);
        return Connection::provisioner($name);
    }
    $database = $tenantDatabase ?? throw new RuntimeException('tenant database required');
    ensureDatabaseExists($database);
    return Connection::provisioner($database);
}

/**
 * Make sure $database exists before we try to connect to it. The
 * provisioning user is granted CREATE on the nextup_% pattern, so
 * idempotently creating the super or a tenant schema is safe.
 */
function ensureDatabaseExists(string $database): void
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $database)) {
        throw new RuntimeException("Invalid database name: {$database}");
    }
    $server = Connection::provisionerServer();
    $server->exec("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}

function ensureLedger(PDO $db): void
{
    $db->exec(MIGRATIONS_TABLE_DDL);
}

/** Returns true if the database contains tables other than schema_migrations. */
function databaseHasUserTables(PDO $db): bool
{
    $rows = $db->query(
        "SELECT table_name FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name <> 'schema_migrations'
         LIMIT 1"
    )->fetchAll();
    return $rows !== [];
}

/** @return array<string,string> filename => checksum */
function loadApplied(PDO $db): array
{
    $rows = $db->query('SELECT filename, checksum FROM schema_migrations')->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[(string)$r['filename']] = (string)$r['checksum'];
    }
    return $out;
}

function runScope(string $scope, bool $dryRun, ?string $tenantDatabase = null): void
{
    $label = $scope === 'super' ? 'super' : "tenant ({$tenantDatabase})";
    echo "▸ Migrating {$label}" . ($dryRun ? ' [dry-run]' : '') . "\n";

    $db = dbForScope($scope, $tenantDatabase);

    // Distinguish a truly-empty database (apply all migrations) from a
    // legacy-populated one (only schema_migrations is missing — mark as
    // applied without executing). Check BEFORE creating the ledger so
    // schema_migrations itself doesn't skew the count.
    $isLegacyPopulated = databaseHasUserTables($db);
    ensureLedger($db);
    $applied = loadApplied($db);
    $files = listMigrationFiles($scope);

    if ($applied === [] && $files !== [] && $isLegacyPopulated) {
        bootstrap($db, $files, $dryRun);
        return;
    }

    $pending = [];
    foreach ($files as $path) {
        $name = basename($path);
        if (!isset($applied[$name])) {
            $pending[] = $path;
            continue;
        }
        $expected = hash_file('sha256', $path);
        if ($applied[$name] !== $expected) {
            fwrite(STDERR, "  ⚠ {$name} content has changed since it was applied (checksum mismatch). Investigate.\n");
        }
    }

    if ($pending === []) {
        echo "  ✓ Up to date (" . count($applied) . " applied).\n";
        return;
    }

    foreach ($pending as $path) {
        applyOne($db, $path, $dryRun);
    }
    $verb = $dryRun ? 'Would apply' : 'Applied';
    echo "  ✓ {$verb} " . count($pending) . " migration(s).\n";
}

/** @param list<string> $files */
function bootstrap(PDO $db, array $files, bool $dryRun): void
{
    echo "  · schema_migrations is empty — bootstrapping " . count($files) . " existing file(s) as applied without executing.\n";
    if ($dryRun) {
        foreach ($files as $path) {
            echo "    would mark: " . basename($path) . "\n";
        }
        return;
    }
    $stmt = $db->prepare('INSERT INTO schema_migrations (filename, checksum) VALUES (?, ?)');
    foreach ($files as $path) {
        $stmt->execute([basename($path), hash_file('sha256', $path)]);
    }
    echo "  ✓ Bootstrap complete.\n";
}

function applyOne(PDO $db, string $path, bool $dryRun): void
{
    $name = basename($path);
    if ($dryRun) {
        echo "    would apply: {$name}\n";
        return;
    }
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException("Unable to read {$path}");
    }
    echo "    applying: {$name} ... ";
    $db->exec($sql);
    $stmt = $db->prepare('INSERT INTO schema_migrations (filename, checksum) VALUES (?, ?)');
    $stmt->execute([$name, hash_file('sha256', $path)]);
    echo "ok\n";
}

function runAllTenants(bool $dryRun): void
{
    // Use provisioner here too — the migrate script should work even
    // before the runtime app user (panicmic) has been provisioned.
    $superName = (string)(Env::get('SUPER_DB_NAME', 'nextup_super') ?? 'nextup_super');
    $super = Connection::provisioner($superName);
    $tenants = $super->query('SELECT slug, database_name FROM tenants ORDER BY slug')->fetchAll();
    if (!$tenants) {
        echo "No tenants registered in nextup_super.tenants.\n";
        return;
    }
    foreach ($tenants as $t) {
        $db = $t['database_name'];
        if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$db)) {
            fwrite(STDERR, "  ⚠ skipping {$t['slug']} — invalid database_name: {$db}\n");
            continue;
        }
        runScope('tenant', $dryRun, (string)$db);
    }
}

/** @param list<string> $cliArgs */
function statusReport(?string $arg, array $cliArgs): void
{
    $target = $arg ?? 'super';
    if ($target === 'super') {
        printStatusForScope('super');
        return;
    }
    if ($target === 'tenants') {
        $superName = (string)(Env::get('SUPER_DB_NAME', 'nextup_super') ?? 'nextup_super');
        $tenants = Connection::provisioner($superName)->query('SELECT slug, database_name FROM tenants ORDER BY slug')->fetchAll();
        foreach ($tenants as $t) {
            printStatusForScope('tenant', (string)$t['database_name']);
        }
        return;
    }
    if ($target === 'tenant') {
        // For `status tenant <db>`, the db name is the third positional
        // (after `status` and `tenant`).
        $database = $cliArgs[3] ?? null;
        printStatusForScope('tenant', requireTenantArg($database));
        return;
    }
    usage(1);
}

function printStatusForScope(string $scope, ?string $tenantDatabase = null): void
{
    $label = $scope === 'super' ? 'super' : "tenant ({$tenantDatabase})";
    echo "▸ Status: {$label}\n";
    $db = dbForScope($scope, $tenantDatabase);
    ensureLedger($db);
    $applied = loadApplied($db);
    $files = listMigrationFiles($scope);
    foreach ($files as $path) {
        $name = basename($path);
        $mark = isset($applied[$name]) ? '✓' : '·';
        echo "  {$mark} {$name}\n";
    }
    $missingOnDisk = array_diff(array_keys($applied), array_map('basename', $files));
    foreach ($missingOnDisk as $name) {
        echo "  ! {$name} (applied but not on disk)\n";
    }
}
