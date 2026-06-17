<?php

declare(strict_types=1);

namespace PanicMic\Services;

use PDO;

/**
 * Tag management for the curated catalog.
 *
 * Applies rule-based tags, seeds the tag vocabulary, and manages the
 * shared_song_tag_links many-to-many table.
 */
final class CatalogTaggingService
{
    // ------------------------------------------------------------------
    // Tag vocabulary
    // ------------------------------------------------------------------

    /**
     * Look up a tag id by slug. Returns null if not found.
     */
    public static function tagId(PDO $db, string $slug): ?int
    {
        $stmt = $db->prepare('SELECT id FROM shared_song_tags WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
    }

    /**
     * Look up multiple tag ids by slug. Returns [slug => id].
     *
     * @param list<string> $slugs
     * @return array<string,int>
     */
    public static function tagIds(PDO $db, array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($slugs), '?'));
        $stmt = $db->prepare("SELECT slug, id FROM shared_song_tags WHERE slug IN ({$placeholders})");
        $stmt->execute($slugs);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string)$row['slug']] = (int)$row['id'];
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public static function allTags(PDO $db): array
    {
        return $db->query('SELECT * FROM shared_song_tags ORDER BY tag_type, name')->fetchAll();
    }

    // ------------------------------------------------------------------
    // Applying tags to songs
    // ------------------------------------------------------------------

    /**
     * Apply a single tag to a shared song.
     *
     * @param 'rule'|'manual'|'lastfm'|'import'|'admin' $source
     */
    public static function applyTag(
        PDO $db,
        int $songId,
        int $tagId,
        int $confidence = 100,
        string $source = 'rule',
    ): void {
        $db->prepare(
            'INSERT INTO shared_song_tag_links (shared_song_id, tag_id, confidence, source)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               confidence = GREATEST(VALUES(confidence), confidence),
               source     = CASE WHEN VALUES(confidence) > confidence THEN VALUES(source) ELSE source END'
        )->execute([$songId, $tagId, $confidence, $source]);
    }

    /**
     * Apply a list of tag slugs to a song.
     *
     * @param list<string> $slugs
     * @param 'rule'|'manual'|'lastfm'|'import'|'admin' $source
     */
    public static function applyTagSlugs(
        PDO $db,
        int $songId,
        array $slugs,
        int $confidence = 100,
        string $source = 'rule',
    ): void {
        if ($slugs === []) {
            return;
        }
        $tagIds = self::tagIds($db, $slugs);
        foreach ($tagIds as $tagId) {
            self::applyTag($db, $songId, $tagId, $confidence, $source);
        }
    }

    /**
     * Remove a specific tag from a song.
     */
    public static function removeTag(PDO $db, int $songId, int $tagId): void
    {
        $db->prepare('DELETE FROM shared_song_tag_links WHERE shared_song_id = ? AND tag_id = ?')
           ->execute([$songId, $tagId]);
    }

    /**
     * Get all tags for a shared song.
     *
     * @return list<array<string,mixed>>
     */
    public static function tagsForSong(PDO $db, int $songId): array
    {
        $stmt = $db->prepare(
            'SELECT t.id, t.name, t.slug, t.tag_type, tl.confidence, tl.source
             FROM shared_song_tag_links tl
             JOIN shared_song_tags t ON t.id = tl.tag_id
             WHERE tl.shared_song_id = ?
             ORDER BY tl.confidence DESC, t.tag_type, t.name'
        );
        $stmt->execute([$songId]);
        return $stmt->fetchAll();
    }

    // ------------------------------------------------------------------
    // Rule-based tagging
    // ------------------------------------------------------------------

    /**
     * Apply all configured tag rules to a single song.
     *
     * @param array<string,mixed> $song     Row from shared_songs (with source_count if available)
     * @param array<string,mixed> $candidate Latest candidate row for this song
     */
    public static function applyRules(PDO $db, array $song, array $candidate, string $rulesFile): void
    {
        if (!is_readable($rulesFile)) {
            return;
        }
        $rules = require $rulesFile;
        if (!is_array($rules)) {
            return;
        }
        foreach ($rules as $rule) {
            $condition = $rule['condition'] ?? null;
            if (!is_callable($condition)) {
                continue;
            }
            if ($condition($song, $candidate)) {
                $tags = $rule['tags'] ?? [];
                $confidence = (int)($rule['confidence'] ?? 70);
                $source = (string)($rule['source'] ?? 'rule');
                /** @var list<string> $tags */
                self::applyTagSlugs($db, (int)$song['id'], $tags, $confidence, $source);
            }
        }
    }

    /**
     * Apply the default_tags from a source list config to a song.
     *
     * @param list<string> $tagSlugs
     */
    public static function applyDefaultTags(PDO $db, int $songId, array $tagSlugs, int $confidence = 75): void
    {
        self::applyTagSlugs($db, $songId, $tagSlugs, $confidence, 'import');
    }

    /**
     * Apply era tag based on decade.
     */
    public static function applyEraTags(PDO $db, int $songId, ?int $decade): void
    {
        if ($decade === null) {
            return;
        }
        $eraMap = [
            1970 => '1970s',
            1980 => '1980s',
            1990 => '1990s',
            2000 => '2000s',
            2010 => '2010s',
            2020 => '2020s',
        ];
        $slug = $eraMap[$decade] ?? null;
        if ($slug !== null) {
            self::applyTagSlugs($db, $songId, [$slug], 95, 'rule');
        }
    }

    /**
     * Apply Last.fm tag data from the enrichment pipeline.
     *
     * @param list<string> $lastfmTags  Raw Last.fm tag names
     */
    public static function applyLastfmTags(PDO $db, int $songId, array $lastfmTags): void
    {
        if ($lastfmTags === []) {
            return;
        }
        // Convert Last.fm tag names to slugs and see if any match our vocabulary
        $allTags = $db->query('SELECT id, slug, name FROM shared_song_tags')->fetchAll();
        $tagsBySlug = [];
        $tagsByName = [];
        foreach ($allTags as $row) {
            $tagsBySlug[strtolower((string)$row['slug'])] = (int)$row['id'];
            $tagsByName[strtolower((string)$row['name'])] = (int)$row['id'];
        }

        foreach ($lastfmTags as $rawTag) {
            $rawTag = trim(strtolower($rawTag));
            // Try exact slug match
            $tagId = $tagsBySlug[$rawTag] ?? null;
            if ($tagId === null) {
                // Try slug-ified version
                $slug = preg_replace('/[^a-z0-9]+/', '-', $rawTag) ?? $rawTag;
                $slug = trim($slug, '-');
                $tagId = $tagsBySlug[$slug] ?? null;
            }
            if ($tagId === null) {
                // Try name match
                $tagId = $tagsByName[$rawTag] ?? null;
            }
            if ($tagId !== null) {
                self::applyTag($db, $songId, $tagId, 60, 'lastfm');
            }
        }
    }
}
