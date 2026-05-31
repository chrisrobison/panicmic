<?php

declare(strict_types=1);

namespace PanicMic\Http;

use PanicMic\Auth\Auth;
use PanicMic\Services\EventBus;
use PanicMic\Services\ScheduleService;
use PanicMic\Support\Request;
use PanicMic\Support\Response;
use PDO;

final class ScheduleController
{
    /** @param array<string,mixed> $tenant @param array<string,mixed> $session */
    public static function index(PDO $db, array $tenant, array $session): never
    {
        Auth::requireTenantRole('kj', 'tenant_admin');
        // Opportunistically top up the materialized calendar so newly due
        // occurrences appear without a separate cron.
        ScheduleService::materialize($db);
        Response::json(['schedules' => ScheduleService::all($db, true)]);
    }

    /** @param array<string,mixed> $tenant @param array<string,mixed> $session */
    public static function create(PDO $db, array $tenant, array $session): never
    {
        Auth::requireTenantRole('kj', 'tenant_admin');
        $schedule = ScheduleService::create($db, Request::input());
        EventBus::publish($db, 'schedules:updated', ['schedule' => $schedule]);
        Response::json(['schedule' => $schedule]);
    }

    /** @param array<string,mixed> $tenant @param array<string,mixed> $session */
    public static function update(PDO $db, array $tenant, array $session, int $id): never
    {
        Auth::requireTenantRole('kj', 'tenant_admin');
        $schedule = ScheduleService::update($db, $id, Request::input());
        if (!$schedule) {
            Response::json(['error' => 'Schedule not found'], 404);
        }
        EventBus::publish($db, 'schedules:updated', ['schedule' => $schedule]);
        Response::json(['schedule' => $schedule]);
    }

    /** @param array<string,mixed> $tenant @param array<string,mixed> $session */
    public static function delete(PDO $db, array $tenant, array $session, int $id): never
    {
        Auth::requireTenantRole('kj', 'tenant_admin');
        if (!ScheduleService::deactivate($db, $id)) {
            Response::json(['error' => 'Schedule not found'], 404);
        }
        EventBus::publish($db, 'schedules:updated', ['schedule_id' => $id]);
        Response::json(['ok' => true]);
    }
}
