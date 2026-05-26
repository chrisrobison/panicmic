<?php

declare(strict_types=1);

namespace NextUp\Tests\Services;

use NextUp\Services\EventBus;
use NextUp\Tests\Support\DatabaseTestCase;

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
        EventBus::publish($this->tenantDb, 'a', []);
        $midId = (int)$this->tenantDb->lastInsertId();
        EventBus::publish($this->tenantDb, 'b', ['x' => 1]);
        EventBus::publish($this->tenantDb, 'c', []);

        $after = EventBus::after($this->tenantDb, $midId);
        self::assertCount(2, $after);
        self::assertSame('b', $after[0]['event_name']);
        self::assertSame(['x' => 1], $after[1]['payload'] === [] ? $after[0]['payload'] : $after[0]['payload']);
        // Second row should be 'c'.
        self::assertSame('c', $after[1]['event_name']);
    }

    public function testAfterReturnsEmptyWhenCaughtUp(): void
    {
        EventBus::publish($this->tenantDb, 'one', []);
        $lastId = (int)$this->tenantDb->lastInsertId();
        self::assertSame([], EventBus::after($this->tenantDb, $lastId));
    }
}
