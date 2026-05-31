<?php

declare(strict_types=1);

namespace PanicMic\Tests\Services;

use PanicMic\Services\EventService;
use PanicMic\Services\SessionService;
use PanicMic\Services\VenueService;
use PanicMic\Tests\Support\DatabaseTestCase;

final class SessionVenueTest extends DatabaseTestCase
{
    public function testStartTagsSessionWithVenueAndEvent(): void
    {
        $venue = VenueService::create($this->tenantDb, ['name' => 'Linked Bar'], 0);
        $event = EventService::createOneOff($this->tenantDb, [
            'venue_id' => (int)$venue['id'],
            'name' => 'Launch Night',
            'scheduled_for' => date('Y-m-d') . ' 20:00',
        ]);

        $session = SessionService::start($this->tenantDb, 'Launch Night', (int)$venue['id'], (int)$event['id']);

        self::assertSame((int)$venue['id'], (int)$session['venue_id']);
        self::assertSame((int)$event['id'], (int)$session['event_id']);

        // The event flips to live and links back to the session.
        $linked = EventService::find($this->tenantDb, (int)$event['id']);
        self::assertSame('live', $linked['status']);
        self::assertSame((int)$session['id'], (int)$linked['session_id']);

        // Previously-live seeded session is closed (single-live-session rule).
        $prev = $this->tenantDb->query("SELECT status FROM karaoke_sessions WHERE id = {$this->sessionId}")->fetchColumn();
        self::assertSame('closed', $prev);
    }

    public function testEndClosesLinkedEvent(): void
    {
        $venue = VenueService::create($this->tenantDb, ['name' => 'End Bar'], 0);
        $event = EventService::createOneOff($this->tenantDb, [
            'venue_id' => (int)$venue['id'],
            'name' => 'Closing Night',
            'scheduled_for' => date('Y-m-d') . ' 20:00',
        ]);
        $session = SessionService::start($this->tenantDb, 'Closing Night', (int)$venue['id'], (int)$event['id']);

        SessionService::end($this->tenantDb, (int)$session['id'], null);

        $linked = EventService::find($this->tenantDb, (int)$event['id']);
        self::assertSame('closed', $linked['status']);
    }
}
