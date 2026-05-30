<?php

declare(strict_types=1);

namespace PanicMic\Services;

use PanicMic\Database\Connection;

/**
 * Idempotent tenant database provisioner. Pulled out of SuperController
 * so the signup flow (PLAN.md Phase 7) can call it directly to take a
 * tenant from status='provisioning' → ready without operator
 * intervention, while super-admin retries continue to work the same way.
 *
 * CREATE DATABASE and the tenant-schema migrations both execute DDL the
 * runtime app user deliberately lacks, so all SQL here runs through
 * Connection::provisioner() / provisionerServer() (Phase 8). On dev
 * installs without PROVISION_DB_* set, those fall back to SUPER_DB_*.
 */
final class TenantProvisioner
{
    /**
     * Create the tenant database (CREATE DATABASE IF NOT EXISTS), apply
     * every migrations/tenant/*.sql file in sorted order, and ensure the
     * content directory exists.
     *
     * @param array<string,mixed> $tenant Row from the super `tenants`
     *                                    table; must include `slug` and
     *                                    `database_name`.
     */
    public static function provision(array $tenant): void
    {
        $root = dirname(__DIR__, 2);
        $dbName = (string)($tenant['database_name'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName)) {
            throw new \RuntimeException('Invalid database name');
        }
        // provisionerServer() doesn't select a database, so we can issue
        // CREATE DATABASE without first needing the target schema to exist.
        Connection::provisionerServer()->exec(
            "CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );

        $tenantDb = Connection::provisioner($dbName);
        $files = glob($root . '/migrations/tenant/*.sql') ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            $sql = file_get_contents($file);
            if ($sql === false || $sql === '') {
                continue;
            }
            $tenantDb->exec($sql);
        }

        try {
            ContentService::ensureTenantDirectory((string)($tenant['slug'] ?? ''));
        } catch (\Throwable $error) {
            throw new \RuntimeException(
                'Tenant database was created, but the content folder could not be created. '
                . 'Make /content writable by PHP-FPM/Apache and retry. Details: '
                . $error->getMessage(),
                0,
                $error,
            );
        }
    }
}
