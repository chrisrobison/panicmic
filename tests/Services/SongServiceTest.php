<?php

declare(strict_types=1);

namespace NextUp\Tests\Services;

use NextUp\Services\SongService;
use NextUp\Tests\Support\DatabaseTestCase;

final class SongServiceTest extends DatabaseTestCase
{
    public function testCreateAndFindRoundTrip(): void
    {
        $id = SongService::create($this->tenantDb, [
            'title' => 'Tennessee Whiskey',
            'artist' => 'Chris Stapleton',
            'genre' => 'Country',
            'decade' => 2010,
            'popularity' => 90,
        ]);
        $row = SongService::find($this->tenantDb, $id);
        self::assertNotNull($row);
        self::assertSame('Tennessee Whiskey', $row['title']);
        self::assertSame('Chris Stapleton', $row['artist']);
    }

    public function testSearchByQueryMatchesTitle(): void
    {
        SongService::create($this->tenantDb, ['title' => 'Whiskey Lullaby', 'artist' => 'Brad Paisley']);
        SongService::create($this->tenantDb, ['title' => 'Friends in Low Places', 'artist' => 'Garth Brooks']);

        $result = SongService::search($this->tenantDb, ['query' => 'whiskey']);
        self::assertSame(1, $result['total']);
        self::assertSame('Whiskey Lullaby', $result['songs'][0]['title']);
    }

    public function testSearchByGenreFilters(): void
    {
        SongService::create($this->tenantDb, ['title' => 'Song A', 'artist' => 'X', 'genre' => 'Rock']);
        SongService::create($this->tenantDb, ['title' => 'Song B', 'artist' => 'Y', 'genre' => 'Country']);

        $result = SongService::search($this->tenantDb, ['genre' => 'Country']);
        self::assertSame(1, $result['total']);
        self::assertSame('Song B', $result['songs'][0]['title']);
    }

    public function testSearchPaginates(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            SongService::create($this->tenantDb, ['title' => "Song {$i}", 'artist' => 'Artist ' . str_pad((string)$i, 2, '0', STR_PAD_LEFT)]);
        }
        $page1 = SongService::search($this->tenantDb, ['page' => 1, 'size' => 10]);
        $page2 = SongService::search($this->tenantDb, ['page' => 2, 'size' => 10]);
        self::assertSame(25, $page1['total']);
        self::assertCount(10, $page1['songs']);
        self::assertCount(10, $page2['songs']);
        self::assertNotSame($page1['songs'][0]['id'], $page2['songs'][0]['id']);
    }

    public function testFindManyReturnsKeyedByIdAndIgnoresMissing(): void
    {
        $a = SongService::create($this->tenantDb, ['title' => 'A', 'artist' => 'X']);
        $b = SongService::create($this->tenantDb, ['title' => 'B', 'artist' => 'Y']);
        $found = SongService::findMany($this->tenantDb, [$a, $b, 99999]);
        self::assertCount(2, $found);
        self::assertArrayHasKey($a, $found);
        self::assertArrayHasKey($b, $found);
    }
}
