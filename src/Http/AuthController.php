<?php

declare(strict_types=1);

namespace PanicMic\Http;

use PanicMic\Auth\Auth;
use PanicMic\Database\Connection;
use PanicMic\Support\Impersonation;
use PanicMic\Support\Request;
use PanicMic\Support\Response;
use PanicMic\Support\Security;
use PDO;

final class AuthController
{
    public static function tenantLogin(PDO $db): never
    {
        $input = Request::input();
        $email = trim((string)($input['email'] ?? ''));
        $bucket = Security::loginBucket($email);
        Security::rateLimitDb(Connection::super(), $bucket, 5, 300);

        $password = (string)($input['password'] ?? '');
        // A tenant user signs in against this venue's own users table;
        // failing that, a super-admin may sign in with their global
        // super credentials on any KJ instance.
        $user = Auth::attemptTenant($db, $email, $password)
            ?? Auth::attemptSuperForTenant(Connection::super(), $email, $password);
        if (!$user) {
            Response::json(['error' => 'Invalid credentials'], 401);
        }
        Security::rateLimitDbClear(Connection::super(), $bucket);
        Response::json(['user' => $user]);
    }

    public static function logoutTenant(): never
    {
        unset($_SESSION['tenant_user']);
        Response::json(['ok' => true]);
    }

    public static function endImpersonation(): never
    {
        unset($_SESSION['super_admin']);
        if (Request::method() === 'POST') {
            Response::json(['ok' => true]);
        }
        Response::redirect('/admin/dashboard');
    }

    /**
     * If the current request carries a valid super_token query param,
     * promote the requester to acting-as-super for this tenant and
     * redirect to the same URL with the token stripped.
     *
     * @param array<string,mixed> $tenant
     */
    public static function consumeImpersonationToken(array $tenant): void
    {
        $raw = $_GET['super_token'] ?? null;
        if (!is_string($raw) || $raw === '') {
            return;
        }
        $info = Impersonation::verify($raw);
        if (!$info || $info['tenant_id'] !== (int)$tenant['id']) {
            return;
        }
        $stmt = Connection::super()->prepare('SELECT id, email, display_name FROM super_admin_users WHERE id = ?');
        $stmt->execute([$info['super_id']]);
        $admin = $stmt->fetch();
        if (!$admin) {
            return;
        }
        $_SESSION['super_admin'] = [
            'id' => (int)$admin['id'],
            'email' => $admin['email'],
            'display_name' => $admin['display_name'],
        ];
        $cleanQuery = $_GET;
        unset($cleanQuery['super_token']);
        $cleanPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        if ($cleanQuery) {
            $cleanPath .= '?' . http_build_query($cleanQuery);
        }
        header('Location: ' . $cleanPath, true, 302);
        exit;
    }
}
