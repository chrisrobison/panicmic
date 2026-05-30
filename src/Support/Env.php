<?php

declare(strict_types=1);

namespace PanicMic\Support;

final class Env
{
    /** @var array<string,string> */
    private static array $values = [];

    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            self::$values[trim($key)] = trim($value, " \t\n\r\0\x0B\"'");
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return $_ENV[$key] ?? getenv($key) ?: self::$values[$key] ?? $default;
    }

    /** @return list<string> */
    public static function list(string $key, string $default = ''): array
    {
        return array_values(array_filter(array_map(
            static fn (string $item): string => strtolower(trim($item)),
            explode(',', self::get($key, $default) ?? '')
        )));
    }
}
