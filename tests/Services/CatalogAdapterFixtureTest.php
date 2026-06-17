<?php

declare(strict_types=1);

namespace PanicMic\Tests\Services;

use PHPUnit\Framework\TestCase;
use PanicMic\Services\CatalogImport\GenericHtmlListAdapter;
use PanicMic\Services\CatalogImport\LastfmTopTracksAdapter;
use PanicMic\Services\CatalogImport\RocklistsHtmlAdapter;

/**
 * Tests catalog adapters against fixture files.
 * No network access, no DB required.
 */
final class CatalogAdapterFixtureTest extends TestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        $this->fixtureDir = dirname(__DIR__) . '/fixtures/catalog';
    }

    // ------------------------------------------------------------------
    // Last.fm top tracks adapter fixture
    // ------------------------------------------------------------------

    public function testLastfmAdapterParsesFixture(): void
    {
        $adapter = new LastfmTopTracksAdapter([], '/tmp/cache', false);
        $raw = (string)file_get_contents($this->fixtureDir . '/lastfm-top-tracks-sample.json');
        $list = [
            'list_title'  => 'Last.fm Alternative',
            'list_slug'   => 'lastfm-alternative',
            'genre_hint'  => 'Alternative',
            'source_name' => 'Last.fm',
            'source_slug' => 'lastfm',
        ];
        $rows = $adapter->parse($raw, $list);
        self::assertCount(3, $rows);
        self::assertSame('Nirvana', $rows[0]['artist']);
        self::assertSame('Smells Like Teen Spirit', $rows[0]['title']);
        self::assertSame(1, $rows[0]['rank']);
        self::assertSame(2500000, $rows[0]['listeners']);
        self::assertSame('abcdef-1234', $rows[0]['mbid']);
        self::assertSame('Radiohead', $rows[1]['artist']);
        self::assertSame('Creep',     $rows[1]['title']);
    }

    public function testLastfmAdapterSkipsEmptyRows(): void
    {
        $adapter = new LastfmTopTracksAdapter([], '/tmp/cache', false);
        $raw = json_encode([
            ['name' => 'Track', 'artist' => ['name' => 'Artist'], 'listeners' => 100],
            ['name' => '',       'artist' => ['name' => 'Missing Title']],
            ['name' => 'No Artist', 'artist' => ['name' => '']],
        ]);
        $rows = $adapter->parse($raw, [
            'list_title' => 'Test', 'list_slug' => 'test', 'source_name' => 'Test', 'source_slug' => 'test',
        ]);
        self::assertCount(1, $rows);
        self::assertSame('Track', $rows[0]['title']);
    }

    // ------------------------------------------------------------------
    // Rocklists HTML adapter fixture (using GenericHtmlListAdapter internally)
    // ------------------------------------------------------------------

    public function testRocklistsAdapterParsesHtmlFixture(): void
    {
        $html = (string)file_get_contents($this->fixtureDir . '/rocklists-sample.html');
        $list = [
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
            'url'         => 'https://example.com/live105-1991',
            'parser'      => ['type' => 'ranked_html', 'format' => 'artist-title'],
        ];

        $adapter = new RocklistsHtmlAdapter([$list], '/tmp/cache', false);
        $rows = $adapter->parse($html, $list);

        self::assertGreaterThanOrEqual(5, count($rows));
        self::assertSame('Nirvana', $rows[0]['artist']);
        self::assertSame('Smells Like Teen Spirit', $rows[0]['title']);
        self::assertSame(1, $rows[0]['rank']);
        self::assertSame('Live 105 / KITS', $rows[0]['source_name']);
        self::assertSame('KITS', $rows[0]['station']);
        self::assertSame(1991, $rows[0]['year']);
    }

    public function testGenericHtmlParserExtractsOlItems(): void
    {
        $html = file_get_contents($this->fixtureDir . '/rocklists-sample.html');
        $list = ['parser' => ['format' => 'artist-title']];
        $rows = GenericHtmlListAdapter::parseOlItems($html, $list);
        self::assertCount(10, $rows);
        self::assertSame('Nirvana', $rows[0]['artist']);
        self::assertSame(1, $rows[0]['rank']);
        self::assertSame('R.E.M.', $rows[1]['artist']);
    }

    public function testGenericHtmlParserFallsBackToText(): void
    {
        $text = "1. Nirvana - Smells Like Teen Spirit\n2. Radiohead - Creep\n";
        $list = ['parser' => ['format' => 'artist-title']];
        $rows = GenericHtmlListAdapter::parseText($text, $list);
        self::assertCount(2, $rows);
        self::assertSame('Nirvana', $rows[0]['artist']);
    }
}
