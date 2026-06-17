<?php

declare(strict_types=1);

namespace PanicMic\Tests\Services;

use PHPUnit\Framework\TestCase;
use PanicMic\Services\CatalogScoringService;

final class CatalogScoringServiceTest extends TestCase
{
    private array $config;

    protected function setUp(): void
    {
        $path = dirname(__DIR__, 2) . '/config/catalog-scoring.php';
        $this->config = is_readable($path) ? (require $path) : [];
    }

    public function testSourceScoreZeroListsIsZero(): void
    {
        self::assertSame(0, CatalogScoringService::calcSourceScore($this->config, 0, 0, null));
    }

    public function testSourceScoreIncreasesWithLists(): void
    {
        $one  = CatalogScoringService::calcSourceScore($this->config, 1, 1, null);
        $five = CatalogScoringService::calcSourceScore($this->config, 5, 2, null);
        self::assertGreaterThan(0, $one);
        self::assertGreaterThan($one, $five);
    }

    public function testSourceScoreCapAt100(): void
    {
        self::assertLessThanOrEqual(100, CatalogScoringService::calcSourceScore($this->config, 100, 50, 1));
    }

    public function testSourceScoreTop10Bonus(): void
    {
        $noBonus  = CatalogScoringService::calcSourceScore($this->config, 1, 1, null);
        $top10    = CatalogScoringService::calcSourceScore($this->config, 1, 1, 5);
        self::assertGreaterThan($noBonus, $top10);
    }

    public function testSourceScoreRank1Bonus(): void
    {
        $rank10 = CatalogScoringService::calcSourceScore($this->config, 1, 1, 10);
        $rank1  = CatalogScoringService::calcSourceScore($this->config, 1, 1, 1);
        self::assertGreaterThan($rank10, $rank1);
    }

    public function testNostalgiaScoreWithPeakEra(): void
    {
        $base = CatalogScoringService::calcNostalgiaScore($this->config, 1, 2000, false, null);
        $peak = CatalogScoringService::calcNostalgiaScore($this->config, 1, 1990, false, null);
        self::assertGreaterThan($base, $peak);
    }

    public function testNostalgiaScoreLocalBonus(): void
    {
        $noLocal = CatalogScoringService::calcNostalgiaScore($this->config, 1, 1990, false, null);
        $local   = CatalogScoringService::calcNostalgiaScore($this->config, 1, 1990, true,  null);
        self::assertGreaterThan($noLocal, $local);
    }

    public function testSingalongScoreWithTags(): void
    {
        $none = CatalogScoringService::calcSingalongScore($this->config, []);
        $tags = CatalogScoringService::calcSingalongScore($this->config, ['songs-everyone-knows', 'big-chorus', 'bar-singalong']);
        self::assertSame(0, $none);
        self::assertGreaterThan($none, $tags);
        self::assertLessThanOrEqual(100, $tags);
    }

    public function testSingalongScoreHardVocalPenalty(): void
    {
        $easy = CatalogScoringService::calcSingalongScore($this->config, ['easy-chorus']);
        $hard = CatalogScoringService::calcSingalongScore($this->config, ['easy-chorus', 'hard-vocals']);
        self::assertGreaterThan($hard, $easy);
    }

    public function testCrowdScoreWithTags(): void
    {
        $none = CatalogScoringService::calcCrowdScore($this->config, []);
        $tags = CatalogScoringService::calcCrowdScore($this->config, ['crowd-favorite', 'songs-everyone-knows']);
        self::assertSame(0, $none);
        self::assertGreaterThan(0, $tags);
        self::assertLessThanOrEqual(100, $tags);
    }

    public function testKaraokeScoreIsWeightedComposite(): void
    {
        $low  = CatalogScoringService::calcKaraokeScore($this->config, 0, 0, 0, 0, false, false, []);
        $high = CatalogScoringService::calcKaraokeScore($this->config, 80, 70, 90, 85, true, false, []);
        self::assertSame(0, $low);
        self::assertGreaterThan(50, $high);
        self::assertLessThanOrEqual(100, $high);
    }

    public function testKaraokeScoreLocalBonus(): void
    {
        $noLocal = CatalogScoringService::calcKaraokeScore($this->config, 50, 50, 50, 50, false, false, []);
        $local   = CatalogScoringService::calcKaraokeScore($this->config, 50, 50, 50, 50, true,  false, []);
        self::assertGreaterThan($noLocal, $local);
    }
}
