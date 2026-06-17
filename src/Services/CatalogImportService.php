<?php

declare(strict_types=1);

namespace PanicMic\Services;

use PDO;
use PanicMic\Services\CatalogImport\SourceAdapter;

/**
 * Orchestrates the curated catalog import pipeline.
 *
 * For each candidate row produced by a SourceAdapter:
 *  1. Normalize artist + title
 *  2. Look for an existing shared_songs row
 *  3. Create or match the shared song
 *  4. Persist the candidate
 *  5. Persist source/list/link metadata
 *  6. Apply default tags and rule-based tags
 *  7. Recalculate scores for the matched/created song
 */
final class CatalogImportService
{
    public function __construct(
        private readonly PDO $db,
        private readonly string $rootPath,
        private readonly bool $dryRun = false,
        private readonly bool $verbose = false,
    ) {}

    /**
     * Run an import using the given adapter.
     *
     * @param array<string,mixed> $options  e.g. ['limit' => 100, 'list_slug' => 'live105-1991']
     * @return array<string,mixed>  Import statistics
     */
    public function run(SourceAdapter $adapter, array $options = []): array
    {
        $stats = [
            'seen'         => 0,
            'imported'     => 0,
            'skipped'      => 0,
            'created'      => 0,
            'matched'      => 0,
            'needs_review' => 0,
            'warnings'     => [],
        ];

        $limit       = isset($options['limit']) ? max(1, (int)$options['limit']) : null;
        $filterSlug  = $options['list_slug'] ?? null;
        $runId       = $this->dryRun ? 0 : CatalogSourceService::startRun($this->db, $adapter->sourceSlug());

        $scoringConfig = $this->loadConfig('catalog-scoring.php');
        $rulesFile     = $this->rootPath . '/config/catalog-tag-rules.php';

        try {
            foreach ($adapter->lists() as $list) {
                if ($filterSlug !== null && ($list['slug'] ?? $list['list_slug'] ?? '') !== $filterSlug) {
                    continue;
                }

                $this->log("→ Processing list: " . ($list['list_title'] ?? $list['slug'] ?? '?'));

                // Fetch raw content
                try {
                    $raw = $adapter->fetch($list);
                } catch (\Throwable $e) {
                    $msg = "Failed to fetch list {$list['slug']}: " . $e->getMessage();
                    $this->warn($msg);
                    $stats['warnings'][] = ['type' => 'fetch_failed', 'message' => $msg];
                    if ($runId) {
                        CatalogSourceService::addWarning($this->db, $runId, 'fetch_failed', $msg);
                    }
                    continue;
                }

                if (trim($raw) === '') {
                    $msg = "Empty content for list: " . ($list['slug'] ?? '?');
                    $this->warn($msg);
                    if ($runId) {
                        CatalogSourceService::addWarning($this->db, $runId, 'empty_content', $msg);
                    }
                    continue;
                }

                // Parse candidates
                try {
                    $candidates = $adapter->parse($raw, $list);
                } catch (\Throwable $e) {
                    $msg = "Parse failed for list {$list['slug']}: " . $e->getMessage();
                    $this->warn($msg);
                    if ($runId) {
                        CatalogSourceService::addWarning($this->db, $runId, 'parse_failed', $msg);
                    }
                    continue;
                }

                // Ensure source + list exist in DB
                $sourceId = 0;
                $listId   = 0;
                if (!$this->dryRun) {
                    $sourceId = CatalogSourceService::upsertSource($this->db, [
                        'name'        => $list['source_name'] ?? $list['source_slug'] ?? 'Unknown',
                        'slug'        => $list['source_slug'] ?? 'unknown',
                        'source_type' => $list['source_type'] ?? 'manual',
                        'station'     => ($list['station']    ?? null) ?: null,
                        'market'      => ($list['market']     ?? null) ?: null,
                        'url'         => ($list['url']        ?? null) ?: null,
                    ]);
                    $listId = CatalogSourceService::upsertList($this->db, $sourceId, [
                        'title'      => $list['list_title'] ?? $list['title'] ?? ($list['list_slug'] ?? ''),
                        'slug'       => $list['list_slug']  ?? $list['slug'] ?? '',
                        'year'       => ($list['year']   ?? null) ?: null,
                        'decade'     => ($list['decade'] ?? null) ?: null,
                        'genre_hint' => ($list['genre_hint'] ?? null) ?: null,
                        'list_type'  => $list['list_type'] ?? 'manual',
                        'url'        => ($list['url']     ?? null) ?: null,
                    ]);
                }

                $defaultTags = is_array($list['default_tags'] ?? null) ? $list['default_tags'] : [];
                $processed   = 0;

                foreach ($candidates as $candidate) {
                    if ($limit !== null && $stats['seen'] >= $limit) {
                        break;
                    }
                    $stats['seen']++;
                    $processed++;

                    $artist = trim((string)($candidate['artist'] ?? ''));
                    $title  = trim((string)($candidate['title']  ?? ''));
                    if ($artist === '' || $title === '') {
                        $stats['skipped']++;
                        if ($runId) {
                            CatalogSourceService::addWarning(
                                $this->db, $runId, 'missing_fields',
                                "Skipped row with empty artist or title",
                                $listId, null, $candidate,
                            );
                        }
                        continue;
                    }

                    if ($this->dryRun) {
                        $this->log("  [dry-run] {$artist} — {$title}");
                        $stats['imported']++;
                        continue;
                    }

                    try {
                        $result = $this->importCandidate(
                            $candidate, $list, $sourceId, $listId, $defaultTags,
                            $scoringConfig, $rulesFile, $runId,
                        );
                        $stats['imported']++;
                        $stats[$result['action']] = ($stats[$result['action']] ?? 0) + 1;
                        $this->log("  [{$result['action']}] {$artist} — {$title}");
                    } catch (\Throwable $e) {
                        $stats['skipped']++;
                        $msg = "Error importing '{$artist} - {$title}': " . $e->getMessage();
                        $this->warn($msg);
                        CatalogSourceService::addWarning(
                            $this->db, $runId, 'import_error', $msg,
                            $listId, null, $candidate,
                        );
                    }
                }

                $this->log("  done: {$processed} candidates");
            }
        } catch (\Throwable $e) {
            $stats['error'] = $e->getMessage();
            if ($runId) {
                CatalogSourceService::finishRun($this->db, $runId, array_merge($stats, [
                    'status' => 'failed',
                    'error'  => $e->getMessage(),
                ]));
            }
            throw $e;
        }

        if ($runId) {
            CatalogSourceService::finishRun($this->db, $runId, array_merge($stats, [
                'status' => 'completed',
            ]));
        }

        return array_merge($stats, ['run_id' => $runId]);
    }

    // ------------------------------------------------------------------

    /**
     * Import a single candidate row.
     *
     * @param array<string,mixed> $candidate
     * @param array<string,mixed> $list
     * @param list<string>        $defaultTags
     * @param array<string,mixed> $scoringConfig
     * @return array{action:string,song_id:int,candidate_id:int}
     */
    private function importCandidate(
        array $candidate,
        array $list,
        int $sourceId,
        int $listId,
        array $defaultTags,
        array $scoringConfig,
        string $rulesFile,
        int $runId,
    ): array {
        $artist = trim((string)$candidate['artist']);
        $title  = trim((string)$candidate['title']);
        $normArtist = CatalogNormalizeService::normalizeArtist($artist);
        $normTitle  = CatalogNormalizeService::normalizeTitle($title);

        // 1. Try to find existing shared_songs row
        [$songId, $action] = $this->findOrCreateSharedSong($candidate, $normArtist, $normTitle);

        // 2. Persist candidate
        $candidateId = CatalogSourceService::upsertCandidate($this->db, $listId, array_merge($candidate, [
            'normalized_artist' => $normArtist,
            'normalized_title'  => $normTitle,
            'shared_song_id'    => $songId,
            'match_status'      => $action === 'needs_review' ? 'needs_review' : ($action === 'created' ? 'created' : 'matched'),
            'confidence_score'  => $action === 'matched' ? 90 : ($action === 'created' ? 80 : 50),
        ]));

        // 3. Persist source link
        CatalogSourceService::upsertSourceLink($this->db, $songId, $sourceId, $listId, [
            'candidate_id' => $candidateId,
            'rank'         => ($candidate['rank'] ?? null) ?: null,
            'year'         => ($candidate['year']  ?? $list['year']  ?? null) ?: null,
            'decade'       => ($candidate['decade'] ?? $list['decade'] ?? null) ?: null,
            'source_weight'=> (int)($list['source_weight'] ?? 5),
        ]);

        // 4. Apply default tags from list config
        if ($defaultTags !== []) {
            CatalogTaggingService::applyDefaultTags($this->db, $songId, $defaultTags, 75);
        }

        // 5. Apply tags from the candidate row itself (manual CSV "Tags" column)
        $importTags = $candidate['import_tags'] ?? null;
        if (is_array($importTags) && $importTags !== []) {
            CatalogTaggingService::applyTagSlugs($this->db, $songId, array_map(
                [CatalogNormalizeService::class, 'slug'],
                $importTags,
            ), 85, 'import');
        }

        // 6. Apply era tag
        $decade = ($candidate['decade'] ?? $list['decade'] ?? null) ?: null;
        if ($decade !== null) {
            CatalogTaggingService::applyEraTags($this->db, $songId, (int)$decade);
        }

        // 7. Get source count for rule evaluation
        $sourceCount = CatalogSourceService::sourceListCount($this->db, $songId);

        // 8. Apply tag rules
        $song = $this->db->prepare('SELECT * FROM shared_songs WHERE id = ? LIMIT 1');
        $song->execute([$songId]);
        $songRow = $song->fetch() ?: [];
        $songRow['source_count'] = $sourceCount;

        CatalogTaggingService::applyRules($this->db, $songRow, array_merge($candidate, [
            'source_slug' => $list['source_slug'] ?? '',
            'station'     => $list['station'] ?? null,
            'market'      => $list['market']  ?? null,
        ]), $rulesFile);

        // 9. Recalculate scores
        CatalogScoringService::recalculateSong($this->db, $songId, $scoringConfig);

        return ['action' => $action, 'song_id' => $songId, 'candidate_id' => $candidateId];
    }

    /**
     * Find or create a shared_songs row for the given normalized artist/title.
     *
     * @param array<string,mixed> $candidate
     * @return array{0:int,1:string}  [song_id, action]  action = 'matched'|'created'|'needs_review'
     */
    private function findOrCreateSharedSong(array $candidate, string $normArtist, string $normTitle): array
    {
        // Look up by normalized artist + title
        $stmt = $this->db->prepare(
            'SELECT id FROM shared_songs WHERE normalized_artist = ? AND normalized_title = ? LIMIT 1'
        );
        $stmt->execute([$normArtist, $normTitle]);
        $existing = $stmt->fetchColumn();

        if ($existing !== false) {
            // Update normalized fields if missing (backfill)
            $this->db->prepare(
                'UPDATE shared_songs SET
                   normalized_artist = COALESCE(normalized_artist, ?),
                   normalized_title  = COALESCE(normalized_title, ?)
                 WHERE id = ?'
            )->execute([$normArtist, $normTitle, (int)$existing]);
            return [(int)$existing, 'matched'];
        }

        // Also try the unique index (title, artist) for exact matches
        $stmt2 = $this->db->prepare(
            'SELECT id FROM shared_songs WHERE title = ? AND artist = ? LIMIT 1'
        );
        $stmt2->execute([$candidate['title'], $candidate['artist']]);
        $exact = $stmt2->fetchColumn();

        if ($exact !== false) {
            $this->db->prepare(
                'UPDATE shared_songs SET
                   normalized_artist = COALESCE(normalized_artist, ?),
                   normalized_title  = COALESCE(normalized_title, ?)
                 WHERE id = ?'
            )->execute([$normArtist, $normTitle, (int)$exact]);
            return [(int)$exact, 'matched'];
        }

        // Create new shared_songs row
        $artist = trim((string)$candidate['artist']);
        $title  = trim((string)$candidate['title']);
        $year   = ($candidate['year'] ?? null) ?: null;
        $decade = $year ? $year - ($year % 10) : (($candidate['decade'] ?? null) ?: null);
        $genre  = ($candidate['genre_hint'] ?? null) ?: null;
        $duo    = (bool)($candidate['duo'] ?? false);
        $explicit = (bool)($candidate['explicit'] ?? false);

        $styles    = $candidate['styles']    ?? null;
        $languages = $candidate['languages'] ?? null;

        $this->db->prepare(
            'INSERT INTO shared_songs
               (title, artist, genre, year, decade, duo, explicit, styles, languages,
                normalized_title, normalized_artist)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            substr($title, 0, 255),
            substr($artist, 0, 255),
            $genre ? substr($genre, 0, 120) : null,
            $year && $year >= 1900 && $year <= 2100 ? $year : null,
            $decade && $decade >= 1900 ? $decade : null,
            $duo ? 1 : 0,
            $explicit ? 1 : 0,
            is_array($styles) && $styles ? json_encode($styles) : null,
            is_array($languages) && $languages ? json_encode($languages) : null,
            $normTitle,
            $normArtist,
        ]);
        return [(int)$this->db->lastInsertId(), 'created'];
    }

    // ------------------------------------------------------------------

    private function loadConfig(string $filename): array
    {
        $path = $this->rootPath . '/config/' . $filename;
        if (!is_readable($path)) {
            return [];
        }
        $data = require $path;
        return is_array($data) ? $data : [];
    }

    private function log(string $msg): void
    {
        if ($this->verbose) {
            echo $msg . "\n";
        }
    }

    private function warn(string $msg): void
    {
        fwrite(STDERR, "WARNING: {$msg}\n");
    }
}
