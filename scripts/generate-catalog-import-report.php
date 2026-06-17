#!/usr/bin/env php
<?php

/**
 * Generate or list catalog import reports.
 *
 * Usage:
 *   php scripts/generate-catalog-import-report.php --latest
 *   php scripts/generate-catalog-import-report.php --run-id=42
 *   php scripts/generate-catalog-import-report.php --list
 */

declare(strict_types=1);

use PanicMic\Database\Connection;
use PanicMic\Services\CatalogReportService;
use PanicMic\Services\CatalogSourceService;
use PanicMic\Support\Env;

require dirname(__DIR__) . '/src/autoload.php';
Env::load(dirname(__DIR__) . '/.env');

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$opts    = parseOpts($argv ?? []);
$root    = dirname(__DIR__);
$reports = new CatalogReportService($root . '/storage/catalog-reports');
$superDb = Connection::super();

if (isset($opts['list'])) {
    $files = $reports->listReports();
    if (!$files) {
        echo "No reports found in storage/catalog-reports/\n";
    } else {
        foreach ($files as $f) {
            printf("  %s  (%s KB)  %s\n",
                date('Y-m-d H:i', $f['mtime']),
                number_format($f['size'] / 1024, 1),
                $f['name'],
            );
        }
    }
    exit(0);
}

$runId = null;
if (isset($opts['run-id'])) {
    $runId = (int)$opts['run-id'];
} elseif (isset($opts['latest'])) {
    $runs = CatalogSourceService::recentRuns($superDb, 1);
    $runId = !empty($runs[0]['id']) ? (int)$runs[0]['id'] : null;
}

if ($runId === null) {
    fwrite(STDERR, "Error: --run-id=N or --latest required, or use --list.\n");
    exit(1);
}

$runRow = CatalogSourceService::getRun($superDb, $runId);
if (!$runRow) {
    fwrite(STDERR, "No import run found with id={$runId}\n");
    exit(1);
}

$paths = $reports->generateImportReport($superDb, $runRow);

// Update run with report path
$superDb->prepare(
    'UPDATE shared_song_import_runs SET report_path = ? WHERE id = ?'
)->execute([$paths['html_path'], $runId]);

echo "Report generated:\n";
echo "  JSON: {$paths['json_path']}\n";
echo "  HTML: {$paths['html_path']}\n";

/**
 * @param list<string> $argv
 * @return array<string,string|true>
 */
function parseOpts(array $argv): array
{
    $opts = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--')) {
            $part = substr($arg, 2);
            if (str_contains($part, '=')) {
                [$k, $v] = explode('=', $part, 2);
                $opts[$k] = $v;
            } else {
                $opts[$part] = true;
            }
        }
    }
    return $opts;
}
