<?php

declare(strict_types=1);

use NextUp\Database\Connection;
use NextUp\Support\Env;

require dirname(__DIR__) . '/src/autoload.php';

Env::load(dirname(__DIR__) . '/.env');

$target = $argv[1] ?? '';
if (!in_array($target, ['super', 'tenant'], true)) {
    fwrite(STDERR, "Usage: php scripts/migrate.php super|tenant [tenant_database]\n");
    exit(1);
}

if ($target === 'super') {
    $db = Connection::super();
    $dir = dirname(__DIR__) . '/migrations/super';
} else {
    $database = $argv[2] ?? null;
    if (!$database) {
        fwrite(STDERR, "Tenant database name is required.\n");
        exit(1);
    }
    $db = Connection::tenant($database);
    $dir = dirname(__DIR__) . '/migrations/tenant';
}

foreach (glob($dir . '/*.sql') ?: [] as $file) {
    echo "Running {$file}\n";
    $sql = file_get_contents($file);
    if ($sql === false) {
        throw new RuntimeException("Unable to read {$file}");
    }
    $db->exec($sql);
}

echo "Done.\n";
