<?php

declare(strict_types=1);

namespace PanicMic\Http;

use PanicMic\Auth\Auth;
use PanicMic\Database\Connection;
use PanicMic\Services\DisplayService;
use PanicMic\Services\EventBus;
use PanicMic\Services\QueueService;
use PanicMic\Services\SessionService;
use PanicMic\Support\Request;
use PanicMic\Support\Response;
use PDO;

final class SessionController
{
    /** @param array<string,mixed> $tenant @param array<string,mixed> $session */
    public static function start(PDO $db, array $tenant, array $session): never
    {
        Auth::requireTenantRole('kj', 'tenant_admin');
        $name = trim((string)(Request::input()['name'] ?? $tenant['night_name'] ?? 'Karaoke Night'));
        if ($name === '') {
            Response::json(['error' => 'Session name is required'], 400);
        }
        $newSession = SessionService::start($db, $name);
        EventBus::publish($db, 'session:started', ['session' => $newSession]);
        Response::json(['session' => $newSession]);
    }

    /** @param array<string,mixed> $tenant @param array<string,mixed> $session */
    public static function end(PDO $db, array $tenant, array $session): never
    {
        Auth::requireTenantRole('kj', 'tenant_admin');
        $sessionId = (int)$session['id'];
        SessionService::end($db, $sessionId, $_SESSION['tenant_user']['id'] ?? null);
        $stats = SessionService::statsFor($db, $sessionId);
        EventBus::publish($db, 'session:ended', ['session_id' => $sessionId, 'stats' => $stats]);
        EventBus::publish($db, 'queue:updated', ['queue' => QueueService::queue($db, $sessionId, Connection::super())]);
        EventBus::publish($db, 'display:state_changed', ['display' => DisplayService::state($db, $sessionId)]);
        Response::json(['ok' => true, 'stats' => $stats]);
    }
}
