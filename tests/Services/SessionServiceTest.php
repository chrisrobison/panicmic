<?php

declare(strict_types=1);

namespace NextUp\Tests\Services;

use NextUp\Services\QueueService;
use NextUp\Services\SessionService;
use NextUp\Services\SongService;
use NextUp\Tests\Support\DatabaseTestCase;

final class SessionServiceTest extends DatabaseTestCase
{
    public function testActiveReturnsTheSeededSession(): void
    {
        $active = SessionService::active($this->tenantDb);
        self::assertNotNull($active);
        self::assertSame($this->sessionId, (int)$active['id']);
    }

    public function testStartArchivesPreviousSessions(): void
    {
        $next = SessionService::start($this->tenantDb, 'Tuesday Night');
        // Phase 4.2 lifecycle: ENUM('draft','live','closed'). A newly
        // started session is 'live'; previously-active rows transition
        // to 'closed'.
        self::assertSame('live', $next['status']);

        $prev = $this->tenantDb->query(
            "SELECT status, ends_at FROM karaoke_sessions WHERE id = {$this->sessionId}"
        )->fetch();
        self::assertSame('closed', $prev['status']);
        self::assertNotNull($prev['ends_at']);

        $active = SessionService::active($this->tenantDb);
        self::assertSame((int)$next['id'], (int)$active['id']);
    }

    public function testEndArchivesAndSnapshotsStats(): void
    {
        $song = SongService::create($this->tenantDb, ['title' => 'X', 'artist' => 'Y']);
        QueueService::submit($this->tenantDb, $this->sessionId, ['song_id' => $song, 'display_name' => 'A'], 'tok-1', false);
        QueueService::submit($this->tenantDb, $this->sessionId, ['song_id' => $song, 'display_name' => 'B'], 'tok-2', false);

        SessionService::end($this->tenantDb, $this->sessionId, null);

        $row = $this->tenantDb->query(
            "SELECT status, ends_at FROM karaoke_sessions WHERE id = {$this->sessionId}"
        )->fetch();
        // SessionService::end now transitions to 'closed' (post-007 ENUM).
        self::assertSame('closed', $row['status']);
        self::assertNotNull($row['ends_at']);

        $audit = $this->tenantDb->query("SELECT action, metadata FROM audit_log WHERE action = 'session.ended'")->fetch();
        self::assertNotFalse($audit);
        $meta = json_decode((string)$audit['metadata'], true);
        self::assertSame(2, $meta['total']);
    }

    public function testStatsForReportsCounts(): void
    {
        $song = SongService::create($this->tenantDb, ['title' => 'X', 'artist' => 'Y']);
        $req = QueueService::submit($this->tenantDb, $this->sessionId, ['song_id' => $song, 'display_name' => 'A'], 'tok', false);
        QueueService::setStatus($this->tenantDb, $this->sessionId, $req, 'now_singing');

        $stats = SessionService::statsFor($this->tenantDb, $this->sessionId);
        self::assertSame(1, $stats['total']);
        self::assertSame(1, $stats['now_singing']);
        self::assertSame(0, $stats['pending']);
    }
}
