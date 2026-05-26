<?php

declare(strict_types=1);

use NextUp\Database\Connection;
use NextUp\Services\SharedCatalogService;
use NextUp\Support\Env;

require dirname(__DIR__) . '/src/autoload.php';
Env::load(dirname(__DIR__) . '/.env');

$file = $argv[1] ?? null;
if (!$file || !is_readable($file)) {
    fwrite(STDERR, "Usage: php scripts/import-shared-catalog.php <path-to-songs.csv>\n");
    fwrite(STDERR, "CSV must be semicolon-delimited with header row.\n");
    exit(1);
}

$delimiter = ';';
$superDb = Connection::super();
$handle = fopen($file, 'r');
if ($handle === false) {
    fwrite(STDERR, "Failed to open {$file}\n");
    exit(1);
}

$rawHeader = fgetcsv($handle, 0, $delimiter, '"', '\\');
if (!$rawHeader) {
    fwrite(STDERR, "Empty CSV\n");
    exit(1);
}
$header = array_map(static fn ($h) => strtolower(trim(str_replace([' ', '-'], '_', (string)$h))), $rawHeader);

$batch = [];
$batchSize = 1000;
$imported = 0;
$skipped = 0;
$total = 0;
$started = microtime(true);

while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
    if (count($row) !== count($header)) {
        $skipped++;
        continue;
    }
    $batch[] = array_combine($header, $row);
    $total++;
    if (count($batch) >= $batchSize) {
        $result = SharedCatalogService::bulkImport($superDb, $batch);
        $imported += $result['imported'];
        $skipped += $result['skipped'];
        $batch = [];
        printf("Processed %d rows (imported %d, skipped %d)\n", $total, $imported, $skipped);
    }
}
if ($batch) {
    $result = SharedCatalogService::bulkImport($superDb, $batch);
    $imported += $result['imported'];
    $skipped += $result['skipped'];
}
fclose($handle);

$elapsed = microtime(true) - $started;
printf("Done in %.1fs. Imported %d, skipped %d, total rows %d.\n", $elapsed, $imported, $skipped, $total);
