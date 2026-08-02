<?php

declare(strict_types=1);

namespace PanicMic\Http;

use PanicMic\Auth\Auth;
use PanicMic\Database\Connection;
use PanicMic\Services\DisplayService;
use PanicMic\Services\EventBus;
use PanicMic\Services\QueueService;
use PanicMic\Services\SessionService;
use PanicMic\Services\VenueService;
use PanicMic\Support\Request;
use PanicMic\Support\Response;
use PDO;

final class SessionController
{
    /** @param array<string,mixed> $tenant @param array<string,mixed> $session */
    public static function start(PDO $db, array $tenant, array $session): never
    {
        Auth::requireTenantRole('kj', 'tenant_admin');
        $input = Request::input();
        $venueId = !empty($input['venue_id']) ? (int)$input['venue_id'] : null;
        $eventId = !empty($input['event_id']) ? (int)$input['event_id'] : null;

        // Default the night name from the venue's default, then the
        // account-level fallback.
        $name = trim((string)($input['name'] ?? ''));
        if ($name === '' && $venueId !== null) {
            $venue = VenueService::find($db, $venueId);
            $name = trim((string)($venue['default_night_name'] ?? ''));
        }
        if ($name === '') {
            $name = trim((string)($tenant['night_name'] ?? 'Karaoke Night'));
        }
        if ($name === '') {
            Response::json(['error' => 'Session name is required'], 400);
        }
        $previousSessionId = (int)($session['id'] ?? 0);
        $newSession = SessionService::start($db, $name, $venueId, $eventId);

        // Tenant-wide (NULL session), NOT scoped to the new session.
        //
        // Session-scoped events only reach clients already attached to that
        // session, so scoping this one created a chicken-and-egg: a display
        // still bound to last night's session could never be told that a new
        // one had started, and sat stale until someone reloaded it by hand.
        // Lifecycle events must reach every client regardless of attachment.
        EventBus::publish($db, 'session:started', [
            'session' => $newSession,
            'sessionId' => (int)$newSession['id'],
            'previousSessionId' => $previousSessionId,
        ], null);

        Response::json(['session' => $newSession]);
    }

    /** @param array<string,mixed> $tenant @param array<string,mixed> $session */
    public static function end(PDO $db, array $tenant, array $session): never
    {
        Auth::requireTenantRole('kj', 'tenant_admin');
        $sessionId = (int)$session['id'];
        SessionService::end($db, $sessionId, $_SESSION['tenant_user']['id'] ?? null);
        $stats = SessionService::statsFor($db, $sessionId);

        // Tenant-wide for the same reason as session:started — a display
        // attached to this session needs to hear that it just ended, and
        // clients on other sessions need to stop showing it as live.
        EventBus::publish($db, 'session:ended', [
            'session_id' => $sessionId,
            'sessionId' => $sessionId,
            'stats' => $stats,
        ], null);
        EventBus::publish($db, 'queue:updated', ['queue' => QueueService::queue($db, $sessionId, Connection::super())], $sessionId);
        EventBus::publish($db, 'display:state_changed', ['display' => DisplayService::state($db, $sessionId)], $sessionId);
        Response::json(['ok' => true, 'stats' => $stats]);
    }
}
