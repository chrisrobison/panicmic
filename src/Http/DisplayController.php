<?php

declare(strict_types=1);

namespace NextUp\Http;

use NextUp\Auth\Auth;
use NextUp\Services\DisplayService;
use NextUp\Services\EventBus;
use NextUp\Support\Request;
use NextUp\Support\Response;
use PDO;

final class DisplayController
{
    /** @param array<string,mixed> $tenant @param array<string,mixed> $session */
    public static function updateState(PDO $db, array $tenant, array $session): never
    {
        Auth::requireTenantRole('kj', 'tenant_admin');
        DisplayService::update($db, (int)$session['id'], Request::input(), $_SESSION['tenant_user']['id'] ?? null);
        $display = DisplayService::state($db, (int)$session['id']);
        EventBus::publish($db, 'display:state_changed', ['display' => $display]);
        Response::json(['display' => $display]);
    }

    /** @param array<string,mixed> $tenant @param array<string,mixed> $session */
    public static function announce(PDO $db, array $tenant, array $session): never
    {
        Auth::requireTenantRole('kj', 'tenant_admin');
        $message = trim((string)(Request::input()['message'] ?? ''));
        if ($message === '' || strlen($message) > 500) {
            Response::json(['error' => 'Announcement message is required'], 400);
        }
        $stmt = $db->prepare('INSERT INTO announcements (session_id, message, created_by) VALUES (?, ?, ?)');
        $stmt->execute([(int)$session['id'], $message, $_SESSION['tenant_user']['id'] ?? null]);
        $id = (int)$db->lastInsertId();
        DisplayService::update($db, (int)$session['id'], ['mode' => 'announcement', 'announcement_id' => $id], $_SESSION['tenant_user']['id'] ?? null);
        EventBus::publish($db, 'announcement:shown', ['id' => $id, 'message' => $message]);
        EventBus::publish($db, 'display:state_changed', ['display' => DisplayService::state($db, (int)$session['id'])]);
        Response::json(['id' => $id]);
    }
}
