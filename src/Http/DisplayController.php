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
        $input = Request::input();
        $screen = self::resolveScreen($input['screen'] ?? null);
        DisplayService::update($db, (int)$session['id'], $input, $_SESSION['tenant_user']['id'] ?? null, $screen);
        $display = DisplayService::state($db, (int)$session['id'], $screen);
        EventBus::publish($db, 'display:state_changed', ['screen' => $screen, 'display' => $display]);
        Response::json(['display' => $display]);
    }

    /** @param array<string,mixed> $tenant @param array<string,mixed> $session */
    public static function showState(PDO $db, array $tenant, array $session): never
    {
        $screen = self::resolveScreen($_GET['screen'] ?? null);
        Response::json(['display' => DisplayService::state($db, (int)$session['id'], $screen)]);
    }

    /** @param array<string,mixed> $tenant @param array<string,mixed> $session */
    public static function listScreens(PDO $db, array $tenant, array $session): never
    {
        Auth::requireTenantRole('kj', 'tenant_admin');
        Response::json(['screens' => DisplayService::listScreens($db, (int)$session['id'])]);
    }

    /** @param array<string,mixed> $tenant @param array<string,mixed> $session */
    public static function saveScreen(PDO $db, array $tenant, array $session): never
    {
        Auth::requireTenantRole('tenant_admin');
        DisplayService::upsertScreen($db, (int)$session['id'], Request::input());
        Response::json(['screens' => DisplayService::listScreens($db, (int)$session['id'])]);
    }

    /** @param array<string,mixed> $tenant @param array<string,mixed> $session */
    public static function deleteScreen(PDO $db, array $tenant, array $session, string $screen): never
    {
        Auth::requireTenantRole('tenant_admin');
        if ($screen === DisplayService::DEFAULT_SCREEN) {
            Response::json(['error' => 'Cannot delete the main screen'], 400);
        }
        DisplayService::removeScreen($db, (int)$session['id'], $screen);
        Response::json(['screens' => DisplayService::listScreens($db, (int)$session['id'])]);
    }

    private static function resolveScreen(mixed $raw): string
    {
        $clean = preg_replace('/[^a-z0-9_-]/i', '', (string)($raw ?? '')) ?: DisplayService::DEFAULT_SCREEN;
        return $clean;
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
        Response::json(['id' => $id, 'announcement_id' => $id]);
    }
}
