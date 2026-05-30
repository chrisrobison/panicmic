<?php

declare(strict_types=1);

namespace PanicMic\Support;

final class Url
{
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
