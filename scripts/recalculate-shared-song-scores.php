#!/usr/bin/env php
<?php

/**
 * Recalculate discovery scores for all (or specific) shared songs.
 *
 * Usage:
 *   php scripts/recalculate-shared-song-scores.php
 *   php scripts/recalculate-shared-song-scores.php --id=123
 *   php scripts/recalculate-shared-song-scores.php --dry-run
 */

declare(strict_types=1);

use PanicMic\Database\Connection;
use PanicMic\Services\CatalogScoringService;
use PanicMic\Support\Env;

require dirname(__DIR__) . '/src/autoload.php';
Env::load(dirname(__DIR__) . '/.env');

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$opts   = parseOpts($argv ?? []);
$songId = isset($opts['id']) ? (int)$opts['id'] : null;
$dryRun = isset($opts['dry-run']);

$root         = dirname(__DIR__);
$configPath   = $root . '/config/catalog-scoring.php';
$scoringConfig = is_readable($configPath) ? (require $configPath) : [];

$superDb = Connection::super();

$started = microtime(true);

if ($dryRun) {
    echo "[DRY-RUN] Score recalculation — no DB changes.\n";
    $total = (int)$superDb->query('SELECT COUNT(*) FROM shared_songs WHERE is_active = 1')->fetchColumn();
    echo "Would recalculate scores for {$total} songs.\n";
    exit(0);
}

if ($songId !== null) {
    echo "Recalculating scores for song id={$songId}...\n";
    CatalogScoringService::recalculateSong($superDb, $songId, $scoringConfig);
    echo "Done.\n";
} else {
    echo "Recalculating scores for all active shared songs...\n";
    $count = CatalogScoringService::recalculateAll($superDb, $scoringConfig);
    $elapsed = microtime(true) - $started;
    printf("Done in %.1fs — updated %d songs.\n", $elapsed, $count);
}

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
