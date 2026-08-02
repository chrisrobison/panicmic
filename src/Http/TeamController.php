<?php

declare(strict_types=1);

namespace PanicMic\Http;

use PanicMic\Auth\Auth;
use PanicMic\Services\Mailer;
use PanicMic\Services\PasswordResetService;
use PanicMic\Services\TeamService;
use PanicMic\Support\Request;
use PanicMic\Support\Response;
use PDO;

final class TeamController
{
    public static function index(PDO $db): never
    {
        Auth::requireTenantRole('tenant_admin');
        Response::json(['members' => TeamService::list($db)]);
    }

    /** @param array<string,mixed> $tenant */
    public static function create(PDO $db, array $tenant, string $origin): never
    {
        Auth::requireTenantRole('tenant_admin');
        $data = Request::input();
        $created = TeamService::create($db, $data);
        $link = rtrim($origin, '/') . '/admin/reset-password?token=' . rawurlencode($created['token']);
        Mailer::send(
            strtolower(trim((string)$data['email'])),
            'You are invited to the PanicMic team',
            "Hi " . trim((string)$data['display_name']) . ",\n\n"
                . "You have been invited to help run {$tenant['night_name']}.\n"
                . "Set your password using this one-time link:\n\n{$link}\n\n"
                . "This link expires in seven days.\n",
        );
        Response::json(['member_id' => $created['user_id']], 201);
    }

    public static function update(PDO $db, int $userId): never
    {
        Auth::requireTenantRole('tenant_admin');
        $actorId = (int)($_SESSION['tenant_user']['id'] ?? 0);
        if (!TeamService::update($db, $userId, Request::input(), $actorId)) {
            Response::json(['error' => 'Team member not found'], 404);
        }
        Response::json(['ok' => true]);
    }

    public static function resendInvite(PDO $db, int $userId, string $origin): never
    {
        Auth::requireTenantRole('tenant_admin');
        $stmt = $db->prepare(
            "SELECT id, email, display_name FROM users
             WHERE id = ? AND is_active = 1 AND role IN ('kj', 'tenant_admin') LIMIT 1"
        );
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user) {
            Response::json(['error' => 'Active team member not found'], 404);
        }
        $token = PasswordResetService::issue($db, $userId, 604800);
        $link = rtrim($origin, '/') . '/admin/reset-password?token=' . rawurlencode($token);
        Mailer::send(
            (string)$user['email'],
            'Set your PanicMic password',
            "Hi {$user['display_name']},\n\nSet your password here:\n\n{$link}\n\nThis link expires in seven days.\n",
        );
        Response::json(['ok' => true]);
    }
}
