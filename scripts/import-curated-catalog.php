#!/usr/bin/env php
<?php

/**
 * Import curated songs into the shared catalog.
 *
 * Usage:
 *   php scripts/import-curated-catalog.php --source=manual-csv [--file=data/catalog/manual/karaoke-staples.csv]
 *   php scripts/import-curated-catalog.php --source=manual-csv --config=config/catalog-sources/manual.php
 *   php scripts/import-curated-catalog.php --source=manual-json --file=data/catalog/manual/songs.json
 *   php scripts/import-curated-catalog.php --source=rocklists --config=config/catalog-sources/rocklists.php
 *   php scripts/import-curated-catalog.php --source=lastfm --tag=alternative --limit=500
 *   php scripts/import-curated-catalog.php --source=lastfm --config=config/catalog-sources/lastfm-tags.php
 *
 * Options:
 *   --source=NAME        Adapter name: manual-csv | manual-json | rocklists | lastfm
 *   --file=PATH          File path for manual-csv or manual-json (relative to app root)
 *   --config=PATH        Config file of source list definitions
 *   --tag=NAME           Last.fm tag (for --source=lastfm)
 *   --list-slug=SLUG     Import only this list slug (from config)
 *   --limit=N            Max candidates to import
 *   --dry-run            Print what would be imported, make no changes
 *   --force-fetch        Re-fetch remote URLs even if cached
 *   --verbose            Extra output
 */

declare(strict_types=1);

use PanicMic\Database\Connection;
use PanicMic\Services\CatalogImport\LastfmTopTracksAdapter;
use PanicMic\Services\CatalogImport\ManualCsvAdapter;
use PanicMic\Services\CatalogImport\ManualJsonAdapter;
use PanicMic\Services\CatalogImport\RocklistsHtmlAdapter;
use PanicMic\Services\CatalogImportService;
use PanicMic\Services\CatalogReportService;
use PanicMic\Services\CatalogSourceService;
use PanicMic\Support\Env;

require dirname(__DIR__) . '/src/autoload.php';
Env::load(dirname(__DIR__) . '/.env');

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$opts = parseCliArgs($argv ?? []);

$source     = $opts['source']     ?? null;
$file       = $opts['file']       ?? null;
$configFile = $opts['config']     ?? null;
$tag        = $opts['tag']        ?? null;
$listSlug   = $opts['list-slug']  ?? null;
$limit      = isset($opts['limit']) ? (int)$opts['limit'] : null;
$dryRun     = isset($opts['dry-run']);
$forceFetch = isset($opts['force-fetch']);
$verbose    = isset($opts['verbose']);

if (!$source) {
    fwrite(STDERR, "Error: --source is required.\n\n");
    printUsage();
    exit(1);
}

$root     = dirname(__DIR__);
$cacheDir = $root . '/storage/catalog-cache';
$superDb  = Connection::super();

// Build the adapter
$adapter = buildAdapter($source, $file, $configFile, $tag, $root, $cacheDir, $forceFetch, $dryRun);

if ($adapter === null) {
    fwrite(STDERR, "Error: unknown --source={$source}\n");
    printUsage();
    exit(1);
}

$importer = new CatalogImportService($superDb, $root, $dryRun, $verbose || $dryRun);

printf(
    "%s Importing from source '%s'%s%s\n",
    $dryRun ? '[DRY-RUN]' : '',
    $source,
    $listSlug ? " list={$listSlug}" : '',
    $limit    ? " limit={$limit}" : '',
);

$started = microtime(true);

try {
    $options = array_filter([
        'limit'     => $limit,
        'list_slug' => $listSlug,
    ], static fn (mixed $v) => $v !== null);

    $stats = $importer->run($adapter, $options);
} catch (\Throwable $e) {
    fwrite(STDERR, "Import failed: " . $e->getMessage() . "\n");
    exit(2);
}

$elapsed = microtime(true) - $started;

printf(
    "Done in %.1fs — seen=%d imported=%d created=%d matched=%d skipped=%d warnings=%d\n",
    $elapsed,
    $stats['seen']    ?? 0,
    $stats['imported'] ?? 0,
    $stats['created']  ?? 0,
    $stats['matched']  ?? 0,
    $stats['skipped']  ?? 0,
    count($stats['warnings'] ?? []),
);

if (!$dryRun && ($runId = (int)($stats['run_id'] ?? 0)) > 0) {
    $runRow = CatalogSourceService::getRun($superDb, $runId);
    if ($runRow) {
        $reporter = new CatalogReportService($root . '/storage/catalog-reports');
        $paths = $reporter->generateImportReport($superDb, $runRow);
        printf("Report: %s\n", $paths['html_path']);

        // Update run with report path
        $superDb->prepare(
            'UPDATE shared_song_import_runs SET report_path = ? WHERE id = ?'
        )->execute([$paths['html_path'], $runId]);
    }
}

/* ------------------------------------------------------------------ */

function buildAdapter(
    string $source,
    ?string $file,
    ?string $configFile,
    ?string $tag,
    string $root,
    string $cacheDir,
    bool $forceFetch,
    bool $dryRun,
): ?\PanicMic\Services\CatalogImport\SourceAdapter {
    switch ($source) {
        case 'manual-csv':
            $lists = loadSourceConfig($configFile, $file, $root, 'manual-csv', [
                'source_name' => 'KJ Manual Curation',
                'source_slug' => 'manual-kj',
                'source_type' => 'manual',
                'list_title'  => basename((string)$file, '.csv'),
                'list_slug'   => CatalogNormalizeService($file ?? 'manual'),
                'list_type'   => 'manual',
            ]);
            return new ManualCsvAdapter($lists, $root);

        case 'manual-json':
            $lists = loadSourceConfig($configFile, $file, $root, 'manual-json', [
                'source_name' => 'KJ Manual Curation',
                'source_slug' => 'manual-kj',
                'source_type' => 'manual',
                'list_title'  => basename((string)$file, '.json'),
                'list_slug'   => slugify((string)($file ?? 'manual')),
                'list_type'   => 'manual',
            ]);
            return new ManualJsonAdapter($lists, $root);

        case 'rocklists':
            $cfgPath = $configFile ?? ($root . '/config/catalog-sources/rocklists.php');
            if (!is_readable($cfgPath)) {
                fwrite(STDERR, "Error: config file not found: {$cfgPath}\n");
                exit(1);
            }
            $lists = require $cfgPath;
            return new RocklistsHtmlAdapter($lists, $cacheDir, $forceFetch);

        case 'lastfm':
            if ($configFile !== null && is_readable($root . '/' . ltrim($configFile, '/'))) {
                $lists = require $root . '/' . ltrim($configFile, '/');
            } elseif ($configFile !== null && is_readable($configFile)) {
                $lists = require $configFile;
            } elseif ($tag !== null) {
                $lists = [[
                    'tag'         => $tag,
                    'source_name' => 'Last.fm',
                    'source_slug' => 'lastfm',
                    'source_type' => 'api',
                    'list_title'  => "Last.fm Top Tracks: {$tag}",
                    'list_slug'   => 'lastfm-' . slugify($tag),
                    'list_type'   => 'genre',
                    'genre_hint'  => ucfirst($tag),
                    'limit'       => 500,
                ]];
            } else {
                $cfgPath = $root . '/config/catalog-sources/lastfm-tags.php';
                if (!is_readable($cfgPath)) {
                    fwrite(STDERR, "Error: no --tag and no config at {$cfgPath}\n");
                    exit(1);
                }
                $lists = require $cfgPath;
            }
            return new LastfmTopTracksAdapter($lists, $cacheDir, $forceFetch);

        default:
            return null;
    }
}

/**
 * @param array<string,mixed> $defaultList
 * @return list<array<string,mixed>>
 */
function loadSourceConfig(
    ?string $configFile,
    ?string $file,
    string $root,
    string $type,
    array $defaultList,
): array {
    if ($configFile !== null) {
        $path = str_starts_with($configFile, '/') ? $configFile : $root . '/' . $configFile;
        if (!is_readable($path)) {
            fwrite(STDERR, "Error: config file not found: {$path}\n");
            exit(1);
        }
        $lists = require $path;
        return is_array($lists) ? $lists : [$lists];
    }
    if ($file !== null) {
        $resolved = str_starts_with($file, '/') ? $file : $root . '/' . $file;
        return [array_merge($defaultList, ['file' => $resolved])];
    }
    // Load default manual config
    $defaultCfg = $root . '/config/catalog-sources/manual.php';
    if (is_readable($defaultCfg)) {
        $lists = require $defaultCfg;
        return is_array($lists) ? $lists : [];
    }
    return [];
}

function slugify(string $text): string
{
    $s = strtolower($text);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? $s;
    return trim($s, '-');
}

function CatalogNormalizeService(string $path): string
{
    return slugify(basename($path, '.csv'));
}

/**
 * @param list<string> $argv
 * @return array<string,string|true>
 */
function parseCliArgs(array $argv): array
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

function printUsage(): void
{
    echo <<<TXT
Usage:
  php scripts/import-curated-catalog.php --source=manual-csv [--file=PATH]
  php scripts/import-curated-catalog.php --source=manual-csv --config=config/catalog-sources/manual.php
  php scripts/import-curated-catalog.php --source=manual-json --file=data/catalog/manual/songs.json
  php scripts/import-curated-catalog.php --source=rocklists [--config=PATH]
  php scripts/import-curated-catalog.php --source=lastfm --tag=alternative [--limit=500]
  php scripts/import-curated-catalog.php --source=lastfm --config=config/catalog-sources/lastfm-tags.php

Options:
  --source=NAME        Adapter: manual-csv | manual-json | rocklists | lastfm
  --file=PATH          File path (relative to app root)
  --config=PATH        Config file
  --tag=NAME           Last.fm tag (for --source=lastfm)
  --list-slug=SLUG     Import only this list slug
  --limit=N            Max candidates
  --dry-run            Show what would be imported, no DB changes
  --force-fetch        Re-fetch remote URLs even if cached
  --verbose            Extra output

TXT;
}
