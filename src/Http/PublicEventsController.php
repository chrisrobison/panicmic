<?php

declare(strict_types=1);

namespace PanicMic\Http;

use PanicMic\Database\Connection;
use PanicMic\Services\EventService;
use PanicMic\Services\ScheduleService;
use PanicMic\Support\Response;
use PDO;

/**
 * Read-only, unauthenticated endpoints powering the public events page:
 * upcoming nights, past nights, and a finished night's setlist. No CSRF /
 * auth — these are GETs of intentionally public information.
 */
final class PublicEventsController
{
    public static function upcoming(PDO $db): never
    {
        ScheduleService::materialize($db);
        Response::json(['events' => EventService::upcoming($db, 50)]);
    }

    public static function past(PDO $db): never
    {
        Response::json(['events' => EventService::past($db, 50)]);
    }

    /** GET /api/public/events/{id} — setlist for a finished night ($id = session id). */
    public static function setlist(PDO $db, int $sessionId): never
    {
        $setlist = EventService::setlistFor($db, $sessionId, Connection::super());
        if (!$setlist) {
            Response::json(['error' => 'Event not found'], 404);
        }
        Response::json(['event' => $setlist]);
    }
}
