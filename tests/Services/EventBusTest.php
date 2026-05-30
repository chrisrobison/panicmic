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
}
