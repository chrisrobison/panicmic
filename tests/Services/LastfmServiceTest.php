<?php

declare(strict_types=1);

namespace PanicMic\Tests\Services;

use PanicMic\Services\LastfmService;
use PHPUnit\Framework\TestCase;

/**
 * Pure parser tests for the Last.fm track.getInfo response — no network.
 */
final class LastfmServiceTest extends TestCase
{
    private function sample(): array
    {
        return [
            'track' => [
                'name' => 'Africa',
                'mbid' => 'track-mbid-123',
                'url' => 'https://www.last.fm/music/Toto/_/Africa',
                'listeners' => '1500000',
                'playcount' => '9000000',
                'artist' => ['name' => 'Toto'],
                'album' => [
                    'artist' => 'Toto',
                    'title' => 'Toto IV',
                    'mbid' => 'album-mbid',
                    'image' => [
                        ['#text' => 'https://lastfm.example/34s/a.png', 'size' => 'small'],
                        ['#text' => 'https://lastfm.example/64s/a.png', 'size' => 'medium'],
                        ['#text' => 'https://lastfm.example/174s/a.png', 'size' => 'large'],
                        ['#text' => 'https://lastfm.example/300x300/a.png', 'size' => 'extralarge'],
                        ['#text' => '', 'size' => 'mega'],
                    ],
                ],
                'toptags' => ['tag' => [
                    ['name' => '80s'],
                    ['name' => 'rock'],
                    ['name' => 'pop'],
                ]],
            ],
        ];
    }

    public function testPicksLargestNonEmptyImage(): void
    {
        $info = LastfmService::parseTrackInfo($this->sample());
        self::assertNotNull($info);
        // mega is empty, so extralarge is the largest usable image.
        self::assertSame('https://lastfm.example/300x300/a.png', $info['album_art_url']);
    }

    public function testExtractsAlbumTagsAndPopularity(): void
    {
        $info = LastfmService::parseTrackInfo($this->sample());
        self::assertSame('Toto IV', $info['album']);
        self::assertSame('track-mbid-123', $info['mbid']);
        self::assertSame('https://www.last.fm/music/Toto/_/Africa', $info['lastfm_url']);
        self::assertSame(1500000, $info['listeners']);
        self::assertSame(9000000, $info['playcount']);
        self::assertSame(['80s', 'rock', 'pop'], $info['tags']);
        self::assertSame('80s', $info['genre']); // first tag fills genre
    }

    public function testFiltersPlaceholderStarImage(): void
    {
        $data = $this->sample();
        $data['track']['album']['image'] = [
            ['#text' => 'https://lastfm.example/i/u/300x300/2a96cbd8b46e442fc41c2b86b821562f.png', 'size' => 'extralarge'],
        ];
        $info = LastfmService::parseTrackInfo($data);
        self::assertNull($info['album_art_url'], 'The known placeholder star must be treated as no image');
    }

    public function testReturnsNullOnApiError(): void
    {
        self::assertNull(LastfmService::parseTrackInfo(['error' => 6, 'message' => 'Track not found']));
        self::assertNull(LastfmService::parseTrackInfo([]));
    }

    public function testHandlesTrackWithoutAlbum(): void
    {
        $info = LastfmService::parseTrackInfo([
            'track' => ['name' => 'Demo', 'listeners' => '10', 'toptags' => ['tag' => []]],
        ]);
        self::assertNotNull($info);
        self::assertNull($info['album']);
        self::assertNull($info['album_art_url']);
        self::assertSame([], $info['tags']);
        self::assertSame(10, $info['listeners']);
    }
}
