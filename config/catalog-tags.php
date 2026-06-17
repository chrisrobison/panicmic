<?php

/**
 * Canonical tag vocabulary for the curated catalog.
 *
 * This file is the single source of truth for tag names, slugs, and types.
 * The migration (009_curated_catalog_imports.sql) seeds these into the DB;
 * this file is used by CatalogTaggingService for in-code reference.
 *
 * tag_type values: genre | mood | difficulty | era | occasion | voice | local | editorial
 */

declare(strict_types=1);

return [
    // Genre ---------------------------------------------------------------
    ['name' => 'Pop',         'slug' => 'pop',         'type' => 'genre'],
    ['name' => 'Rock',        'slug' => 'rock',        'type' => 'genre'],
    ['name' => 'Alternative', 'slug' => 'alternative', 'type' => 'genre'],
    ['name' => 'New Wave',    'slug' => 'new-wave',    'type' => 'genre'],
    ['name' => 'Punk',        'slug' => 'punk',        'type' => 'genre'],
    ['name' => 'Post-Punk',   'slug' => 'post-punk',   'type' => 'genre'],
    ['name' => 'Metal',       'slug' => 'metal',       'type' => 'genre'],
    ['name' => 'Classic Rock','slug' => 'classic-rock','type' => 'genre'],
    ['name' => 'Grunge',      'slug' => 'grunge',      'type' => 'genre'],
    ['name' => 'Indie',       'slug' => 'indie',       'type' => 'genre'],
    ['name' => 'Hip-Hop',     'slug' => 'hip-hop',     'type' => 'genre'],
    ['name' => 'Rap',         'slug' => 'rap',         'type' => 'genre'],
    ['name' => 'R&B',         'slug' => 'rnb',         'type' => 'genre'],
    ['name' => 'Soul',        'slug' => 'soul',        'type' => 'genre'],
    ['name' => 'Funk',        'slug' => 'funk',        'type' => 'genre'],
    ['name' => 'Country',     'slug' => 'country',     'type' => 'genre'],
    ['name' => 'Folk',        'slug' => 'folk',        'type' => 'genre'],
    ['name' => 'Reggae',      'slug' => 'reggae',      'type' => 'genre'],
    ['name' => 'Ska',         'slug' => 'ska',         'type' => 'genre'],
    ['name' => 'Latin',       'slug' => 'latin',       'type' => 'genre'],
    ['name' => 'Dance',       'slug' => 'dance',       'type' => 'genre'],
    ['name' => 'Disco',       'slug' => 'disco',       'type' => 'genre'],
    ['name' => 'Electronic',  'slug' => 'electronic',  'type' => 'genre'],
    ['name' => 'Jazz',        'slug' => 'jazz',        'type' => 'genre'],
    ['name' => 'Blues',       'slug' => 'blues',       'type' => 'genre'],
    ['name' => 'Showtunes',   'slug' => 'showtunes',   'type' => 'genre'],
    ['name' => 'Soundtrack',  'slug' => 'soundtrack',  'type' => 'genre'],
    // Era ------------------------------------------------------------------
    ['name' => '1970s',       'slug' => '1970s',       'type' => 'era'],
    ['name' => '1980s',       'slug' => '1980s',       'type' => 'era'],
    ['name' => '1990s',       'slug' => '1990s',       'type' => 'era'],
    ['name' => '2000s',       'slug' => '2000s',       'type' => 'era'],
    ['name' => '2010s',       'slug' => '2010s',       'type' => 'era'],
    ['name' => '2020s',       'slug' => '2020s',       'type' => 'era'],
    // Karaoke/use-case -----------------------------------------------------
    ['name' => 'Beginner Friendly',      'slug' => 'beginner-friendly',      'type' => 'difficulty'],
    ['name' => 'Crowd Favorite',         'slug' => 'crowd-favorite',         'type' => 'occasion'],
    ['name' => 'Power Ballad',           'slug' => 'power-ballad',           'type' => 'mood'],
    ['name' => 'Guilty Pleasure',        'slug' => 'guilty-pleasure',        'type' => 'mood'],
    ['name' => 'Duet',                   'slug' => 'duet',                   'type' => 'occasion'],
    ['name' => 'Group Song',             'slug' => 'group-song',             'type' => 'occasion'],
    ['name' => 'Big Chorus',             'slug' => 'big-chorus',             'type' => 'occasion'],
    ['name' => 'Easy Chorus',            'slug' => 'easy-chorus',            'type' => 'difficulty'],
    ['name' => 'Hard Vocals',            'slug' => 'hard-vocals',            'type' => 'difficulty'],
    ['name' => 'Fast Rap',               'slug' => 'fast-rap',               'type' => 'difficulty'],
    ['name' => 'Explicit Lyrics',        'slug' => 'explicit-lyrics',        'type' => 'occasion'],
    ['name' => 'Under 4 Minutes',        'slug' => 'under-4-minutes',        'type' => 'occasion'],
    ['name' => 'Long Song',              'slug' => 'long-song',              'type' => 'occasion'],
    ['name' => 'Low Voice Friendly',     'slug' => 'low-voice-friendly',     'type' => 'voice'],
    ['name' => 'High Voice Friendly',    'slug' => 'high-voice-friendly',    'type' => 'voice'],
    ['name' => 'Bar Singalong',          'slug' => 'bar-singalong',          'type' => 'occasion'],
    ['name' => 'Last Call',              'slug' => 'last-call',              'type' => 'occasion'],
    ['name' => 'Dance Floor',            'slug' => 'dance-floor',            'type' => 'occasion'],
    ['name' => 'Breakup Song',           'slug' => 'breakup-song',           'type' => 'mood'],
    ['name' => 'Love Song',              'slug' => 'love-song',              'type' => 'mood'],
    ['name' => 'Angry Song',             'slug' => 'angry-song',             'type' => 'mood'],
    ['name' => 'Sad Banger',             'slug' => 'sad-banger',             'type' => 'mood'],
    ['name' => 'Funny Song',             'slug' => 'funny-song',             'type' => 'mood'],
    ['name' => 'Wedding-ish',            'slug' => 'wedding-ish',            'type' => 'occasion'],
    ['name' => 'Dangerous But Glorious', 'slug' => 'dangerous-but-glorious', 'type' => 'difficulty'],
    // Local/editorial ------------------------------------------------------
    ['name' => 'Mabuhay Classic',           'slug' => 'mabuhay-classic',           'type' => 'local'],
    ['name' => 'Live 105 Classic',          'slug' => 'live105-classic',           'type' => 'local'],
    ['name' => 'Bay Area Nostalgia',        'slug' => 'bay-area-nostalgia',        'type' => 'local'],
    ['name' => 'Punk-ish Singalong',        'slug' => 'punk-ish-singalong',        'type' => 'editorial'],
    ['name' => 'Songs You Forgot You Know', 'slug' => 'songs-you-forgot-you-know', 'type' => 'editorial'],
    ['name' => 'Songs Everyone Knows',      'slug' => 'songs-everyone-knows',      'type' => 'editorial'],
    ['name' => 'Deep Cut',                  'slug' => 'deep-cut',                  'type' => 'editorial'],
    ['name' => 'KJ Panic Pick',             'slug' => 'kj-panic-pick',             'type' => 'editorial'],
];
