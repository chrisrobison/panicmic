<?php

declare(strict_types=1);

namespace NextUp\Services;

use PDO;

final class SettingsService
{
    /** @return array<string,mixed> */
    public static function all(PDO $db): array
    {
        $rows = $db->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['setting_key']] = json_decode($row['setting_value'], true);
        }
        return $settings;
    }

    /** @param mixed $value */
    public static function set(PDO $db, string $key, mixed $value): void
    {
        $stmt = $db->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, CAST(? AS JSON)) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        $stmt->execute([$key, json_encode($value, JSON_THROW_ON_ERROR)]);
    }
}
