<?php

declare(strict_types=1);

namespace PanicMic\Tests\Services;

use PanicMic\Services\QueueService;
use PanicMic\Tests\Support\DatabaseTestCase;

/**
 * KJ walk-up entry.
 *
 * The public submit() path enforces rules aimed at the room: an 8/minute
 * rate limit, the requests-paused gate, duplicate-name prevention, and a
 * mandatory catalog match. Routing the KJ's own "add a walk-up" button
 * through it meant the operator inherited all of them — so adding a singer
 * failed exactly when it mattered, and when it did work the request landed
 * in the incoming tray rather than the rotation.
 *
 * These tests pin the walk-up path's distinct behavior.
 */
final class QueueWalkUpTest extends DatabaseTestCase
{
    private function seedSong(string $title = 'Sweet Caroline', string $artist = 'Neil Diamond'): int
    {
        $stmt = $this->tenantDb->prepare('INSERT INTO songs (title, artist) VALUES (?, ?)');
        $stmt->execute([$title, $artist]);
        return (int)$this->tenantDb->lastInsertId();
    }

    public function testWalkUpLandsDirectlyInTheRotationNotTheIncomingTray(): void
    {
        $songId = $this->seedSong();

        $result = QueueService::submitWalkUp($this->tenantDb, $this->sessionId, [
            'display_name' => 'Dana',
            'song_id' => $songId,
        ]);

        $queue = QueueService::queue($this->tenantDb, $this->sessionId);
        $row = null;
        foreach ($queue as $item) {
            if ((int)$item['request_id'] === $result['requestId']) {
                $row = $item;
            }
        }

        self::assertNotNull($row, 'the walk-up must appear in the queue');
        self::assertFalse(
            $row['is_incoming'],
            'a KJ-entered walk-up must not sit in the incoming tray waiting for its own approval',
        );
    }

    public function testWalkUpAcceptsASongThatIsNotInAnyCatalog(): void
    {
        $result = QueueService::submitWalkUp($this->tenantDb, $this->sessionId, [
            'display_name' => 'Sam',
            'custom_song_title' => 'Obscure B-Side',
            'custom_song_artist' => 'The Unlisted',
        ]);

        $queue = QueueService::queue($this->tenantDb, $this->sessionId);
        $row = null;
        foreach ($queue as $item) {
            if ((int)$item['request_id'] === $result['requestId']) {
                $row = $item;
            }
        }

        self::assertNotNull($row);
        self::assertSame('Obscure B-Side', $row['title']);
        self::assertSame('The Unlisted', $row['artist']);
        self::assertSame('walkup', $row['song_source']);
    }

    public function testWalkUpAllowsTheSameSingerTwiceUnlikeThePublicPath(): void
    {
        // prevent_duplicate_requests blocks a repeat name on the public path.
        // The KJ is explicitly overriding that: a duet partner or a genuine
        // second slot is theirs to decide.
        $songId = $this->seedSong();

        QueueService::submitWalkUp($this->tenantDb, $this->sessionId, [
            'display_name' => 'Repeat Singer',
            'song_id' => $songId,
        ]);
        $second = QueueService::submitWalkUp($this->tenantDb, $this->sessionId, [
            'display_name' => 'Repeat Singer',
            'song_id' => $songId,
        ]);

        self::assertGreaterThan(0, $second['requestId']);
        $count = (int)$this->tenantDb
            ->query("SELECT COUNT(*) FROM song_requests WHERE session_id = {$this->sessionId}")
            ->fetchColumn();
        self::assertSame(2, $count);
    }

    public function testRepeatWalkUpReusesTheSameSingerRow(): void
    {
        $songId = $this->seedSong();

        $first = QueueService::submitWalkUp($this->tenantDb, $this->sessionId, [
            'display_name' => 'Same Person',
            'song_id' => $songId,
        ]);
        $second = QueueService::submitWalkUp($this->tenantDb, $this->sessionId, [
            'display_name' => 'Same Person',
            'song_id' => $songId,
        ]);

        self::assertSame(
            $first['singerId'],
            $second['singerId'],
            'one person should not become two singer rows in the same session',
        );
    }

    public function testWalkUpsAppendInOrder(): void
    {
        $songId = $this->seedSong();
        $a = QueueService::submitWalkUp($this->tenantDb, $this->sessionId, ['display_name' => 'First', 'song_id' => $songId]);
        $b = QueueService::submitWalkUp($this->tenantDb, $this->sessionId, ['display_name' => 'Second', 'song_id' => $songId]);

        $positions = [];
        foreach (QueueService::queue($this->tenantDb, $this->sessionId) as $item) {
            $positions[(int)$item['request_id']] = (int)$item['position'];
        }

        self::assertLessThan(
            $positions[$b['requestId']],
            $positions[$a['requestId']],
            'walk-ups must queue behind existing singers, not jump the line',
        );
    }

    public function testCatalogSelectionWinsOverTypedText(): void
    {
        $songId = $this->seedSong('Real Catalog Song', 'Real Artist');

        $result = QueueService::submitWalkUp($this->tenantDb, $this->sessionId, [
            'display_name' => 'Pat',
            'song_id' => $songId,
            'custom_song_title' => 'Ignored Text',
        ]);

        $stmt = $this->tenantDb->prepare('SELECT custom_song_title FROM song_requests WHERE id = ?');
        $stmt->execute([$result['requestId']]);
        self::assertNull($stmt->fetchColumn(), 'a catalog match must not also store free text');
    }

    public function testSingerNameIsRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        QueueService::submitWalkUp($this->tenantDb, $this->sessionId, [
            'display_name' => '   ',
            'custom_song_title' => 'Something',
        ]);
    }

    public function testSomeSongIdentityIsRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        QueueService::submitWalkUp($this->tenantDb, $this->sessionId, ['display_name' => 'Nameless Song']);
    }

    public function testRejectsBothCatalogSourcesAtOnce(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        QueueService::submitWalkUp($this->tenantDb, $this->sessionId, [
            'display_name' => 'Confused',
            'song_id' => $this->seedSong(),
            'shared_song_id' => 1,
        ]);
    }

    public function testRejectsUnknownCatalogSong(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        QueueService::submitWalkUp($this->tenantDb, $this->sessionId, [
            'display_name' => 'Ghost',
            'song_id' => 99999999,
        ]);
    }

    public function testCannotAddAWalkUpWithoutALiveSession(): void
    {
        \PanicMic\Services\SessionService::end($this->tenantDb, $this->sessionId);

        $this->expectException(\InvalidArgumentException::class);
        QueueService::submitWalkUp($this->tenantDb, $this->sessionId, [
            'display_name' => 'Too Late',
            'custom_song_title' => 'Closing Time',
        ]);
    }

    public function testWalkUpHasNoRequesterTokenSoItCannotBeSelfCanceled(): void
    {
        $result = QueueService::submitWalkUp($this->tenantDb, $this->sessionId, [
            'display_name' => 'Walk Up',
            'custom_song_title' => 'A Song',
        ]);

        $stmt = $this->tenantDb->prepare('SELECT requester_token FROM song_requests WHERE id = ?');
        $stmt->execute([$result['requestId']]);
        self::assertNull($stmt->fetchColumn());
    }
}
