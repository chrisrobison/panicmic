<?php

declare(strict_types=1);

namespace NextUp\Database;

use NextUp\Support\Env;
use PDO;

final class Connection
{
    private static ?PDO $super = null;
    /** @var array<string,PDO> */
    private static array $tenants = [];

    public static function super(): PDO
    {
        if (!self::$super) {
            self::$super = self::make(Env::get('SUPER_DB_NAME', 'nextup_super') ?? 'nextup_super', 'SUPER_DB');
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
