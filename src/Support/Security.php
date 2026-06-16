<?php

declare(strict_types=1);

namespace PanicMic\Support;

final class Security
{
    /**
     * Test seam for session-id rotation. When set, {@see regenerateSession}
     * calls this instead of PHP's session_regenerate_id(). The CLI test
     * process never has an active session, so this lets the auth tests
     * observe that login actually rotates the id. Mirrors the Auth::useDb
     * seam pattern.
     *
     * @var (callable():void)|null
     */
    private static $sessionRotator = null;

    public static function startSession(): void
    {
        $secure = (Env::get('APP_ENV') === 'production');
        session_name(Env::get('SESSION_NAME', 'panicmic_sid') ?? 'panicmic_sid');
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
        $nonce = self::styleNonce();
        // YouTube IFrame Player needs script + frame access; everything
        // else stays same-origin.
        $csp = "default-src 'self'; "
             . "script-src 'self' https://www.youtube.com https://s.ytimg.com; "
             . "connect-src 'self'; "
             . "img-src 'self' data: https:; "
             . "style-src 'self' 'nonce-{$nonce}'; "
             . "frame-src https://www.youtube.com https://www.youtube-nocookie.com; "
             . "object-src 'none'";
        header('Content-Security-Policy: ' . $csp);
    }

    /**
     * Per-request nonce attached to the single inline <style> block in
     * views/layout.php. Stable for the request, fresh on each new
     * request, never reused across responses.
     */
    public static function styleNonce(): string
    {
        static $nonce = null;
        if ($nonce === null) {
            $nonce = base64_encode(random_bytes(16));
        }
        return $nonce;
    }

    /**
     * Rotate the session id at a privilege boundary (login / impersonation)
     * to defend against session fixation: an attacker who plants a known
     * session id on the victim before login can't ride it afterward.
     *
     * session_regenerate_id(true) preserves $_SESSION data (CSRF token,
     * rate-limit buckets) while issuing a fresh id and dropping the old one.
     * Guarded so it's a safe no-op when there is no active session (CLI,
     * tests, webhook routes that never start one).
     */
    public static function regenerateSession(): void
    {
        if (self::$sessionRotator !== null) {
            (self::$sessionRotator)();
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /** Test seam — observe/override session-id rotation. Pass null to reset. */
    public static function setSessionRotator(?callable $rotator): void
    {
        self::$sessionRotator = $rotator;
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
        if (!self::csrfTokenMatches(is_string($token) ? $token : '')) {
            Response::json(['error' => 'Invalid CSRF token'], 419);
        }
    }

    /**
     * Pure predicate used by requireCsrf. Public so unit tests can
     * exercise the verify side of the round-trip without dealing with
     * Response::json's exit.
     */
    public static function csrfTokenMatches(string $candidate): bool
    {
        $expected = $_SESSION['csrf'] ?? '';
        if (!is_string($expected) || $expected === '') {
            return false;
        }
        return hash_equals($expected, $candidate);
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

    /**
     * DB-backed rate limit (survives session resets — used for login
     * endpoints to defend against credential stuffing).
     *
     * Returns the number of attempts that would now be recorded in the
     * bucket (including this call's increment) when the limit has NOT
     * been exceeded. When it has, calls Response::json with HTTP 429.
     */
    public static function rateLimitDb(\PDO $db, string $bucket, int $limit, int $windowSeconds): int
    {
        // Prune anything outside the window for this bucket.
        $prune = $db->prepare(
            'DELETE FROM login_attempts WHERE bucket = ? AND attempted_at < (NOW() - INTERVAL ? SECOND)'
        );
        $prune->execute([$bucket, $windowSeconds]);

        $count = $db->prepare('SELECT COUNT(*) FROM login_attempts WHERE bucket = ?');
        $count->execute([$bucket]);
        $current = (int)$count->fetchColumn();

        if ($current >= $limit) {
            header('Retry-After: ' . $windowSeconds);
            Response::json([
                'error' => 'Too many login attempts; try again later.',
                'retry_after' => $windowSeconds,
            ], 429);
        }

        $db->prepare('INSERT INTO login_attempts (bucket) VALUES (?)')->execute([$bucket]);
        return $current + 1;
    }

    /**
     * Clears the bucket on successful login so a legitimate user who
     * was nearly throttled doesn't carry their attempt count forward.
     */
    public static function rateLimitDbClear(\PDO $db, string $bucket): void
    {
        $db->prepare('DELETE FROM login_attempts WHERE bucket = ?')->execute([$bucket]);
    }

    /**
     * Constructs a bucket key from the remote IP and (normalized) email
     * so that one bad actor can't lock out another user from the same
     * IP and vice versa.
     */
    public static function loginBucket(string $email): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        return 'login:' . $ip . ':' . strtolower(trim($email));
    }

    /**
     * Per-IP bucket for the self-serve signup endpoint. Keyed on IP only
     * (no email/subdomain) so the limit throttles account-creation abuse
     * and subdomain enumeration regardless of which values are probed.
     */
    public static function signupBucket(?string $ip = null): string
    {
        $ip ??= ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        return 'signup:' . $ip;
    }
}
