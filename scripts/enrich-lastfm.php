<?php

declare(strict_types=1);

use PanicMic\Database\Connection;
use PanicMic\Services\LastfmService;
use PanicMic\Services\SharedCatalogService;
use PanicMic\Support\Env;

require dirname(__DIR__) . '/src/autoload.php';
Env::load(dirname(__DIR__) . '/.env');

// Backfill Last.fm enrichment (album art + metadata) onto shared_songs.
//
// Usage:
//   php scripts/enrich-lastfm.php [--limit=N] [--reenrich]
//
//   --limit=N    Stop after N rows (default: all unenriched).
//   --reenrich   Re-process rows already enriched (default: skip them).

if (!LastfmService::isEnabled()) {
    fwrite(STDERR, "LASTFM_API_KEY is not set in .env — nothing to do.\n");
    exit(1);
}

$limit = null;
$reenrich = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(1, (int)substr($arg, 8));
    } elseif ($arg === '--reenrich') {
        $reenrich = true;
    }
}

$superDb = Connection::super();
$where = $reenrich ? 'is_active = 1' : 'is_active = 1 AND lastfm_enriched_at IS NULL';
$sql = "SELECT id, title, artist FROM shared_songs WHERE {$where} ORDER BY id ASC";
if ($limit !== null) {
    $sql .= ' LIMIT ' . $limit;
}

$rows = $superDb->query($sql)->fetchAll(PDO::FETCH_ASSOC);
$total = count($rows);
if ($total === 0) {
    echo "No rows to enrich.\n";
    exit(0);
}
printf("Enriching %d shared song(s) from Last.fm...\n", $total);

$processed = 0;
$withArt = 0;
$started = microtime(true);

foreach ($rows as $row) {
    $id = (int)$row['id'];
    $info = LastfmService::trackInfo((string)$row['artist'], (string)$row['title']);
    // Always stamp lastfm_enriched_at (pass [] on a miss) so the row is
    // not retried on the next run.
    SharedCatalogService::applyLastfm($superDb, $id, $info ?? []);
    if (!empty($info['album_art_url'])) {
        $withArt++;
    }
    $processed++;

    if ($processed % 25 === 0 || $processed === $total) {
        printf("  %d/%d processed (%d with cover art)\n", $processed, $total, $withArt);
    }

    // Throttle to respect Last.fm's ~5 req/s guidance.
    usleep(250000);
}

$elapsed = microtime(true) - $started;
printf("Done in %.1fs. Processed %d, %d got cover art.\n", $elapsed, $processed, $withArt);
