<?php

declare(strict_types=1);

namespace PanicMic\Services;

use PDO;

/**
 * Scoring engine for the curated catalog.
 *
 * Calculates discovery scores for shared songs based on import metadata,
 * tags, and source appearances. All scores are TINYINT UNSIGNED (0–100).
 *
 * The goal is not "best song" but "most useful song for karaoke browsing."
 */
final class CatalogScoringService
{
    /**
     * Recalculate all scores for a single shared song and persist them.
     *
     * @param array<string,mixed> $config  Contents of config/catalog-scoring.php
     */
    public static function recalculateSong(PDO $db, int $songId, array $config): void
    {
        $song = $db->prepare('SELECT * FROM shared_songs WHERE id = ? LIMIT 1');
        $song->execute([$songId]);
        $row = $song->fetch();
        if (!$row) {
            return;
        }

        // Gather tag slugs for this song
        $tagStmt = $db->prepare(
            'SELECT t.slug FROM shared_song_tag_links tl
             JOIN shared_song_tags t ON t.id = tl.tag_id
             WHERE tl.shared_song_id = ?'
        );
        $tagStmt->execute([$songId]);
        $tagSlugs = array_column($tagStmt->fetchAll(), 'slug');

        // Count source appearances
        $srcCount = (int)$db->prepare(
            'SELECT COUNT(DISTINCT source_list_id) FROM shared_song_source_links WHERE shared_song_id = ?'
        )->execute([$songId]) ? $db->prepare(
            'SELECT COUNT(DISTINCT source_list_id) FROM shared_song_source_links WHERE shared_song_id = ?'
        ) : null;
        // Re-query cleanly
        $srcStmt = $db->prepare(
            'SELECT COUNT(DISTINCT source_list_id) as cnt,
                    COUNT(DISTINCT source_id) as src_diversity,
                    MIN(rank) as best_rank
             FROM shared_song_source_links WHERE shared_song_id = ?'
        );
        $srcStmt->execute([$songId]);
        $srcData = $srcStmt->fetch();
        $sourceListCount  = (int)($srcData['cnt'] ?? 0);
        $sourceDiversity  = (int)($srcData['src_diversity'] ?? 0);
        $bestRank         = $srcData['best_rank'] !== null ? (int)$srcData['best_rank'] : null;

        // Check station/market tags
        $hasLocal    = in_array('bay-area-nostalgia', $tagSlugs, true) || in_array('live105-classic', $tagSlugs, true);
        $listeners   = isset($row['listeners']) ? (int)$row['listeners'] : null;
        $decade      = isset($row['decade'])    ? (int)$row['decade']    : null;
        $duo         = (bool)($row['duo'] ?? false);
        $explicit    = (bool)($row['explicit'] ?? false);

        $sourceScore   = self::calcSourceScore($config, $sourceListCount, $sourceDiversity, $bestRank);
        $nostalgiaScore= self::calcNostalgiaScore($config, $sourceListCount, $decade, $hasLocal, $listeners);
        $singalongScore= self::calcSingalongScore($config, $tagSlugs);
        $crowdScore    = self::calcCrowdScore($config, $tagSlugs);
        $karaokeScore  = self::calcKaraokeScore(
            $config, $sourceScore, $nostalgiaScore, $singalongScore, $crowdScore,
            $hasLocal, $duo, $tagSlugs,
        );

        $db->prepare(
            'UPDATE shared_songs SET
               source_score    = ?,
               nostalgia_score = ?,
               singalong_score = ?,
               crowd_score     = ?,
               karaoke_score   = ?
             WHERE id = ?'
        )->execute([
            $sourceScore,
            $nostalgiaScore,
            $singalongScore,
            $crowdScore,
            $karaokeScore,
            $songId,
        ]);
    }

    /**
     * Recalculate scores for all active shared songs.
     * Returns the count of songs updated.
     */
    public static function recalculateAll(PDO $db, array $config): int
    {
        $ids = $db->query('SELECT id FROM shared_songs WHERE is_active = 1')->fetchAll(\PDO::FETCH_COLUMN);
        $count = 0;
        foreach ($ids as $id) {
            self::recalculateSong($db, (int)$id, $config);
            $count++;
        }
        return $count;
    }

    // ------------------------------------------------------------------
    // Score calculations
    // ------------------------------------------------------------------

    public static function calcSourceScore(array $config, int $listCount, int $diversity, ?int $bestRank): int
    {
        $cfg = $config['source_score'] ?? [];
        $perList          = (int)($cfg['per_list']          ?? 10);
        $diminishAfter    = (int)($cfg['diminishing_after'] ?? 3);
        $top10Bonus       = (int)($cfg['top10_bonus']       ?? 15);
        $rank1Bonus       = (int)($cfg['rank1_bonus']       ?? 10);
        $diversityBonus   = (int)($cfg['diversity_bonus']   ?? 8);
        $max              = (int)($cfg['max']               ?? 100);

        if ($listCount === 0) {
            return 0;
        }

        $score = 0;
        for ($i = 1; $i <= $listCount; $i++) {
            if ($i <= $diminishAfter) {
                $score += $perList;
            } else {
                $score += max(1, (int)($perList / ($i - $diminishAfter + 1)));
            }
        }

        if ($bestRank !== null && $bestRank <= 10) {
            $score += $top10Bonus;
        }
        if ($bestRank === 1) {
            $score += $rank1Bonus;
        }
        if ($diversity >= 3) {
            $score += $diversityBonus;
        }

        return min($max, $score);
    }

    public static function calcNostalgiaScore(
        array $config,
        int $listCount,
        ?int $decade,
        bool $hasLocal,
        ?int $listeners,
    ): int {
        $cfg = $config['nostalgia_score'] ?? [];
        $perDecadeList  = (int)($cfg['per_decade_list']    ?? 12);
        $peakEraBonus   = (int)($cfg['peak_era_bonus']     ?? 10);
        $localBonus     = (int)($cfg['local_source_bonus'] ?? 8);
        $max            = (int)($cfg['max']                ?? 100);

        $score = min(40, $listCount * $perDecadeList);

        if ($decade !== null && in_array($decade, [1980, 1990], true)) {
            $score += $peakEraBonus;
        }
        if ($hasLocal) {
            $score += $localBonus;
        }
        if ($listeners !== null) {
            $score += self::listenersBonus($config, $listeners);
        }

        return min($max, $score);
    }

    /**
     * @param list<string> $tagSlugs
     */
    public static function calcSingalongScore(array $config, array $tagSlugs): int
    {
        $cfg     = $config['singalong_score'] ?? [];
        $bonuses = $cfg['tag_bonuses']  ?? [];
        $maluses = $cfg['tag_penalties'] ?? [];
        $max     = (int)($cfg['max'] ?? 100);

        $score = 0;
        foreach ($bonuses as $slug => $pts) {
            if (in_array($slug, $tagSlugs, true)) {
                $score += (int)$pts;
            }
        }
        foreach ($maluses as $slug => $pts) {
            if (in_array($slug, $tagSlugs, true)) {
                $score += (int)$pts; // already negative
            }
        }
        return min($max, max(0, $score));
    }

    /**
     * @param list<string> $tagSlugs
     */
    public static function calcCrowdScore(array $config, array $tagSlugs): int
    {
        $cfg     = $config['crowd_score'] ?? [];
        $bonuses = $cfg['tag_bonuses']   ?? [];
        $maluses = $cfg['tag_penalties'] ?? [];
        $max     = (int)($cfg['max'] ?? 100);

        $score = 0;
        foreach ($bonuses as $slug => $pts) {
            if (in_array($slug, $tagSlugs, true)) {
                $score += (int)$pts;
            }
        }
        foreach ($maluses as $slug => $pts) {
            if (in_array($slug, $tagSlugs, true)) {
                $score += (int)$pts;
            }
        }
        return min($max, max(0, $score));
    }

    /**
     * @param list<string> $tagSlugs
     */
    public static function calcKaraokeScore(
        array $config,
        int $sourceScore,
        int $nostalgiaScore,
        int $singalongScore,
        int $crowdScore,
        bool $hasLocal,
        bool $isDuet,
        array $tagSlugs,
    ): int {
        $cfg = $config['karaoke_score'] ?? [];
        $sw  = (float)($cfg['source_weight']    ?? 0.30);
        $nw  = (float)($cfg['nostalgia_weight']  ?? 0.20);
        $siw = (float)($cfg['singalong_weight']  ?? 0.25);
        $crw = (float)($cfg['crowd_weight']      ?? 0.25);

        $base = $sourceScore * $sw
              + $nostalgiaScore * $nw
              + $singalongScore * $siw
              + $crowdScore * $crw;

        $bonus = 0;
        if ($hasLocal)  $bonus += (int)($cfg['local_bonus']   ?? 5);
        if ($isDuet)    $bonus += (int)($cfg['duet_bonus']    ?? 3);
        if (in_array('beginner-friendly', $tagSlugs, true))
                        $bonus += (int)($cfg['beginner_bonus'] ?? 3);

        $penalty = 0;
        if (in_array('long-song', $tagSlugs, true))
            $penalty += abs((int)($cfg['long_penalty']    ?? -4));
        if (in_array('deep-cut', $tagSlugs, true))
            $penalty += abs((int)($cfg['obscure_penalty'] ?? -6));

        $max = (int)($cfg['max'] ?? 100);
        return min($max, max(0, (int)round($base + $bonus - $penalty)));
    }

    private static function listenersBonus(array $config, int $listeners): int
    {
        $cfg = $config['listeners'] ?? [];
        if ($listeners >= 1_000_000) return (int)($cfg['million_plus']    ?? 15);
        if ($listeners >= 100_000)   return (int)($cfg['hundred_k_plus']  ?? 10);
        if ($listeners >= 10_000)    return (int)($cfg['ten_k_plus']      ?? 5);
        return 0;
    }
}
