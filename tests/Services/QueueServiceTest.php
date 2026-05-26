<?php

declare(strict_types=1);

namespace NextUp\Tests\Services;

use NextUp\Services\QueueService;
use NextUp\Services\SongService;
use NextUp\Tests\Support\DatabaseTestCase;

final class QueueServiceTest extends DatabaseTestCase
{
    public function testEmptyQueueIsEmpty(): void
    {
        self::assertSame([], QueueService::queue($this->tenantDb, $this->sessionId));
    }

    public function testSubmitCreatesQueueEntryAndIncrementsPosition(): void
    {
        $songA = SongService::create($this->tenantDb, ['title' => 'Don\'t Stop Believin\'', 'artist' => 'Journey']);
        $songB = SongService::create($this->tenantDb, ['title' => 'Living on a Prayer', 'artist' => 'Bon Jovi']);

        $reqA = QueueService::submit($this->tenantDb, $this->sessionId, ['song_id' => $songA, 'display_name' => 'Alice'], 'token-a', false);
        $reqB = QueueService::submit($this->tenantDb, $this->sessionId, ['song_id' => $songB, 'display_name' => 'Bob'], 'token-b', false);

        $queue = QueueService::queue($this->tenantDb, $this->sessionId);
        self::assertCount(2, $queue);
        self::assertSame(1, (int)$queue[0]['position']);
        self::assertSame(2, (int)$queue[1]['position']);
        self::assertSame($reqA, (int)$queue[0]['request_id']);
        self::assertSame($reqB, (int)$queue[1]['request_id']);
        self::assertSame('Alice', $queue[0]['singer_name']);
    }

    public function testSubmitRequiresASongSelection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        QueueService::submit($this->tenantDb, $this->sessionId, ['display_name' => 'Nobody'], 'token-x', false);
    }

    public function testSubmitRejectsBothLocalAndSharedSong(): void
    {
        $song = SongService::create($this->tenantDb, ['title' => 'X', 'artist' => 'Y']);
        $this->expectException(\InvalidArgumentException::class);
        QueueService::submit(
            $this->tenantDb,
            $this->sessionId,
            ['song_id' => $song, 'shared_song_id' => 42, 'display_name' => 'Nobody'],
            'token-x',
            false,
        );
    }

    public function testPreventDuplicateBlocksSecondActiveRequest(): void
    {
        $song = SongService::create($this->tenantDb, ['title' => 'X', 'artist' => 'Y']);
        QueueService::submit($this->tenantDb, $this->sessionId, ['song_id' => $song, 'display_name' => 'Alice'], 'token-dup', true);
        $this->expectException(\RuntimeException::class);
        QueueService::submit($this->tenantDb, $this->sessionId, ['song_id' => $song, 'display_name' => 'Alice'], 'token-dup', true);
    }

    public function testSetStatusUpdatesBothRows(): void
    {
        $song = SongService::create($this->tenantDb, ['title' => 'X', 'artist' => 'Y']);
        $reqId = QueueService::submit($this->tenantDb, $this->sessionId, ['song_id' => $song, 'display_name' => 'A'], 'tok', false);
        QueueService::setStatus($this->tenantDb, $this->sessionId, $reqId, 'now_singing');

        $queue = QueueService::queue($this->tenantDb, $this->sessionId);
        self::assertSame('now_singing', $queue[0]['queue_status']);
    }

    public function testRepeatedSubmissionsReuseSingerRow(): void
    {
        $songA = SongService::create($this->tenantDb, ['title' => 'A', 'artist' => 'X']);
        $songB = SongService::create($this->tenantDb, ['title' => 'B', 'artist' => 'Y']);
        QueueService::submit($this->tenantDb, $this->sessionId, ['song_id' => $songA, 'display_name' => 'Chris'], 'tok-1', false);
        QueueService::submit($this->tenantDb, $this->sessionId, ['song_id' => $songB, 'display_name' => 'Chris'], 'tok-2', false);

        $count = (int)$this->tenantDb->query("SELECT COUNT(*) FROM singers WHERE display_name = 'Chris'")->fetchColumn();
        self::assertSame(1, $count);

        // Both requests should point at the same singer row.
        $requests = $this->tenantDb->query('SELECT singer_id FROM song_requests')->fetchAll();
        self::assertCount(2, $requests);
        self::assertSame($requests[0]['singer_id'], $requests[1]['singer_id']);
    }
}
