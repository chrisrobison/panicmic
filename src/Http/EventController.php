<?php

declare(strict_types=1);

namespace PanicMic\Http;

use PanicMic\Auth\Auth;
use PanicMic\Services\EventBus;
use PanicMic\Services\EventService;
use PanicMic\Services\ScheduleService;
use PanicMic\Services\SessionService;
use PanicMic\Support\Request;
use PanicMic\Support\Response;
use PDO;

final class EventController
{
    /**
     * GET /api/admin/events?from=Y-m-d&to=Y-m-d — calendar entries in a
     * range. Defaults to the next 60 days when no range is given.
     *
     * @param array<string,mixed> $tenant @param array<string,mixed> $session
     */
    public static function index(PDO $db, array $tenant, array $session): never
    {
        Auth::requireTenantRole('kj', 'tenant_admin');
        ScheduleService::materialize($db);
        $from = self::dateParam($_GET['from'] ?? null) ?? date('Y-m-d');
        $to = self::dateParam($_GET['to'] ?? null) ?? date('Y-m-d', strtotime('+60 days'));
        Response::json(['events' => EventService::range($db, $from, $to)]);
    }

    /** @param array<string,mixed> $tenant @param array<string,mixed> $session */
    public static function create(PDO $db, array $tenant, array $session): never
    {
        Auth::requireTenantRole('kj', 'tenant_admin');
        $event = EventService::createOneOff($db, Request::input(), $_SESSION['tenant_user']['id'] ?? null);
        EventBus::publish($db, 'events:updated', ['event' => $event]);
        Response::json(['event' => $event]);
    }

    /** @param array<string,mixed> $tenant @param array<string,mixed> $session */
    public static function update(PDO $db, array $tenant, array $session, int $id): never
    {
        Auth::requireTenantRole('kj', 'tenant_admin');
        $event = EventService::update($db, $id, Request::input());
        if (!$event) {
            Response::json(['error' => 'Event not found'], 404);
        }
        EventBus::publish($db, 'events:updated', ['event' => $event]);
        Response::json(['event' => $event]);
    }

    /** @param array<string,mixed> $tenant @param array<string,mixed> $session */
    public static function cancel(PDO $db, array $tenant, array $session, int $id): never
    {
        Auth::requireTenantRole('kj', 'tenant_admin');
        if (!EventService::cancel($db, $id)) {
            Response::json(['error' => 'Event not found'], 404);
        }
        EventBus::publish($db, 'events:updated', ['event_id' => $id]);
        Response::json(['ok' => true]);
    }

    /**
     * POST /api/admin/events/{id}/start — start a live night from a
     * scheduled event, carrying its venue + name onto the new session.
     *
     * @param array<string,mixed> $tenant @param array<string,mixed> $session
     */
    public static function start(PDO $db, array $tenant, array $session, int $id): never
    {
        Auth::requireTenantRole('kj', 'tenant_admin');
        $event = EventService::find($db, $id);
        if (!$event) {
            Response::json(['error' => 'Event not found'], 404);
        }
        $name = trim((string)$event['name']) ?: (string)($tenant['night_name'] ?? 'Karaoke Night');
        $newSession = SessionService::start($db, $name, (int)$event['venue_id'], $id);
        EventBus::publish($db, 'session:started', ['session' => $newSession]);
        Response::json(['session' => $newSession]);
    }

    private static function dateParam(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }
}
