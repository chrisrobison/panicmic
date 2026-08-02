<?php

declare(strict_types=1);

namespace PanicMic\Tests\Services;

use PanicMic\Services\EventBus;
use PanicMic\Tests\Support\DatabaseTestCase;

final class EventBusTest extends DatabaseTestCase
{
    public function testPublishInsertsRow(): void
    {
        EventBus::publish($this->tenantDb, 'queue:updated', ['requestId' => 7]);
        $count = (int)$this->tenantDb->query('SELECT COUNT(*) FROM realtime_events')->fetchColumn();
        self::assertSame(1, $count);
    }

    public function testAfterReturnsOnlyNewerEvents(): void
    {
        $midId = EventBus::publish($this->tenantDb, 'a', []);
        EventBus::publish($this->tenantDb, 'b', ['x' => 1]);
        EventBus::publish($this->tenantDb, 'c', []);

        $after = EventBus::after($this->tenantDb, $midId);
        self::assertCount(2, $after);
        self::assertSame('b', $after[0]['event_name']);
        self::assertSame(['x' => 1], $after[0]['payload']);
        self::assertSame('c', $after[1]['event_name']);
    }

    public function testAfterReturnsEmptyWhenCaughtUp(): void
    {
        $id = EventBus::publish($this->tenantDb, 'one', []);
        self::assertSame([], EventBus::after($this->tenantDb, $id));
    }

    public function testSessionFeedIncludesGlobalEventsAndExcludesOtherSessions(): void
    {
        $this->tenantDb->exec(
            "INSERT INTO karaoke_sessions (name, starts_at, status)
             VALUES ('Other Session', NOW(), 'live')"
        );
        $otherSessionId = (int)$this->tenantDb->lastInsertId();

        EventBus::publish($this->tenantDb, 'settings:updated', []);
        EventBus::publish($this->tenantDb, 'queue:updated', ['room' => 'ours'], $this->sessionId);
        EventBus::publish($this->tenantDb, 'queue:updated', ['room' => 'other'], $otherSessionId);

        $events = EventBus::after($this->tenantDb, 0, $this->sessionId);
        self::assertSame(
            ['settings:updated', 'queue:updated'],
            array_column($events, 'event_name'),
        );
        self::assertNull($events[0]['session_id']);
        self::assertSame($this->sessionId, $events[1]['session_id']);
        self::assertSame('ours', $events[1]['payload']['room']);
    }

    public function testPublishPrunesOldEvents(): void
    {
        // Insert an "old" event with backdated created_at (1 day ago).
        $this->tenantDb->exec(
            "INSERT INTO realtime_events (event_name, payload, created_at) " .
            "VALUES ('ancient', '{}', NOW() - INTERVAL 1 DAY)"
        );
        self::assertSame(1, (int)$this->tenantDb->query('SELECT COUNT(*) FROM realtime_events')->fetchColumn());

        // A new publish should prune the ancient row.
        EventBus::publish($this->tenantDb, 'fresh', []);
        $rows = $this->tenantDb->query('SELECT event_name FROM realtime_events')->fetchAll();
        self::assertCount(1, $rows);
        self::assertSame('fresh', $rows[0]['event_name']);
    }

    /* ------------------------------------------------------------------
     * Fault isolation.
     *
     * The bus is a notification channel. When it breaks it must degrade to
     * "clients refresh later", never take down the caller. A missing
     * realtime_events.session_id column once made every publish() throw,
     * which broke adding singers, approving requests, and display control
     * across 43 call sites. These tests pin that behavior down.
     * ------------------------------------------------------------------ */

    /**
     * A connection with no realtime_events table, standing in for any
     * broken bus (missing table, missing column, unreachable server). The
     * app's MySQL user intentionally has no DDL rights, so we can't drop
     * the real table — an empty in-memory SQLite handle gives the same
     * "every query against this throws" behavior, hermetically.
     */
    private function brokenBus(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }

    public function testPublishReturnsZeroInsteadOfThrowingWhenBusIsBroken(): void
    {
        $id = EventBus::publish($this->brokenBus(), 'queue:updated', ['requestId' => 1]);

        self::assertSame(0, $id, 'publish() must report failure via 0, not an exception');
    }

    public function testPublishFailureDoesNotAbortTheCallersWork(): void
    {
        // Mirrors a controller: do the business write, publish, keep going.
        // The regression this guards against is publish() throwing and
        // taking the rest of the handler with it.
        $this->tenantDb->exec(
            "INSERT INTO karaoke_sessions (name, starts_at, status)
             VALUES ('Walk-up Night', NOW(), 'live')"
        );
        $newSessionId = (int)$this->tenantDb->lastInsertId();

        $reachedCodeAfterPublish = false;
        EventBus::publish($this->brokenBus(), 'session:started', [], $newSessionId);
        $reachedCodeAfterPublish = true;

        self::assertTrue($reachedCodeAfterPublish, 'control flow must continue past a failed publish');

        $stmt = $this->tenantDb->prepare('SELECT name FROM karaoke_sessions WHERE id = ?');
        $stmt->execute([$newSessionId]);
        self::assertSame('Walk-up Night', $stmt->fetchColumn(), 'the business write must remain intact');
    }

    public function testAfterReturnsEmptyListInsteadOfThrowingWhenBusIsBroken(): void
    {
        self::assertSame([], EventBus::after($this->brokenBus(), 0));
        self::assertSame([], EventBus::after($this->brokenBus(), 0, $this->sessionId));
    }

    public function testPublishStillReturnsUsableIdOnTheHappyPath(): void
    {
        // Guard against the try/catch masking a real regression: a healthy
        // bus must still return a positive, monotonic id.
        $first = EventBus::publish($this->tenantDb, 'a', []);
        $second = EventBus::publish($this->tenantDb, 'b', []);

        self::assertGreaterThan(0, $first);
        self::assertGreaterThan($first, $second);
    }
}
