<?php

declare(strict_types=1);

namespace NextUp\Services;

use PDO;

final class SettingsService
{
    /** @var array<string,mixed> */
    public const DEFAULTS = [
        'prevent_duplicate_requests' => true,
        'song_source' => 'catalog',          // 'catalog' | 'catalog+youtube'
        'auto_attach_youtube' => false,
        'max_requests_per_singer' => 1,
        'default_party_type' => 'solo',      // 'solo' | 'duet' | 'group'
        'show_explicit_songs' => true,
    ];

    /** @return array<string,mixed> */
    public static function all(PDO $db): array
    {
        $settings = self::DEFAULTS;
        $rows = $db->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
        foreach ($rows as $row) {
            $settings[$row['setting_key']] = json_decode($row['setting_value'], true);
        }
        return $settings;
    }

    public static function set(PDO $db, string $key, mixed $value): void
    {
        // CAST(? AS JSON) is rejected by MariaDB; binding the encoded
        // string directly works there and in MySQL 8 alike (the column's
        // implicit JSON_VALID check accepts well-formed JSON text).
        $stmt = $db->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        $stmt->execute([$key, json_encode($value, JSON_THROW_ON_ERROR)]);
    }

    /** @param array<string,mixed> $values */
    public static function saveMany(PDO $db, array $values): void
    {
        foreach ($values as $key => $value) {
            if (!is_string($key) || !array_key_exists($key, self::DEFAULTS)) {
                continue;
            }
            self::set($db, $key, self::coerce($key, $value));
        }
    }

    private static function coerce(string $key, mixed $value): mixed
    {
        $default = self::DEFAULTS[$key] ?? null;
        if (is_bool($default)) {
            if (is_bool($value)) {
                return $value;
            }
            $str = strtolower(trim((string)$value));
            return !in_array($str, ['', '0', 'false', 'off', 'no'], true);
        }
        if (is_int($default)) {
            return max(0, (int)$value);
        }
        if ($key === 'song_source') {
            $value = (string)$value;
            return in_array($value, ['catalog', 'catalog+youtube'], true) ? $value : 'catalog';
        }
        if ($key === 'default_party_type') {
            $value = (string)$value;
            return in_array($value, ['solo', 'duet', 'group'], true) ? $value : 'solo';
        }
        return $value;
    }
}
