<?php

declare(strict_types=1);

namespace PanicMic\Http;

use PanicMic\Auth\Auth;
use PanicMic\Database\Connection;
use PanicMic\Services\BillingService;
use PanicMic\Services\EventBus;
use PanicMic\Services\VenueService;
use PanicMic\Support\Request;
use PanicMic\Support\Response;
use PDO;

final class VenueController
{
    /** @param array<string,mixed> $tenant @param array<string,mixed> $session */
    public static function index(PDO $db, array $tenant, array $session): never
    {
        Auth::requireTenantRole('kj', 'tenant_admin');
        $cap = BillingService::venueCap(Connection::super(), $tenant);
        Response::json([
            'venues' => VenueService::all($db, true),
            'max_venues' => $cap,
            'venues_used' => VenueService::countActive($db),
        ]);
    }

    /** @param array<string,mixed> $tenant @param array<string,mixed> $session */
    public static function create(PDO $db, array $tenant, array $session): never
    {
        Auth::requireTenantRole('kj', 'tenant_admin');
        $cap = BillingService::venueCap(Connection::super(), $tenant);
        $venue = VenueService::create($db, Request::input(), $cap);
        EventBus::publish($db, 'venues:updated', ['venue' => $venue]);
        Response::json(['venue' => $venue]);
    }

    /** @param array<string,mixed> $tenant @param array<string,mixed> $session */
    public static function update(PDO $db, array $tenant, array $session, int $id): never
    {
        Auth::requireTenantRole('kj', 'tenant_admin');
        $venue = VenueService::update($db, $id, Request::input());
        if (!$venue) {
            Response::json(['error' => 'Venue not found'], 404);
        }
        EventBus::publish($db, 'venues:updated', ['venue' => $venue]);
        Response::json(['venue' => $venue]);
    }

    /** @param array<string,mixed> $tenant @param array<string,mixed> $session */
    public static function delete(PDO $db, array $tenant, array $session, int $id): never
    {
        Auth::requireTenantRole('kj', 'tenant_admin');
        if (!VenueService::archive($db, $id)) {
            Response::json(['error' => 'Venue not found'], 404);
        }
        EventBus::publish($db, 'venues:updated', ['venue_id' => $id]);
        Response::json(['ok' => true]);
    }
}
