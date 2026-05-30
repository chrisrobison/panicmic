<?php

declare(strict_types=1);

namespace PanicMic\Database;

use PanicMic\Support\Env;
use PDO;

final class Connection
{
    private static ?PDO $super = null;
    /** @var array<string,PDO> */
    private static array $tenants = [];
    /** @var array<string,PDO> */
    private static array $provisioner = [];

    public static function super(): PDO
    {
        if (!self::$super) {
            self::$super = self::make(Env::get('SUPER_DB_NAME', 'panicmic_super') ?? 'panicmic_super', 'SUPER_DB');
        }
        return self::$super;
    }

    public static function tenant(string $database): PDO
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $database)) {
            throw new \RuntimeException('Invalid tenant database name');
        }
        return self::$tenants[$database] ??= self::make($database, 'TENANT_DB');
    }

    /**
     * Open a connection using the elevated provisioning credentials
     * (PROVISION_DB_*) — needed by setup scripts and the tenant
     * provisioning code path because they execute DDL (CREATE DATABASE,
     * CREATE TABLE, ALTER TABLE) that the runtime user lacks.
     *
     * Falls back to SUPER_DB_* if PROVISION_DB_USER is unset, so dev
     * installs that haven't split credentials still work.
     *
     * Caches per-database so repeated calls within one process reuse
     * the same PDO. The cache is intentionally separate from
     * Connection::$tenants — both must be allowed to exist at the same
     * time with different credentials against the same schema.
     */
    public static function provisioner(string $database): PDO
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $database)) {
            throw new \RuntimeException('Invalid database name');
        }
        $prefix = self::provisionPrefix();
        return self::$provisioner[$database] ??= self::make($database, $prefix);
    }

    /**
     * Open a PDO with the provisioning credentials but without selecting
     * any database. Use this when you need to `CREATE DATABASE foo`
     * without first having a database to connect to (i.e. the very first
     * bootstrap or when provisioning a fresh tenant schema).
     */
    public static function provisionerServer(): PDO
    {
        $prefix = self::provisionPrefix();
        $host = Env::get("{$prefix}_HOST", '127.0.0.1');
        $port = Env::get("{$prefix}_PORT", '3306');
        $user = Env::get("{$prefix}_USER", 'root');
        $password = Env::get("{$prefix}_PASSWORD", '');
        $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
        return new PDO($dsn, $user ?? '', $password ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    private static function provisionPrefix(): string
    {
        $user = (string)(Env::get('PROVISION_DB_USER', '') ?? '');
        return $user !== '' ? 'PROVISION_DB' : 'SUPER_DB';
    }

    private static function make(string $database, string $prefix): PDO
    {
        $host = Env::get("{$prefix}_HOST", '127.0.0.1');
        $port = Env::get("{$prefix}_PORT", '3306');
        $user = Env::get("{$prefix}_USER", 'root');
        $password = Env::get("{$prefix}_PASSWORD", '');
        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
        return new PDO($dsn, $user ?? '', $password ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
