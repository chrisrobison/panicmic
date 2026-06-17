<?php

/**
 * Manual CSV / JSON sources for the curated catalog.
 *
 * Each entry describes a manually curated list file that ships with
 * the repo under data/catalog/manual/.
 */

declare(strict_types=1);

return [

    [
        'slug'        => 'manual-karaoke-staples',
        'source_name' => 'KJ Manual Curation',
        'source_slug' => 'manual-kj',
        'source_type' => 'manual',
        'station'     => null,
        'market'      => null,
        'list_title'  => 'Karaoke Staples',
        'list_slug'   => 'manual-karaoke-staples',
        'list_type'   => 'manual',
        'year'        => null,
        'decade'      => null,
        'genre_hint'  => null,
        'file'        => 'data/catalog/manual/karaoke-staples.csv',
        'default_tags' => ['crowd-favorite', 'bar-singalong'],
        'parser'      => ['type' => 'csv', 'delimiter' => ';'],
    ],

    [
        'slug'        => 'manual-beginner-friendly',
        'source_name' => 'KJ Manual Curation',
        'source_slug' => 'manual-kj',
        'source_type' => 'manual',
        'station'     => null,
        'market'      => null,
        'list_title'  => 'Beginner Friendly Starter Pack',
        'list_slug'   => 'manual-beginner-friendly',
        'list_type'   => 'manual',
        'year'        => null,
        'decade'      => null,
        'genre_hint'  => null,
        'file'        => 'data/catalog/manual/beginner-friendly.csv',
        'default_tags' => ['beginner-friendly', 'easy-chorus'],
        'parser'      => ['type' => 'csv', 'delimiter' => ';'],
    ],

    [
        'slug'        => 'manual-power-ballads',
        'source_name' => 'KJ Manual Curation',
        'source_slug' => 'manual-kj',
        'source_type' => 'manual',
        'station'     => null,
        'market'      => null,
        'list_title'  => 'Power Ballads',
        'list_slug'   => 'manual-power-ballads',
        'list_type'   => 'manual',
        'year'        => null,
        'decade'      => null,
        'genre_hint'  => 'Rock',
        'file'        => 'data/catalog/manual/power-ballads.csv',
        'default_tags' => ['power-ballad', 'rock'],
        'parser'      => ['type' => 'csv', 'delimiter' => ';'],
    ],

    [
        'slug'        => 'manual-duets',
        'source_name' => 'KJ Manual Curation',
        'source_slug' => 'manual-kj',
        'source_type' => 'manual',
        'station'     => null,
        'market'      => null,
        'list_title'  => 'Duets',
        'list_slug'   => 'manual-duets',
        'list_type'   => 'manual',
        'year'        => null,
        'decade'      => null,
        'genre_hint'  => null,
        'file'        => 'data/catalog/manual/duets.csv',
        'default_tags' => ['duet'],
        'parser'      => ['type' => 'csv', 'delimiter' => ';'],
    ],

    [
        'slug'        => 'manual-guilty-pleasures',
        'source_name' => 'KJ Manual Curation',
        'source_slug' => 'manual-kj',
        'source_type' => 'manual',
        'station'     => null,
        'market'      => null,
        'list_title'  => 'Guilty Pleasures',
        'list_slug'   => 'manual-guilty-pleasures',
        'list_type'   => 'manual',
        'year'        => null,
        'decade'      => null,
        'genre_hint'  => null,
        'file'        => 'data/catalog/manual/guilty-pleasures.csv',
        'default_tags' => ['guilty-pleasure'],
        'parser'      => ['type' => 'csv', 'delimiter' => ';'],
    ],

    [
        'slug'        => 'manual-live105-classics',
        'source_name' => 'KJ Manual Curation',
        'source_slug' => 'manual-kj',
        'source_type' => 'manual',
        'station'     => 'KITS',
        'market'      => 'San Francisco',
        'list_title'  => 'Live 105 Classics (Manual)',
        'list_slug'   => 'manual-live105-classics',
        'list_type'   => 'manual',
        'year'        => null,
        'decade'      => null,
        'genre_hint'  => 'Alternative',
        'file'        => 'data/catalog/manual/live105-classics.csv',
        'default_tags' => ['live105-classic', 'bay-area-nostalgia', 'alternative'],
        'parser'      => ['type' => 'csv', 'delimiter' => ';'],
    ],
];
