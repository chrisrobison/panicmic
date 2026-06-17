<?php

declare(strict_types=1);

namespace PanicMic\Tests\Services;

use PHPUnit\Framework\TestCase;
use PanicMic\Services\CatalogImport\GenericHtmlListAdapter;
use PanicMic\Services\CatalogImport\ManualCsvAdapter;

/**
 * Unit tests for catalog import adapter parsers.
 * Does NOT require a database connection.
 */
final class CatalogImportServiceTest extends TestCase
{
    // -----------------------------------------------------------------------
    // ManualCsvAdapter::parse()
    // -----------------------------------------------------------------------

    private function csvAdapter(): ManualCsvAdapter
    {
        return new ManualCsvAdapter([], '/tmp');
    }

    private function parseOneCsv(string $csv, array $list = []): array
    {
        return $this->csvAdapter()->parse($csv, $list + ['parser' => ['delimiter' => ';']]);
    }

    public function testParsesBasicCsv(): void
    {
        $csv = "Title;Artist;Year;Genre\nAfrica;Toto;1982;Rock\n";
        $rows = $this->parseOneCsv($csv);
        self::assertCount(1, $rows);
        self::assertSame('Africa', $rows[0]['title']);
        self::assertSame('Toto',   $rows[0]['artist']);
        self::assertSame(1982,     $rows[0]['year']);
        self::assertSame('Rock',   $rows[0]['genre_hint']);
    }

    public function testSkipsEmptyArtistOrTitle(): void
    {
        $csv = "Title;Artist\n;Toto\nAfrica;\nOk;Artist\n";
        $rows = $this->parseOneCsv($csv);
        self::assertCount(1, $rows);
        self::assertSame('Ok', $rows[0]['title']);
    }

    public function testDuoFlag(): void
    {
        $csv = "Title;Artist;Duo\nIslands in the Stream;Rogers & Parton;1\n";
        $rows = $this->parseOneCsv($csv);
        self::assertTrue($rows[0]['duo']);
    }

    public function testExplicitFlag(): void
    {
        $csv = "Title;Artist;Explicit\nSong;Artist;yes\n";
        $rows = $this->parseOneCsv($csv);
        self::assertTrue($rows[0]['explicit']);
    }

    public function testStylesAndLanguages(): void
    {
        $csv = "Title;Artist;Styles;Languages\nSong;Artist;Rock,Pop;English,Spanish\n";
        $rows = $this->parseOneCsv($csv);
        self::assertSame(['Rock', 'Pop'],       $rows[0]['styles']);
        self::assertSame(['English', 'Spanish'], $rows[0]['languages']);
    }

    public function testTagsColumn(): void
    {
        $csv = "Title;Artist;Tags\nSong;Artist;crowd-favorite,bar-singalong\n";
        $rows = $this->parseOneCsv($csv);
        self::assertSame(['crowd-favorite', 'bar-singalong'], $rows[0]['import_tags']);
    }

    public function testRankFromColumn(): void
    {
        $csv = "Title;Artist;Rank\nSong;Artist;42\n";
        $rows = $this->parseOneCsv($csv);
        self::assertSame(42, $rows[0]['rank']);
    }

    public function testAutoRankFromRowOrder(): void
    {
        $csv = "Title;Artist\nFirst;Artist\nSecond;Artist\nThird;Artist\n";
        $rows = $this->parseOneCsv($csv);
        self::assertSame(1, $rows[0]['rank']);
        self::assertSame(2, $rows[1]['rank']);
        self::assertSame(3, $rows[2]['rank']);
    }

    public function testInheritListMetadata(): void
    {
        $csv = "Title;Artist\nSong;Artist\n";
        $list = [
            'source_name' => 'Live 105',
            'source_slug' => 'live105',
            'source_type' => 'radio_countdown',
            'list_title'  => 'Live 105 Top 105',
            'list_slug'   => 'live105-1991',
            'list_type'   => 'year_end',
            'year'        => 1991,
            'decade'      => 1990,
            'genre_hint'  => 'Alternative',
            'station'     => 'KITS',
            'market'      => 'San Francisco',
        ];
        $rows = $this->parseOneCsv($csv, $list);
        self::assertSame('Live 105',   $rows[0]['source_name']);
        self::assertSame('live105',    $rows[0]['source_slug']);
        self::assertSame(1991,         $rows[0]['year']);
        self::assertSame('KITS',       $rows[0]['station']);
        self::assertSame('Alternative',$rows[0]['genre_hint']);
    }

    public function testEmptyCsvReturnsEmpty(): void
    {
        self::assertSame([], $this->parseOneCsv(''));
        self::assertSame([], $this->parseOneCsv("   \n  "));
    }

    // -----------------------------------------------------------------------
    // GenericHtmlListAdapter — ranked text parsing
    // -----------------------------------------------------------------------

    public function testParseTextNumberedDotFormat(): void
    {
        $text = "1. Nirvana - Smells Like Teen Spirit\n2. R.E.M. - Losing My Religion\n";
        $rows = GenericHtmlListAdapter::parseText($text, ['parser' => ['format' => 'artist-title']]);
        self::assertCount(2, $rows);
        self::assertSame('Nirvana', $rows[0]['artist']);
        self::assertSame('Smells Like Teen Spirit', $rows[0]['title']);
        self::assertSame(1, $rows[0]['rank']);
        self::assertSame('R.E.M.', $rows[1]['artist']);
        self::assertSame(2, $rows[1]['rank']);
    }

    public function testParseTextNumberedParenFormat(): void
    {
        $text = "1) Nirvana - Smells Like Teen Spirit\n2) Pearl Jam - Black\n";
        $rows = GenericHtmlListAdapter::parseText($text, ['parser' => ['format' => 'artist-title']]);
        self::assertCount(2, $rows);
        self::assertSame('Nirvana', $rows[0]['artist']);
        self::assertSame('Pearl Jam', $rows[1]['artist']);
    }

    public function testParseTextEnDashSeparator(): void
    {
        $text = "1. Nirvana \u{2013} Smells Like Teen Spirit\n";
        $rows = GenericHtmlListAdapter::parseText($text, ['parser' => ['format' => 'artist-title']]);
        self::assertCount(1, $rows);
        self::assertSame('Nirvana', $rows[0]['artist']);
        self::assertSame('Smells Like Teen Spirit', $rows[0]['title']);
    }

    public function testParseTextTitleArtistFormat(): void
    {
        // format='title-artist' means the input line has "Title - Artist" ordering
        // So the right part ('Nirvana') becomes artist, left part becomes title
        $text = "1. Smells Like Teen Spirit - Nirvana\n";
        $rows = GenericHtmlListAdapter::parseText($text, ['parser' => ['format' => 'title-artist']]);
        self::assertCount(1, $rows);
        self::assertSame('Nirvana', $rows[0]['artist']);
        self::assertSame('Smells Like Teen Spirit', $rows[0]['title']);
    }

    public function testParseHtmlOlItems(): void
    {
        $html = '<ol><li>Nirvana - Smells Like Teen Spirit</li><li>R.E.M. - Losing My Religion</li></ol>';
        $rows = GenericHtmlListAdapter::parseOlItems($html, ['parser' => ['format' => 'artist-title']]);
        self::assertCount(2, $rows);
        self::assertSame('Nirvana', $rows[0]['artist']);
        self::assertSame(1, $rows[0]['rank']);
    }

    public function testHtmlToText(): void
    {
        $html = '<p>Hello <strong>World</strong></p>';
        self::assertSame('Hello World', GenericHtmlListAdapter::htmlToText($html));
    }

    public function testParseArtistTitleBasic(): void
    {
        $result = GenericHtmlListAdapter::parseArtistTitle('Nirvana - Smells Like Teen Spirit');
        self::assertNotNull($result);
        self::assertSame('Nirvana', $result['artist']);
        self::assertSame('Smells Like Teen Spirit', $result['title']);
    }

    public function testParseArtistTitleQuotedTitle(): void
    {
        $result = GenericHtmlListAdapter::parseArtistTitle('Nirvana "Smells Like Teen Spirit"');
        self::assertNotNull($result);
        self::assertSame('Nirvana', $result['artist']);
        self::assertSame('Smells Like Teen Spirit', $result['title']);
    }

    public function testParseArtistTitleReturnsNullForBlankLine(): void
    {
        self::assertNull(GenericHtmlListAdapter::parseArtistTitle(''));
        self::assertNull(GenericHtmlListAdapter::parseArtistTitle('no separator here'));
    }
}
