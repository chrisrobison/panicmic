<?php

declare(strict_types=1);

namespace PanicMic\Services;

use PDO;

/**
 * Persistence layer for the curated catalog source/list/candidate tables.
 *
 * Handles upserts for sources, lists, candidates, and source_links.
 * All methods are static and accept a super PDO connection.
 */
final class CatalogSourceService
{
    // ------------------------------------------------------------------
    // Sources
    // ------------------------------------------------------------------

    /**
     * Upsert a source definition. Returns the source id.
     *
     * @param array<string,mixed> $data
     */
    public static function upsertSource(PDO $db, array $data): int
    {
        $stmt = $db->prepare(
            'INSERT INTO shared_song_sources (name, slug, source_type, station, market, url, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               name        = VALUES(name),
               source_type = VALUES(source_type),
               station     = COALESCE(VALUES(station), station),
               market      = COALESCE(VALUES(market), market),
               url         = COALESCE(VALUES(url), url),
               notes       = COALESCE(VALUES(notes), notes),
               updated_at  = NOW()'
        );
        $stmt->execute([
            substr(trim((string)$data['name']), 0, 255),
            substr(trim((string)$data['slug']), 0, 120),
            $data['source_type'] ?? 'manual',
            ($data['station'] ?? null) ?: null,
            ($data['market']  ?? null) ?: null,
            ($data['url']     ?? null) ?: null,
            ($data['notes']   ?? null) ?: null,
        ]);
        return (int)self::findSourceId($db, (string)$data['slug']);
    }

    public static function findSourceId(PDO $db, string $slug): ?int
    {
        $stmt = $db->prepare('SELECT id FROM shared_song_sources WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
    }

    /** @return list<array<string,mixed>> */
    public static function allSources(PDO $db): array
    {
        return $db->query('SELECT * FROM shared_song_sources ORDER BY name ASC')->fetchAll();
    }

    // ------------------------------------------------------------------
    // Source lists
    // ------------------------------------------------------------------

    /**
     * Upsert a source list. Returns the list id.
     *
     * @param array<string,mixed> $data
     */
    public static function upsertList(PDO $db, int $sourceId, array $data): int
    {
        $stmt = $db->prepare(
            'INSERT INTO shared_song_source_lists
               (source_id, title, slug, year, decade, genre_hint, list_type, url, parser_version)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               title          = VALUES(title),
               year           = COALESCE(VALUES(year), year),
               decade         = COALESCE(VALUES(decade), decade),
               genre_hint     = COALESCE(VALUES(genre_hint), genre_hint),
               list_type      = VALUES(list_type),
               url            = COALESCE(VALUES(url), url),
               parser_version = COALESCE(VALUES(parser_version), parser_version),
               updated_at     = NOW()'
        );
        $stmt->execute([
            $sourceId,
            substr(trim((string)$data['title']), 0, 255),
            substr(trim((string)$data['slug']),  0, 160),
            ($data['year']   ?? null) ?: null,
            ($data['decade'] ?? null) ?: null,
            ($data['genre_hint'] ?? null) ?: null,
            $data['list_type'] ?? 'manual',
            ($data['url']    ?? null) ?: null,
            ($data['parser_version'] ?? null) ?: null,
        ]);
        return (int)self::findListId($db, (string)$data['slug']);
    }

    public static function findListId(PDO $db, string $slug): ?int
    {
        $stmt = $db->prepare('SELECT id FROM shared_song_source_lists WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
    }

    /** Mark a list as fetched now and store the cache path. */
    public static function markFetched(PDO $db, int $listId, ?string $cachePath = null): void
    {
        $db->prepare('UPDATE shared_song_source_lists SET fetched_at = NOW(), raw_cache_path = ? WHERE id = ?')
           ->execute([$cachePath, $listId]);
    }

    /** @return list<array<string,mixed>> */
    public static function allLists(PDO $db): array
    {
        return $db->query(
            'SELECT l.*, s.name AS source_name, s.slug AS source_slug, s.station, s.market
             FROM shared_song_source_lists l
             JOIN shared_song_sources s ON s.id = l.source_id
             ORDER BY l.year DESC, l.title ASC'
        )->fetchAll();
    }

    // ------------------------------------------------------------------
    // Candidates
    // ------------------------------------------------------------------

    /**
     * Insert or update a candidate row. Returns the candidate id.
     *
     * @param array<string,mixed> $data
     */
    public static function upsertCandidate(PDO $db, int $listId, array $data): int
    {
        $stmt = $db->prepare(
            'INSERT INTO shared_song_candidates
               (source_list_id, shared_song_id, artist, title,
                normalized_artist, normalized_title,
                rank, year, decade, genre_hint,
                raw_row_json, confidence_score, match_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               shared_song_id    = COALESCE(VALUES(shared_song_id), shared_song_id),
               confidence_score  = GREATEST(VALUES(confidence_score), confidence_score),
               match_status      = VALUES(match_status),
               updated_at        = NOW()'
        );

        // Note: there is no UNIQUE key on (source_list_id, normalized_artist, normalized_title)
        // so this always inserts; we only deduplicate at the shared_song level.
        // For re-runs, check if candidate exists first.
        $existing = $db->prepare(
            'SELECT id FROM shared_song_candidates
             WHERE source_list_id = ? AND normalized_artist = ? AND normalized_title = ? LIMIT 1'
        );
        $existing->execute([$listId, $data['normalized_artist'], $data['normalized_title']]);
        $existingId = $existing->fetchColumn();

        if ($existingId !== false) {
            // Update in place
            $db->prepare(
                'UPDATE shared_song_candidates SET
                   shared_song_id   = COALESCE(?, shared_song_id),
                   confidence_score = GREATEST(?, confidence_score),
                   match_status     = ?,
                   raw_row_json     = COALESCE(?, raw_row_json),
                   updated_at       = NOW()
                 WHERE id = ?'
            )->execute([
                ($data['shared_song_id'] ?? null) ?: null,
                (int)($data['confidence_score'] ?? 0),
                (string)($data['match_status'] ?? 'needs_review'),
                isset($data['raw']) ? json_encode($data['raw']) : null,
                (int)$existingId,
            ]);
            return (int)$existingId;
        }

        $stmt->execute([
            $listId,
            ($data['shared_song_id'] ?? null) ?: null,
            substr(trim((string)$data['artist']), 0, 255),
            substr(trim((string)$data['title']),  0, 255),
            ($data['normalized_artist'] ?? null) ?: null,
            ($data['normalized_title']  ?? null) ?: null,
            ($data['rank'] ?? null) ?: null,
            ($data['year'] ?? null) ?: null,
            ($data['decade'] ?? null) ?: null,
            ($data['genre_hint'] ?? null) ?: null,
            isset($data['raw']) ? json_encode($data['raw']) : null,
            (int)($data['confidence_score'] ?? 0),
            (string)($data['match_status'] ?? 'needs_review'),
        ]);
        return (int)$db->lastInsertId();
    }

    /** Update a candidate's match status and linked song. */
    public static function resolveCandidate(PDO $db, int $candidateId, int $songId, string $status): void
    {
        $db->prepare(
            'UPDATE shared_song_candidates
             SET shared_song_id = ?, match_status = ?, updated_at = NOW()
             WHERE id = ?'
        )->execute([$songId, $status, $candidateId]);
    }

    /** @return list<array<string,mixed>> */
    public static function unresolvedCandidates(PDO $db, int $listId): array
    {
        $stmt = $db->prepare(
            "SELECT * FROM shared_song_candidates
             WHERE source_list_id = ? AND match_status IN ('needs_review','possible_duplicate')
             ORDER BY rank ASC"
        );
        $stmt->execute([$listId]);
        return $stmt->fetchAll();
    }

    // ------------------------------------------------------------------
    // Source links
    // ------------------------------------------------------------------

    /**
     * Ensure a source_link row exists for (shared_song, source_list).
     *
     * @param array<string,mixed> $data
     */
    public static function upsertSourceLink(
        PDO $db,
        int $songId,
        int $sourceId,
        int $listId,
        array $data,
    ): void {
        $db->prepare(
            'INSERT INTO shared_song_source_links
               (shared_song_id, candidate_id, source_id, source_list_id,
                rank, year, decade, source_weight)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               rank           = COALESCE(VALUES(rank), rank),
               source_weight  = GREATEST(VALUES(source_weight), source_weight)'
        )->execute([
            $songId,
            ($data['candidate_id'] ?? null) ?: null,
            $sourceId,
            $listId,
            ($data['rank']   ?? null) ?: null,
            ($data['year']   ?? null) ?: null,
            ($data['decade'] ?? null) ?: null,
            (int)($data['source_weight'] ?? 1),
        ]);
    }

    /**
     * How many distinct source lists mention this song?
     */
    public static function sourceListCount(PDO $db, int $songId): int
    {
        $stmt = $db->prepare('SELECT COUNT(DISTINCT source_list_id) FROM shared_song_source_links WHERE shared_song_id = ?');
        $stmt->execute([$songId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Full source appearance history for one shared song.
     *
     * @return list<array<string,mixed>>
     */
    public static function sourceAppearancesForSong(PDO $db, int $songId): array
    {
        $stmt = $db->prepare(
            'SELECT sl.*, src.name AS source_name, src.slug AS source_slug,
                    src.station, src.market, src.source_type,
                    lst.title AS list_title, lst.slug AS list_slug,
                    lst.year AS list_year, lst.decade AS list_decade, lst.genre_hint
             FROM shared_song_source_links sl
             JOIN shared_song_sources src ON src.id = sl.source_id
             JOIN shared_song_source_lists lst ON lst.id = sl.source_list_id
             WHERE sl.shared_song_id = ?
             ORDER BY sl.rank ASC, lst.year DESC'
        );
        $stmt->execute([$songId]);
        return $stmt->fetchAll();
    }

    // ------------------------------------------------------------------
    // Import runs
    // ------------------------------------------------------------------

    public static function startRun(PDO $db, string $sourceSlug): int
    {
        $db->prepare(
            "INSERT INTO shared_song_import_runs (source_slug, status) VALUES (?, 'running')"
        )->execute([$sourceSlug]);
        return (int)$db->lastInsertId();
    }

    /**
     * @param array<string,mixed> $stats
     */
    public static function finishRun(PDO $db, int $runId, array $stats): void
    {
        $db->prepare(
            'UPDATE shared_song_import_runs SET
               status           = ?,
               finished_at      = NOW(),
               total_seen       = ?,
               total_imported   = ?,
               total_skipped    = ?,
               total_created    = ?,
               total_matched    = ?,
               total_needs_review = ?,
               report_path      = ?,
               error_message    = ?
             WHERE id = ?'
        )->execute([
            $stats['status']      ?? 'completed',
            $stats['seen']        ?? 0,
            $stats['imported']    ?? 0,
            $stats['skipped']     ?? 0,
            $stats['created']     ?? 0,
            $stats['matched']     ?? 0,
            $stats['needs_review']?? 0,
            $stats['report_path'] ?? null,
            $stats['error']       ?? null,
            $runId,
        ]);
    }

    /** @return list<array<string,mixed>> */
    public static function recentRuns(PDO $db, int $limit = 20): array
    {
        $stmt = $db->prepare(
            'SELECT * FROM shared_song_import_runs ORDER BY started_at DESC LIMIT ?'
        );
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public static function getRun(PDO $db, int $runId): ?array
    {
        $stmt = $db->prepare('SELECT * FROM shared_song_import_runs WHERE id = ? LIMIT 1');
        $stmt->execute([$runId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    // ------------------------------------------------------------------
    // Import warnings
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed>|null $rawJson
     */
    public static function addWarning(
        PDO $db,
        int $runId,
        string $type,
        string $message,
        ?int $listId = null,
        ?int $candidateId = null,
        ?array $rawJson = null,
    ): void {
        $db->prepare(
            'INSERT INTO shared_song_import_warnings
               (import_run_id, source_list_id, candidate_id, warning_type, message, raw_json)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $runId,
            $listId,
            $candidateId,
            substr($type, 0, 80),
            $message,
            $rawJson !== null ? json_encode($rawJson) : null,
        ]);
    }

    /** @return list<array<string,mixed>> */
    public static function warningsForRun(PDO $db, int $runId): array
    {
        $stmt = $db->prepare(
            'SELECT * FROM shared_song_import_warnings WHERE import_run_id = ? ORDER BY id ASC'
        );
        $stmt->execute([$runId]);
        return $stmt->fetchAll();
    }
}
