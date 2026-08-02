<?php

declare(strict_types=1);

namespace PanicMic\Support;

final class Url
{
    public static function origin(): string
    {
        $configured = rtrim(trim((string)(Env::get('APP_URL', '') ?? '')), '/');
        if ($configured !== '') {
            $parts = parse_url($configured);
            if (is_array($parts)
                && in_array($parts['scheme'] ?? '', ['http', 'https'], true)
                && !empty($parts['host'])
            ) {
                return $configured;
            }
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        if (Env::get('APP_ENV') === 'production') {
            $scheme = 'https';
        }
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
        // Tenant resolution already validates the host, but keep this helper
        // independently safe when used from tests or CLI.
        if (!preg_match('/^[a-z0-9.-]+(?::\d{1,5})?$/', $host)) {
            $host = 'localhost';
        }
        return $scheme . '://' . $host . self::basePath();
    }

    public static function basePath(): string
    {
        $configured = trim(Env::get('APP_BASE_PATH', '') ?? '');
        if ($configured !== '') {
            return self::normalizeBase($configured);
        }

        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        if (str_ends_with($script, '/index.php')) {
            return self::normalizeBase(substr($script, 0, -10));
        }
        return '';
    }

    public static function path(string $path = '/'): string
    {
        $base = self::basePath();
        $normalizedPath = '/' . ltrim($path, '/');
        if ($normalizedPath === '/') {
            return $base === '' ? '/' : $base;
        }
        return $base . $normalizedPath;
    }

    public static function stripBasePath(string $path): string
    {
        $base = self::basePath();
        if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
            $path = substr($path, strlen($base)) ?: '/';
        }
        return rtrim($path, '/') ?: '/';
    }

    private static function normalizeBase(string $base): string
    {
        $base = '/' . trim($base, '/');
        return $base === '/' ? '' : rtrim($base, '/');
    }
}
