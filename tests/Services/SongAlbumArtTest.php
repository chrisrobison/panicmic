<?php

declare(strict_types=1);

namespace PanicMic\Tests\Services;

use PanicMic\Services\QueueService;
use PanicMic\Services\SongService;
use PanicMic\Tests\Support\DatabaseTestCase;

final class SongAlbumArtTest extends DatabaseTestCase
{
    public function testCreateAndUpdateRoundTripAlbumArt(): void
    {
        $id = SongService::create($this->tenantDb, [
            'title' => 'Mr. Brightside',
            'artist' => 'The Killers',
            'album' => 'Hot Fuss',
            'album_art_url' => 'https://img.example/hotfuss.png',
        ]);

        $row = SongService::find($this->tenantDb, $id);
        self::assertSame('Hot Fuss', $row['album']);
        self::assertSame('https://img.example/hotfuss.png', $row['album_art_url']);

        SongService::update($this->tenantDb, $id, [
            'title' => 'Mr. Brightside',
            'artist' => 'The Killers',
            'album' => 'Hot Fuss (Deluxe)',
            'album_art_url' => 'https://img.example/deluxe.png',
        ]);
        $row = SongService::find($this->tenantDb, $id);
        self::assertSame('Hot Fuss (Deluxe)', $row['album']);
        self::assertSame('https://img.example/deluxe.png', $row['album_art_url']);
    }

    public function testQueueExposesAlbumArtForLocalSong(): void
    {
        $songId = SongService::create($this->tenantDb, [
            'title' => 'Vogue',
            'artist' => 'Madonna',
            'album_art_url' => 'https://img.example/vogue.png',
        ]);
        QueueService::submit($this->tenantDb, $this->sessionId, [
            'song_id' => $songId,
            'display_name' => 'Aisha',
        ], 'tok-art', false);

        $queue = QueueService::queue($this->tenantDb, $this->sessionId, $this->superDb);
        self::assertNotEmpty($queue);
        self::assertSame('https://img.example/vogue.png', $queue[0]['album_art_url']);
        // local_* temporaries must not leak into the payload.
        self::assertArrayNotHasKey('local_album_art_url', $queue[0]);
    }
}
