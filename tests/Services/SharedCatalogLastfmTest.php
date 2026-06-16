<?php

declare(strict_types=1);

namespace PanicMic\Tests\Services;

use PanicMic\Services\SharedCatalogService;
use PanicMic\Tests\Support\DatabaseTestCase;

final class SharedCatalogLastfmTest extends DatabaseTestCase
{
    private function seedShared(string $title, string $artist, ?string $genre = null): int
    {
        $this->superDb
            ->prepare('INSERT INTO shared_songs (title, artist, genre) VALUES (?, ?, ?)')
            ->execute([$title, $artist, $genre]);
        return (int)$this->superDb->lastInsertId();
    }

    public function testApplyLastfmPersistsAndDecodes(): void
    {
        $id = $this->seedShared('Africa', 'Toto');
        SharedCatalogService::applyLastfm($this->superDb, $id, [
            'album' => 'Toto IV',
            'album_art_url' => 'https://img.example/africa.png',
            'mbid' => 'mbid-1',
            'lastfm_url' => 'https://last.fm/africa',
            'listeners' => 1500000,
            'playcount' => 9000000,
            'tags' => ['80s', 'rock'],
            'genre' => '80s',
        ]);

        $row = SharedCatalogService::find($this->superDb, $id);
        self::assertSame('Toto IV', $row['album']);
        self::assertSame('https://img.example/africa.png', $row['album_art_url']);
        self::assertSame(1500000, (int)$row['listeners']);
        self::assertSame(['80s', 'rock'], $row['tags']); // decoded from JSON
        self::assertNotNull($row['lastfm_enriched_at']);
    }

    public function testApplyLastfmDoesNotOverwriteExistingGenre(): void
    {
        $id = $this->seedShared('Song', 'Artist', 'Curated Pop');
        SharedCatalogService::applyLastfm($this->superDb, $id, ['genre' => 'rock', 'tags' => ['rock']]);

        $row = SharedCatalogService::find($this->superDb, $id);
        self::assertSame('Curated Pop', $row['genre'], 'Existing curated genre must win');
    }

    public function testApplyLastfmStampsEnrichedEvenOnEmptyInfo(): void
    {
        $id = $this->seedShared('Unknown', 'Nobody');
        SharedCatalogService::applyLastfm($this->superDb, $id, []);

        $row = SharedCatalogService::find($this->superDb, $id);
        self::assertNotNull($row['lastfm_enriched_at'], 'A miss must still stamp enriched_at so it is not retried');
        self::assertNull($row['album_art_url']);
    }
}
