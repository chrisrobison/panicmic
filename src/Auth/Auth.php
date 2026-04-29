<?php

declare(strict_types=1);

namespace NextUp\Auth;

use NextUp\Support\Response;
use PDO;

final class Auth
{
    public static function requireTenantRole(string ...$roles): void
    {
        $user = $_SESSION['tenant_user'] ?? null;
        if (!is_array($user) || !in_array($user['role'] ?? '', $roles, true)) {
            Response::json(['error' => 'Authentication required'], 401);
        }
    }

    public static function requireSuper(): void
    {
        if (empty($_SESSION['super_admin'])) {
            Response::json(['error' => 'Super-admin authentication required'], 401);
        }
    }

    /** @return array<string,mixed>|null */
    public static function attemptTenant(PDO $db, string $email, string $password): ?array
    {
        $stmt = $db->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password_hash'] ?? '')) {
            return null;
        }
        $_SESSION['tenant_user'] = ['id' => (int)$user['id'], 'email' => $user['email'], 'display_name' => $user['display_name'], 'role' => $user['role']];
        return $_SESSION['tenant_user'];
    }
}
