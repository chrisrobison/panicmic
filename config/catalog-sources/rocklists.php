<?php

/**
 * Rocklists.com source configurations.
 *
 * Each entry is one year-end countdown list from a specific station.
 * URLs point to Rocklists.com pages — fetched once and cached in
 * storage/catalog-cache/. The importer respects robots.txt and
 * rate-limits. Do not hammer the site.
 *
 * parser.type values:
 *   ranked_text  — plain-text lines like "1. Artist - Song"
 *   ranked_html  — HTML with ordered-list or numbered rows
 *   table        — HTML table rows
 *
 * parser.format values:
 *   artist-title   — "Artist - Title" or "Artist – Title"
 *   title-artist   — "Title - Artist"
 *   auto           — try to detect
 */

declare(strict_types=1);

return [

    // Live 105 / KITS — San Francisco
    [
        'slug'        => 'live105-1991',
        'source_name' => 'Live 105 / KITS',
        'source_slug' => 'live105',
        'source_type' => 'radio_countdown',
        'station'     => 'KITS',
        'market'      => 'San Francisco',
        'list_title'  => 'Live 105 Top 105.3 Songs of 1991',
        'list_slug'   => 'live105-1991',
        'list_type'   => 'year_end',
        'year'        => 1991,
        'decade'      => 1990,
        'genre_hint'  => 'Alternative',
        'url'         => 'https://rocklists.com/lists/1991/live105-top1053-songs-of-1991/',
        'default_tags' => ['live105-classic', 'bay-area-nostalgia', 'alternative', '1990s'],
        'parser'      => ['type' => 'ranked_html', 'format' => 'artist-title'],
    ],

    [
        'slug'        => 'live105-1993',
        'source_name' => 'Live 105 / KITS',
        'source_slug' => 'live105',
        'source_type' => 'radio_countdown',
        'station'     => 'KITS',
        'market'      => 'San Francisco',
        'list_title'  => 'Live 105 Top 105.3 Songs of 1993',
        'list_slug'   => 'live105-1993',
        'list_type'   => 'year_end',
        'year'        => 1993,
        'decade'      => 1990,
        'genre_hint'  => 'Alternative',
        'url'         => 'https://rocklists.com/lists/1993/live105-top1053-songs-of-1993/',
        'default_tags' => ['live105-classic', 'bay-area-nostalgia', 'alternative', '1990s'],
        'parser'      => ['type' => 'ranked_html', 'format' => 'artist-title'],
    ],

    [
        'slug'        => 'live105-1995',
        'source_name' => 'Live 105 / KITS',
        'source_slug' => 'live105',
        'source_type' => 'radio_countdown',
        'station'     => 'KITS',
        'market'      => 'San Francisco',
        'list_title'  => 'Live 105 Top 105.3 Songs of 1995',
        'list_slug'   => 'live105-1995',
        'list_type'   => 'year_end',
        'year'        => 1995,
        'decade'      => 1990,
        'genre_hint'  => 'Alternative',
        'url'         => 'https://rocklists.com/lists/1995/live105-top1053-songs-of-1995/',
        'default_tags' => ['live105-classic', 'bay-area-nostalgia', 'alternative', '1990s'],
        'parser'      => ['type' => 'ranked_html', 'format' => 'artist-title'],
    ],

    // KROQ — Los Angeles
    [
        'slug'        => 'kroq-1992',
        'source_name' => 'KROQ',
        'source_slug' => 'kroq',
        'source_type' => 'radio_countdown',
        'station'     => 'KROQ',
        'market'      => 'Los Angeles',
        'list_title'  => 'KROQ Top 106.7 Songs of 1992',
        'list_slug'   => 'kroq-1992',
        'list_type'   => 'year_end',
        'year'        => 1992,
        'decade'      => 1990,
        'genre_hint'  => 'Alternative',
        'url'         => 'https://rocklists.com/lists/1992/kroq-top-1067-songs-of-1992/',
        'default_tags' => ['alternative', '1990s'],
        'parser'      => ['type' => 'ranked_html', 'format' => 'artist-title'],
    ],

    // Q101 — Chicago
    [
        'slug'        => 'q101-1994',
        'source_name' => 'Q101',
        'source_slug' => 'q101',
        'source_type' => 'radio_countdown',
        'station'     => 'Q101',
        'market'      => 'Chicago',
        'list_title'  => 'Q101 Top 101 Songs of 1994',
        'list_slug'   => 'q101-1994',
        'list_type'   => 'year_end',
        'year'        => 1994,
        'decade'      => 1990,
        'genre_hint'  => 'Alternative',
        'url'         => 'https://rocklists.com/lists/1994/q101-top-101-songs-of-1994/',
        'default_tags' => ['alternative', '1990s'],
        'parser'      => ['type' => 'ranked_html', 'format' => 'artist-title'],
    ],
];
