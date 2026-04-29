<?php

declare(strict_types=1);

namespace NextUp\Support;

final class Security
{
    public static function startSession(): void
    {
        $secure = (Env::get('APP_ENV') === 'production');
        session_name(Env::get('SESSION_NAME', 'nextup_sid') ?? 'nextup_sid');
        session_set_cookie_params([
            'lifetime' => 60 * 60 * 12,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    public static function headers(): void
    {
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; connect-src 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline'; object-src 'none'");
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function requireCsrf(): void
    {
        if (in_array(Request::method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? Request::input()['csrf'] ?? '';
        if (!is_string($token) || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
            Response::json(['error' => 'Invalid CSRF token'], 419);
        }
    }

    public static function rateLimit(string $key, int $limit, int $windowSeconds): void
    {
        $now = time();
        $_SESSION['rate_limits'][$key] ??= ['count' => 0, 'reset' => $now + $windowSeconds];
        if ($_SESSION['rate_limits'][$key]['reset'] < $now) {
            $_SESSION['rate_limits'][$key] = ['count' => 0, 'reset' => $now + $windowSeconds];
        }
        $_SESSION['rate_limits'][$key]['count']++;
        if ($_SESSION['rate_limits'][$key]['count'] > $limit) {
            Response::json(['error' => 'Rate limit exceeded'], 429);
        }
    }
}
