<?php

declare(strict_types=1);

namespace PanicMic\Tests\Services;

use PHPUnit\Framework\TestCase;
use PanicMic\Services\CatalogNormalizeService;

final class CatalogNormalizeServiceTest extends TestCase
{
    /** @dataProvider normalizationProvider */
    public function testNormalize(string $input, string $expected): void
    {
        self::assertSame($expected, CatalogNormalizeService::normalize($input));
    }

    /** @return iterable<array{string,string}> */
    public static function normalizationProvider(): iterable
    {
        yield 'lowercase' => ['Africa', 'africa'];
        yield 'trim whitespace' => ['  africa  ', 'africa'];
        yield 'collapse whitespace' => ["africa  toto", 'africa toto'];
        yield 'smart apostrophe' => ["Don\u{2019}t Stop Believin", "don't stop believin"];
        yield 'smart quotes' => ["\u{201C}Africa\u{201D}", '"africa"'];
        yield 'em dash' => ["Sign \u{2014} Sealed", 'sign - sealed'];
        yield 'ampersand' => ['Simon & Garfunkel', 'simon and garfunkel'];
        yield 'amp entity' => ['Simon &amp; Garfunkel', 'simon and garfunkel'];
        yield 'html entity' => ['R&amp;B', 'r and b'];
        yield 'strip remastered' => ['Africa (Remastered)', 'africa'];
        yield 'strip radio edit' => ['Creep (Radio Edit)', 'creep'];
        yield 'strip album version' => ['One (Album Version)', 'one'];
        yield 'strip explicit' => ['Killing Me Softly (Explicit)', 'killing me softly'];
        yield 'preserve meaningful paren' => [
            "Don't You (Forget About Me)",
            "don't you (forget about me)",
        ];
        yield 'normalize feat' => ['Song feat. Artist', 'song feat. artist'];
        yield 'normalize ft.' => ['Song ft. Artist', 'song feat. artist'];
        yield 'normalize featuring' => ['Song featuring Artist', 'song feat. artist'];
    }

    public function testNormalizeArtistReturnsSameAsNormalize(): void
    {
        self::assertSame(
            CatalogNormalizeService::normalize('The Killers'),
            CatalogNormalizeService::normalizeArtist('The Killers'),
        );
    }

    public function testSlug(): void
    {
        self::assertSame('live-105-1991', CatalogNormalizeService::slug('Live 105 / 1991'));
        self::assertSame('ac-dc', CatalogNormalizeService::slug('AC/DC'));
        self::assertSame('r-b', CatalogNormalizeService::slug('R&B'));
    }

    public function testSimilarity(): void
    {
        self::assertSame(100, CatalogNormalizeService::similarity('africa', 'africa'));
        self::assertGreaterThan(80, CatalogNormalizeService::similarity('africa', 'afric'));
        self::assertLessThan(50, CatalogNormalizeService::similarity('africa', 'bohemian'));
    }

    public function testStripLeadingThe(): void
    {
        self::assertSame('killers', CatalogNormalizeService::stripLeadingThe('the killers'));
        self::assertSame('CURE', CatalogNormalizeService::stripLeadingThe('THE CURE')); // only strips prefix, no casing
        self::assertSame('u2', CatalogNormalizeService::stripLeadingThe('u2'));
        self::assertSame('the', CatalogNormalizeService::stripLeadingThe('the'));
    }

    public function testArtistsMatch(): void
    {
        self::assertTrue(CatalogNormalizeService::artistsMatch('the killers', 'the killers'));
        self::assertTrue(CatalogNormalizeService::artistsMatch('the cure', 'cure'));
        self::assertTrue(CatalogNormalizeService::artistsMatch('the beatles', 'beatles'));
        self::assertFalse(CatalogNormalizeService::artistsMatch('journey', 'toto'));
    }
}
