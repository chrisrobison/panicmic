<?php

/**
 * Last.fm tag-based source configurations.
 *
 * Requires LASTFM_API_KEY in .env.
 * Fetches up to $limit tracks per tag using tag.getTopTracks pagination.
 *
 * These are treated as genre-discovery sources, not curated countdowns.
 * Their source_weight is lower than radio station sources.
 */

declare(strict_types=1);

return [
    [
        'slug'         => 'lastfm-rock',
        'source_name'  => 'Last.fm',
        'source_slug'  => 'lastfm',
        'source_type'  => 'api',
        'tag'          => 'rock',
        'limit'        => 500,
        'source_weight'=> 3,
        'genre_hint'   => 'Rock',
        'list_title'   => 'Last.fm Top Rock Tracks',
        'list_slug'    => 'lastfm-rock',
        'list_type'    => 'genre',
        'default_tags' => ['rock'],
    ],
    [
        'slug'         => 'lastfm-pop',
        'source_name'  => 'Last.fm',
        'source_slug'  => 'lastfm',
        'source_type'  => 'api',
        'tag'          => 'pop',
        'limit'        => 500,
        'source_weight'=> 3,
        'genre_hint'   => 'Pop',
        'list_title'   => 'Last.fm Top Pop Tracks',
        'list_slug'    => 'lastfm-pop',
        'list_type'    => 'genre',
        'default_tags' => ['pop'],
    ],
    [
        'slug'         => 'lastfm-alternative',
        'source_name'  => 'Last.fm',
        'source_slug'  => 'lastfm',
        'source_type'  => 'api',
        'tag'          => 'alternative',
        'limit'        => 500,
        'source_weight'=> 3,
        'genre_hint'   => 'Alternative',
        'list_title'   => 'Last.fm Top Alternative Tracks',
        'list_slug'    => 'lastfm-alternative',
        'list_type'    => 'genre',
        'default_tags' => ['alternative'],
    ],
    [
        'slug'         => 'lastfm-hip-hop',
        'source_name'  => 'Last.fm',
        'source_slug'  => 'lastfm',
        'source_type'  => 'api',
        'tag'          => 'hip-hop',
        'limit'        => 500,
        'source_weight'=> 3,
        'genre_hint'   => 'Hip-Hop',
        'list_title'   => 'Last.fm Top Hip-Hop Tracks',
        'list_slug'    => 'lastfm-hip-hop',
        'list_type'    => 'genre',
        'default_tags' => ['hip-hop', 'rap'],
    ],
    [
        'slug'         => 'lastfm-punk',
        'source_name'  => 'Last.fm',
        'source_slug'  => 'lastfm',
        'source_type'  => 'api',
        'tag'          => 'punk',
        'limit'        => 500,
        'source_weight'=> 3,
        'genre_hint'   => 'Punk',
        'list_title'   => 'Last.fm Top Punk Tracks',
        'list_slug'    => 'lastfm-punk',
        'list_type'    => 'genre',
        'default_tags' => ['punk', 'punk-ish-singalong'],
    ],
    [
        'slug'         => 'lastfm-rnb',
        'source_name'  => 'Last.fm',
        'source_slug'  => 'lastfm',
        'source_type'  => 'api',
        'tag'          => 'rnb',
        'limit'        => 500,
        'source_weight'=> 3,
        'genre_hint'   => 'R&B',
        'list_title'   => 'Last.fm Top R&B Tracks',
        'list_slug'    => 'lastfm-rnb',
        'list_type'    => 'genre',
        'default_tags' => ['rnb'],
    ],
    [
        'slug'         => 'lastfm-country',
        'source_name'  => 'Last.fm',
        'source_slug'  => 'lastfm',
        'source_type'  => 'api',
        'tag'          => 'country',
        'limit'        => 500,
        'source_weight'=> 3,
        'genre_hint'   => 'Country',
        'list_title'   => 'Last.fm Top Country Tracks',
        'list_slug'    => 'lastfm-country',
        'list_type'    => 'genre',
        'default_tags' => ['country'],
    ],
];
