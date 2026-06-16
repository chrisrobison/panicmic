<?php

declare(strict_types=1);

namespace PanicMic\Auth;

use PanicMic\Support\Response;
use PanicMic\Support\Security;
use PDO;

final class Auth
{
    /**
     * Per-request memo so re-checking users.is_active multiple times in
     * one request still only hits the DB once.
     *
     * @var array<int,bool>
     */
    private static array $activeMemo = [];

    /**
     * Request-scoped PDO handle that requireTenantRole uses to re-check
     * is_active without having to pass a connection through every
     * controller call site. Set by the front controller via useDb().
     */
    private static ?PDO $db = null;

    /** Register the tenant DB for this request so role checks can re-verify is_active. */
    public static function useDb(?PDO $db): void
    {
        self::$db = $db;
    }

    public static function requireTenantRole(string ...$roles): void
    {
        if (self::actingAsSuper()) {
            return;
        }
        $user = $_SESSION['tenant_user'] ?? null;
        if (!self::userHasRole($user, $roles)) {
            Response::json(['error' => 'Authentication required'], 401);
        }
        // Re-verify the user is still active. The per-request memo keeps
        // this to one DB roundtrip per request even if multiple routes
        // chain through requireTenantRole.
        if (self::$db instanceof PDO) {
            self::ensureSessionUserActive(self::$db);
        }
    }

    /**
     * Pure predicate behind the role-gate. Public so unit tests can
     * exercise the role-matching logic without triggering the HTTP exit
     * inside Response::json.
     *
     * @param mixed $user Session payload (any type — only array<string,mixed> with a 'role' key passes).
     * @param list<string> $roles Roles that should be admitted.
     */
    public static function userHasRole(mixed $user, array $roles): bool
    {
        return is_array($user) && in_array($user['role'] ?? '', $roles, true);
    }

    /**
     * Verifies that the session's tenant_user is still active in the
     * database. Call this from request hot paths that need to honor
     * deactivation immediately (instead of waiting for the session to
     * expire). Caches the lookup per request so repeated calls are
     * cheap.
     */
    public static function ensureSessionUserActive(PDO $db): void
    {
        if (self::actingAsSuper()) {
            return;
        }
        $user = $_SESSION['tenant_user'] ?? null;
        if (!is_array($user) || !isset($user['id'])) {
            return;
        }
        $userId = (int)$user['id'];
        if (isset(self::$activeMemo[$userId])) {
            if (!self::$activeMemo[$userId]) {
                self::invalidateSession();
            }
            return;
        }
        $stmt = $db->prepare('SELECT is_active FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $isActive = (int)($stmt->fetchColumn() ?: 0) === 1;
        self::$activeMemo[$userId] = $isActive;
        if (!$isActive) {
            self::invalidateSession();
        }
    }

    private static function invalidateSession(): never
    {
        unset($_SESSION['tenant_user']);
        Response::json(['error' => 'Account has been deactivated'], 401);
    }

    /** Test seam — drop the per-request memo. */
    public static function resetActiveMemo(): void
    {
        self::$activeMemo = [];
    }

    public static function requireSuper(): void
    {
        if (empty($_SESSION['super_admin'])) {
            Response::json(['error' => 'Super-admin authentication required'], 401);
        }
    }

    public static function actingAsSuper(): bool
    {
        return !empty($_SESSION['super_admin']);
    }

    /** @return array<string,mixed>|null */
    public static function currentTenantActor(): ?array
    {
        if (self::actingAsSuper()) {
            $admin = $_SESSION['super_admin'];
            return [
                'id' => null,
                'email' => $admin['email'] ?? null,
                'display_name' => ($admin['display_name'] ?? 'Super Admin') . ' (super)',
                'role' => 'super_admin',
            ];
        }
        return $_SESSION['tenant_user'] ?? null;
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
        // Rotate the session id at the privilege boundary before elevating.
        Security::regenerateSession();
        $_SESSION['tenant_user'] = ['id' => (int)$user['id'], 'email' => $user['email'], 'display_name' => $user['display_name'], 'role' => $user['role']];
        return $_SESSION['tenant_user'];
    }

    /**
     * Fallback for the tenant login form: a super-admin may sign in with
     * their super credentials on ANY tenant host. On success we set the
     * same acting-as-super session that impersonation uses, so every
     * requireTenantRole() check passes for this tenant. Super-admins are
     * a small, trusted set living in the shared super DB, so this is a
     * global login rather than a per-tenant user row.
     *
     * @return array<string,mixed>|null
     */
    public static function attemptSuperForTenant(PDO $superDb, string $email, string $password): ?array
    {
        $stmt = $superDb->prepare('SELECT * FROM super_admin_users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $admin = $stmt->fetch();
        if (!$admin || !password_verify($password, $admin['password_hash'] ?? '')) {
            return null;
        }
        // Rotate the session id at the privilege boundary before elevating.
        Security::regenerateSession();
        $_SESSION['super_admin'] = [
            'id' => (int)$admin['id'],
            'email' => $admin['email'],
            'display_name' => $admin['display_name'],
        ];
        return self::currentTenantActor();
    }
}
