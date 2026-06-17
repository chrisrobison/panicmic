<?php

/**
 * Scoring configuration for the curated catalog.
 *
 * CatalogScoringService uses these weights when calculating per-song scores.
 * The goal is not "best song" but "most useful song for karaoke discovery."
 *
 * All scores are clamped to 0–100 by the service.
 */

declare(strict_types=1);

return [

    // ------------------------------------------------------------------
    // source_score — how many curated sources mention this song
    // ------------------------------------------------------------------

    'source_score' => [
        // Base points per source list appearance (first appearance = full weight)
        'per_list'          => 10,
        // Diminishing returns after N appearances
        'diminishing_after' => 3,
        // Multiplier for top-10 appearances (rank 1-10)
        'top10_bonus'       => 15,
        // Multiplier for rank 1
        'rank1_bonus'       => 10,
        // Bonus for appearing in multiple distinct stations (diversity bonus)
        'diversity_bonus'   => 8,
        'max'               => 100,
    ],

    // ------------------------------------------------------------------
    // nostalgia_score — decade + local relevance
    // ------------------------------------------------------------------

    'nostalgia_score' => [
        // Points for each decade with a curated list (1980–1999)
        'per_decade_list'   => 12,
        // Bonus for 80s/90s songs (peak nostalgia)
        'peak_era_bonus'    => 10,   // 1980s or 1990s
        // Bonus for Bay Area / local sources
        'local_source_bonus'=> 8,
        'max'               => 100,
    ],

    // ------------------------------------------------------------------
    // singalong_score — how singalong-friendly is this song?
    // ------------------------------------------------------------------

    'singalong_score' => [
        // Tag bonuses (applied when tag is present)
        'tag_bonuses' => [
            'songs-everyone-knows' => 25,
            'bar-singalong'        => 20,
            'big-chorus'           => 15,
            'crowd-favorite'       => 15,
            'easy-chorus'          => 12,
            'beginner-friendly'    => 10,
            'under-4-minutes'      => 8,
            'punk-ish-singalong'   => 10,
            'guilty-pleasure'      => 8,
            'songs-you-forgot-you-know' => 5,
        ],
        // Tag penalties
        'tag_penalties' => [
            'hard-vocals'   => -10,
            'fast-rap'      => -8,
            'long-song'     => -5,
            'deep-cut'      => -8,
        ],
        'max' => 100,
    ],

    // ------------------------------------------------------------------
    // crowd_score — crowd appeal
    // ------------------------------------------------------------------

    'crowd_score' => [
        'tag_bonuses' => [
            'crowd-favorite'       => 25,
            'songs-everyone-knows' => 20,
            'dance-floor'          => 15,
            'power-ballad'         => 12,
            'guilty-pleasure'      => 10,
            'wedding-ish'          => 8,
            'funny-song'           => 8,
            'last-call'            => 5,
        ],
        'tag_penalties' => [
            'deep-cut'     => -10,
            'angry-song'   => -5,
            'explicit-lyrics' => -3,
        ],
        'max' => 100,
    ],

    // ------------------------------------------------------------------
    // karaoke_score — composite "usefulness for karaoke browsing"
    // ------------------------------------------------------------------

    'karaoke_score' => [
        // Weights for each component score
        'source_weight'    => 0.30,
        'nostalgia_weight' => 0.20,
        'singalong_weight' => 0.25,
        'crowd_weight'     => 0.25,

        // Bonus: local/bay-area tags
        'local_bonus'    => 5,
        // Bonus: duet
        'duet_bonus'     => 3,
        // Bonus: beginner-friendly
        'beginner_bonus' => 3,
        // Penalty: very long songs
        'long_penalty'   => -4,
        // Penalty: obscure / deep cut
        'obscure_penalty' => -6,
        'max' => 100,
    ],

    // ------------------------------------------------------------------
    // Popularity proxy (Last.fm listeners) — used in nostalgia/crowd scores
    // ------------------------------------------------------------------

    'listeners' => [
        // Log-scaled listeners → bonus points (0–15)
        'million_plus'     => 15,   // 1M+ listeners
        'hundred_k_plus'   => 10,
        'ten_k_plus'       => 5,
        'under_ten_k'      => 0,
    ],
];
