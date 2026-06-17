<?php

/**
 * Rule-based auto-tagging configuration for the curated catalog.
 *
 * Each rule is an array with:
 *  - 'condition' : callable(array $song, array $candidate): bool
 *  - 'tags'      : list<string>  (tag slugs to apply)
 *  - 'confidence': int 0-100
 *  - 'source'    : 'rule' (always for config-driven rules)
 *
 * The $song array contains shared_songs columns.
 * The $candidate array contains the normalized candidate row from an import.
 * Both may contain nulls for any field.
 *
 * CatalogTaggingService::applyRules() iterates these in order.
 */

declare(strict_types=1);

return [

    // ------------------------------------------------------------------
    // Station / market rules
    // ------------------------------------------------------------------

    [
        'name'       => 'KITS/Live 105 Classic',
        'condition'  => static fn (array $song, array $cand): bool =>
            (strtoupper((string)($cand['station'] ?? '')) === 'KITS')
            || strtolower((string)($cand['source_slug'] ?? '')) === 'live105',
        'tags'       => ['live105-classic', 'bay-area-nostalgia'],
        'confidence' => 90,
        'source'     => 'rule',
    ],

    [
        'name'       => 'Bay Area market',
        'condition'  => static fn (array $song, array $cand): bool =>
            stripos((string)($cand['market'] ?? ''), 'san francisco') !== false
            || stripos((string)($cand['market'] ?? ''), 'bay area') !== false,
        'tags'       => ['bay-area-nostalgia'],
        'confidence' => 80,
        'source'     => 'rule',
    ],

    // ------------------------------------------------------------------
    // Decade + genre hint combinations
    // ------------------------------------------------------------------

    [
        'name'       => '80s Alternative → New Wave',
        'condition'  => static fn (array $song, array $cand): bool =>
            ((int)($cand['decade'] ?? $song['decade'] ?? 0)) === 1980
            && (stripos((string)($cand['genre_hint'] ?? $song['genre'] ?? ''), 'alternative') !== false
                || stripos((string)($cand['genre_hint'] ?? $song['genre'] ?? ''), 'new wave') !== false),
        'tags'       => ['new-wave', 'alternative', '1980s'],
        'confidence' => 75,
        'source'     => 'rule',
    ],

    [
        'name'       => '80s decade tag',
        'condition'  => static fn (array $song, array $cand): bool =>
            ((int)($cand['decade'] ?? $song['decade'] ?? 0)) === 1980,
        'tags'       => ['1980s'],
        'confidence' => 95,
        'source'     => 'rule',
    ],

    [
        'name'       => '90s Alternative/Grunge',
        'condition'  => static fn (array $song, array $cand): bool =>
            ((int)($cand['decade'] ?? $song['decade'] ?? 0)) === 1990
            && (stripos((string)($cand['genre_hint'] ?? $song['genre'] ?? ''), 'alternative') !== false
                || stripos((string)($cand['genre_hint'] ?? $song['genre'] ?? ''), 'grunge') !== false),
        'tags'       => ['alternative', 'grunge', '1990s'],
        'confidence' => 75,
        'source'     => 'rule',
    ],

    [
        'name'       => '90s decade tag',
        'condition'  => static fn (array $song, array $cand): bool =>
            ((int)($cand['decade'] ?? $song['decade'] ?? 0)) === 1990,
        'tags'       => ['1990s'],
        'confidence' => 95,
        'source'     => 'rule',
    ],

    [
        'name'       => '2000s decade tag',
        'condition'  => static fn (array $song, array $cand): bool =>
            ((int)($cand['decade'] ?? $song['decade'] ?? 0)) === 2000,
        'tags'       => ['2000s'],
        'confidence' => 95,
        'source'     => 'rule',
    ],

    [
        'name'       => '70s decade tag',
        'condition'  => static fn (array $song, array $cand): bool =>
            ((int)($cand['decade'] ?? $song['decade'] ?? 0)) === 1970,
        'tags'       => ['1970s', 'classic-rock'],
        'confidence' => 70,
        'source'     => 'rule',
    ],

    // ------------------------------------------------------------------
    // Genre hint → genre tags
    // ------------------------------------------------------------------

    [
        'name'       => 'Punk genre hint',
        'condition'  => static fn (array $song, array $cand): bool =>
            stripos((string)($cand['genre_hint'] ?? $song['genre'] ?? ''), 'punk') !== false,
        'tags'       => ['punk', 'punk-ish-singalong'],
        'confidence' => 70,
        'source'     => 'rule',
    ],

    [
        'name'       => 'Pop genre hint',
        'condition'  => static fn (array $song, array $cand): bool =>
            strtolower(trim((string)($cand['genre_hint'] ?? $song['genre'] ?? ''))) === 'pop',
        'tags'       => ['pop'],
        'confidence' => 80,
        'source'     => 'rule',
    ],

    [
        'name'       => 'Rock genre hint',
        'condition'  => static fn (array $song, array $cand): bool =>
            (bool)preg_match('/\brock\b/i', (string)($cand['genre_hint'] ?? $song['genre'] ?? '')),
        'tags'       => ['rock'],
        'confidence' => 80,
        'source'     => 'rule',
    ],

    [
        'name'       => 'Hip-Hop / Rap genre hint',
        'condition'  => static fn (array $song, array $cand): bool =>
            (bool)preg_match('/\b(hip.?hop|rap)\b/i', (string)($cand['genre_hint'] ?? $song['genre'] ?? '')),
        'tags'       => ['hip-hop', 'rap'],
        'confidence' => 80,
        'source'     => 'rule',
    ],

    [
        'name'       => 'R&B / Soul genre hint',
        'condition'  => static fn (array $song, array $cand): bool =>
            (bool)preg_match('/\b(r&b|rnb|soul|funk)\b/i', (string)($cand['genre_hint'] ?? $song['genre'] ?? '')),
        'tags'       => ['rnb'],
        'confidence' => 75,
        'source'     => 'rule',
    ],

    // ------------------------------------------------------------------
    // Structural song properties
    // ------------------------------------------------------------------

    [
        'name'       => 'Duo/Duet flag',
        'condition'  => static fn (array $song, array $cand): bool =>
            (bool)($song['duo'] ?? $cand['duo'] ?? false),
        'tags'       => ['duet'],
        'confidence' => 90,
        'source'     => 'rule',
    ],

    [
        'name'       => 'Explicit flag',
        'condition'  => static fn (array $song, array $cand): bool =>
            (bool)($song['explicit'] ?? $cand['explicit'] ?? false),
        'tags'       => ['explicit-lyrics'],
        'confidence' => 95,
        'source'     => 'rule',
    ],

    // ------------------------------------------------------------------
    // Source diversity → universal recognition
    // ------------------------------------------------------------------

    [
        'name'       => 'High source count → Songs Everyone Knows',
        'condition'  => static fn (array $song, array $cand): bool =>
            ((int)($song['source_count'] ?? 0)) >= 5,
        'tags'       => ['songs-everyone-knows', 'crowd-favorite'],
        'confidence' => 60,
        'source'     => 'rule',
    ],

    [
        'name'       => 'Moderate source count → Crowd Favorite',
        'condition'  => static fn (array $song, array $cand): bool =>
            ((int)($song['source_count'] ?? 0)) >= 3
            && ((int)($song['source_count'] ?? 0)) < 5,
        'tags'       => ['crowd-favorite'],
        'confidence' => 55,
        'source'     => 'rule',
    ],

    // ------------------------------------------------------------------
    // Default tags from list config (applied when list has default_tags)
    // ------------------------------------------------------------------
    // These are injected dynamically by CatalogTaggingService::applyDefaultTags()
    // from the source config's 'default_tags' array — not listed here.

];
