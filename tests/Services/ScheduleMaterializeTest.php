<?php

declare(strict_types=1);

namespace PanicMic\Tests\Services;

use PanicMic\Services\ScheduleService;
use PanicMic\Services\VenueService;
use PanicMic\Tests\Support\DatabaseTestCase;

final class ScheduleMaterializeTest extends DatabaseTestCase
{
    public function testMaterializeIsIdempotent(): void
    {
        $venue = VenueService::create($this->tenantDb, ['name' => 'Materialize Bar'], 0);
        // A weekly show creates one event per week across the 120-day horizon.
        ScheduleService::create($this->tenantDb, [
            'venue_id' => (int)$venue['id'],
            'name' => 'Weekly Night',
            'recurrence_type' => 'weekly',
            'weekday' => (int)date('w'),
            'start_time' => '20:00',
            'starts_on' => date('Y-m-d'),
        ]);

        $countAfterCreate = (int)$this->tenantDb->query('SELECT COUNT(*) FROM events')->fetchColumn();
        self::assertGreaterThan(0, $countAfterCreate);

        // Re-running materialize must not duplicate occurrences.
        ScheduleService::materialize($this->tenantDb);
        ScheduleService::materialize($this->tenantDb);
        $countAfterRerun = (int)$this->tenantDb->query('SELECT COUNT(*) FROM events')->fetchColumn();
        self::assertSame($countAfterCreate, $countAfterRerun);
    }

    public function testCanceledOccurrenceIsNotResurrected(): void
    {
        $venue = VenueService::create($this->tenantDb, ['name' => 'Cancel Bar'], 0);
        ScheduleService::create($this->tenantDb, [
            'venue_id' => (int)$venue['id'],
            'name' => 'Weekly Night',
            'recurrence_type' => 'weekly',
            'weekday' => (int)date('w'),
            'start_time' => '20:00',
            'starts_on' => date('Y-m-d'),
        ]);

        $eventId = (int)$this->tenantDb->query('SELECT id FROM events ORDER BY scheduled_for ASC LIMIT 1')->fetchColumn();
        $this->tenantDb->prepare("UPDATE events SET status = 'canceled' WHERE id = ?")->execute([$eventId]);

        ScheduleService::materialize($this->tenantDb);

        $status = $this->tenantDb->query("SELECT status FROM events WHERE id = {$eventId}")->fetchColumn();
        self::assertSame('canceled', $status, 'A canceled occurrence must stay canceled after re-materialize');
    }
}
