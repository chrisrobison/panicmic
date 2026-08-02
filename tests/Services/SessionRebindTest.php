<?php

declare(strict_types=1);

namespace PanicMic\Tests\Services;

use PanicMic\Services\EventBus;
use PanicMic\Services\SessionService;
use PanicMic\Tests\Support\DatabaseTestCase;

/**
 * Regression tests for the display/dashboard session split-brain.
 *
 * A display page freezes its session id into a meta tag at render time. If
 * the KJ then starts a new night, the display is bound to a session that is
 * no longer live. Because realtime events are session-scoped, that display
 * receives nothing at all — it looks connected and silently shows stale
 * state until a human reloads it.
 *
 * The fix has two halves, both covered here:
 *   1. Session lifecycle events are published tenant-wide (NULL session_id)
 *      so they reach clients bound to *any* session, including a dead one.
 *   2. A closed session is distinguishable from a live one, so the daemon
 *      can refuse a stale handshake instead of parking the client in a
 *      fanout group that will never receive anything.
 */
final class SessionRebindTest extends DatabaseTestCase
{
    public function testSessionStartedIsVisibleToAClientBoundToTheOldSession(): void
    {
        $oldSessionId = $this->sessionId;

        // Tenant-wide publish, mirroring SessionController::start.
        $newSession = SessionService::start($this->tenantDb, 'Second Night');
        EventBus::publish($this->tenantDb, 'session:started', [
            'session' => $newSession,
            'sessionId' => (int)$newSession['id'],
            'previousSessionId' => $oldSessionId,
        ], null);

        // A display still attached to the OLD session must see it. This is
        // the event that tells it to rebind; scoping it to the new session
        // made that impossible.
        $events = EventBus::after($this->tenantDb, 0, $oldSessionId);

        self::assertContains(
            'session:started',
            array_column($events, 'event_name'),
            'a client on the old session must receive session:started',
        );
    }

    public function testSessionStartedCarriesTheNewSessionIdSoClientsCanRebind(): void
    {
        $newSession = SessionService::start($this->tenantDb, 'Third Night');
        EventBus::publish($this->tenantDb, 'session:started', [
            'session' => $newSession,
            'sessionId' => (int)$newSession['id'],
        ], null);

        $events = EventBus::after($this->tenantDb, 0, $this->sessionId);
        $started = null;
        foreach ($events as $event) {
            if ($event['event_name'] === 'session:started') {
                $started = $event;
            }
        }

        self::assertNotNull($started);
        self::assertSame(
            (int)$newSession['id'],
            $started['payload']['sessionId'],
            'the payload must name the session to rebind to',
        );
        self::assertNotSame(
            $this->sessionId,
            $started['payload']['sessionId'],
            'the new session must actually differ from the stale one',
        );
    }

    public function testSessionEndedIsAlsoTenantWide(): void
    {
        $endedId = $this->sessionId;
        SessionService::end($this->tenantDb, $endedId);
        EventBus::publish($this->tenantDb, 'session:ended', [
            'session_id' => $endedId,
            'sessionId' => $endedId,
            'stats' => [],
        ], null);

        // Readable from an unrelated session id — i.e. genuinely tenant-wide.
        $events = EventBus::after($this->tenantDb, 0, $endedId + 999);

        self::assertContains('session:ended', array_column($events, 'event_name'));
    }

    public function testStartingANewSessionClosesThePreviousOne(): void
    {
        $oldSessionId = $this->sessionId;
        SessionService::start($this->tenantDb, 'Replacement Night');

        $stmt = $this->tenantDb->prepare('SELECT status FROM karaoke_sessions WHERE id = ?');
        $stmt->execute([$oldSessionId]);

        self::assertSame(
            'closed',
            $stmt->fetchColumn(),
            'the superseded session must not remain live, or two sessions look current',
        );
    }

    /**
     * The daemon gates handshakes on this distinction. Existence alone was
     * the old check, which is why a display holding a closed session id was
     * happily accepted and then never heard anything again.
     */
    public function testClosedSessionIsDistinguishableFromLive(): void
    {
        $closedId = $this->sessionId;
        $newSession = SessionService::start($this->tenantDb, 'Live Night');

        $liveIds = $this->tenantDb
            ->query("SELECT id FROM karaoke_sessions WHERE status IN ('live','active','paused')")
            ->fetchAll(\PDO::FETCH_COLUMN);
        $liveIds = array_map('intval', $liveIds);

        self::assertContains((int)$newSession['id'], $liveIds);
        self::assertNotContains($closedId, $liveIds);
    }

    public function testLatestReturnsClosedSessionWithoutCreatingANewOne(): void
    {
        // Read paths must never conjure a session — a display polling in an
        // empty room previously could, via SessionService::current().
        SessionService::end($this->tenantDb, $this->sessionId);
        $before = (int)$this->tenantDb->query('SELECT COUNT(*) FROM karaoke_sessions')->fetchColumn();

        $latest = SessionService::latest($this->tenantDb, 'Fallback Night');

        $after = (int)$this->tenantDb->query('SELECT COUNT(*) FROM karaoke_sessions')->fetchColumn();
        self::assertSame($before, $after, 'a read must not create a session');
        self::assertSame('closed', $latest['status']);
    }

    public function testCurrentStillBootstrapsForMutations(): void
    {
        SessionService::end($this->tenantDb, $this->sessionId);
        $before = (int)$this->tenantDb->query('SELECT COUNT(*) FROM karaoke_sessions')->fetchColumn();

        $session = SessionService::current($this->tenantDb, 'Bootstrapped Night');

        $after = (int)$this->tenantDb->query('SELECT COUNT(*) FROM karaoke_sessions')->fetchColumn();
        self::assertSame($before + 1, $after);
        self::assertSame('live', $session['status']);
    }
}
