<?php

declare(strict_types=1);

namespace PanicMic\Tests\Services;

use PanicMic\Services\CatalogTaggingService;
use PanicMic\Tests\Support\DatabaseTestCase;

final class CatalogTaggingServiceTest extends DatabaseTestCase
{
    private function seedSharedSong(): int
    {
        $this->superDb->prepare(
            'INSERT INTO shared_songs (title, artist) VALUES (?, ?)'
        )->execute(['Test Song', 'Test Artist']);
        return (int)$this->superDb->lastInsertId();
    }

    private function hasTag(string $table): bool
    {
        return (bool)$this->superDb->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = '{$table}'"
        )->fetchColumn();
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Skip if curated tables don't exist yet
        if (!$this->hasTag('shared_song_tags')) {
            self::markTestSkipped('Curated catalog migration 009 not applied');
        }
        // Clean curated tables
        $this->superDb->exec('DELETE FROM shared_song_tag_links');
        $this->superDb->exec('DELETE FROM shared_song_tags');
    }

    private function seedTag(string $name, string $slug, string $type = 'editorial'): int
    {
        $this->superDb->prepare(
            'INSERT INTO shared_song_tags (name, slug, tag_type) VALUES (?, ?, ?)'
        )->execute([$name, $slug, $type]);
        return (int)$this->superDb->lastInsertId();
    }

    public function testApplyTagById(): void
    {
        $songId = $this->seedSharedSong();
        $tagId  = $this->seedTag('Crowd Favorite', 'crowd-favorite', 'occasion');

        CatalogTaggingService::applyTag($this->superDb, $songId, $tagId, 90, 'manual');

        $tags = CatalogTaggingService::tagsForSong($this->superDb, $songId);
        self::assertCount(1, $tags);
        self::assertSame('crowd-favorite', $tags[0]['slug']);
        self::assertSame(90, (int)$tags[0]['confidence']);
        self::assertSame('manual', $tags[0]['source']);
    }

    public function testApplyTagSlugs(): void
    {
        $songId = $this->seedSharedSong();
        $this->seedTag('Pop',       'pop',  'genre');
        $this->seedTag('1990s',     '1990s','era');
        $this->seedTag('Deep Cut',  'deep-cut', 'editorial');

        CatalogTaggingService::applyTagSlugs($this->superDb, $songId, ['pop', '1990s', 'deep-cut'], 80, 'import');

        $tags = CatalogTaggingService::tagsForSong($this->superDb, $songId);
        self::assertCount(3, $tags);
        $slugs = array_column($tags, 'slug');
        self::assertContains('pop', $slugs);
        self::assertContains('1990s', $slugs);
        self::assertContains('deep-cut', $slugs);
    }

    public function testApplyTagUpdatesConfidence(): void
    {
        $songId = $this->seedSharedSong();
        $tagId  = $this->seedTag('Rock', 'rock', 'genre');

        CatalogTaggingService::applyTag($this->superDb, $songId, $tagId, 50, 'rule');
        CatalogTaggingService::applyTag($this->superDb, $songId, $tagId, 90, 'admin');

        $tags = CatalogTaggingService::tagsForSong($this->superDb, $songId);
        self::assertCount(1, $tags);
        self::assertSame(90, (int)$tags[0]['confidence'], 'Higher confidence should win');
    }

    public function testRemoveTag(): void
    {
        $songId = $this->seedSharedSong();
        $tagId  = $this->seedTag('Duet', 'duet', 'occasion');
        CatalogTaggingService::applyTag($this->superDb, $songId, $tagId, 100, 'manual');
        self::assertCount(1, CatalogTaggingService::tagsForSong($this->superDb, $songId));

        CatalogTaggingService::removeTag($this->superDb, $songId, $tagId);
        self::assertCount(0, CatalogTaggingService::tagsForSong($this->superDb, $songId));
    }

    public function testTagIds(): void
    {
        $this->seedTag('Pop',  'pop',  'genre');
        $this->seedTag('Rock', 'rock', 'genre');
        $ids = CatalogTaggingService::tagIds($this->superDb, ['pop', 'rock', 'unknown-slug']);
        self::assertCount(2, $ids);
        self::assertArrayHasKey('pop',  $ids);
        self::assertArrayHasKey('rock', $ids);
        self::assertArrayNotHasKey('unknown-slug', $ids);
    }

    public function testApplyRulesWithDuoFlag(): void
    {
        $songId = $this->seedSharedSong();
        $this->seedTag('Duet', 'duet', 'occasion');
        $rulesFile = dirname(__DIR__, 2) . '/config/catalog-tag-rules.php';
        if (!is_readable($rulesFile)) {
            self::markTestSkipped('catalog-tag-rules.php not found');
        }

        $song      = ['id' => $songId, 'duo' => 1, 'genre' => null, 'decade' => null, 'source_count' => 0];
        $candidate = ['duo' => 1, 'station' => null, 'market' => null, 'genre_hint' => null,
                      'decade' => null, 'source_slug' => 'manual'];

        CatalogTaggingService::applyRules($this->superDb, $song, $candidate, $rulesFile);

        $tags = CatalogTaggingService::tagsForSong($this->superDb, $songId);
        $slugs = array_column($tags, 'slug');
        self::assertContains('duet', $slugs, 'Duo flag should produce duet tag');
    }
}
