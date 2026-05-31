<?php

declare(strict_types=1);

namespace PanicMic\Services;

use PDO;

/**
 * Venues are the places a KJ hosts at, scoped to the account (tenant DB).
 * A professional KJ runs several venues across the week, so this is
 * first-class data rather than the single venue_name that used to live on
 * the tenant record.
 */
final class VenueService
{
    /** @return list<array<string,mixed>> */
    public static function all(PDO $db, bool $includeInactive = false): array
    {
        $where = $includeInactive ? '' : 'WHERE is_active = 1';
        return $db->query(
            "SELECT * FROM venues {$where} ORDER BY is_active DESC, name ASC"
        )->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public static function find(PDO $db, int $id): ?array
    {
        $stmt = $db->prepare('SELECT * FROM venues WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function countActive(PDO $db): int
    {
        return (int)$db->query('SELECT COUNT(*) FROM venues WHERE is_active = 1')->fetchColumn();
    }

    /**
     * Create a venue, enforcing the plan's venue cap. Pass $maxVenues = 0
     * (or negative) to disable the cap. Throws InvalidArgumentException
     * (surfaced as HTTP 400) when the name is blank or the cap is hit.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function create(PDO $db, array $data, int $maxVenues = 0): array
    {
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Venue name is required');
        }
        if ($maxVenues > 0 && self::countActive($db) >= $maxVenues) {
            throw new \InvalidArgumentException(
                "Your plan allows up to {$maxVenues} venues. Upgrade or archive a venue to add another."
            );
        }

        $fields = self::sanitize($data);
        $fields['slug'] = self::uniqueSlug($db, $name);

        $columns = array_keys($fields);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $stmt = $db->prepare(
            'INSERT INTO venues (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')'
        );
        $stmt->execute(array_values($fields));
        $id = (int)$db->lastInsertId();

        return self::find($db, $id) ?? ['id' => $id, 'name' => $name];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>|null
     */
    public static function update(PDO $db, int $id, array $data): ?array
    {
        if (!self::find($db, $id)) {
            return null;
        }
        $fields = self::sanitize($data);
        if (array_key_exists('name', $fields) && trim((string)$fields['name']) === '') {
            throw new \InvalidArgumentException('Venue name is required');
        }
        // Allow toggling active state on update.
        if (array_key_exists('is_active', $data)) {
            $fields['is_active'] = !empty($data['is_active']) ? 1 : 0;
        }
        if (!$fields) {
            return self::find($db, $id);
        }
        $assignments = implode(', ', array_map(static fn (string $c): string => "{$c} = ?", array_keys($fields)));
        $params = array_values($fields);
        $params[] = $id;
        $db->prepare("UPDATE venues SET {$assignments} WHERE id = ?")->execute($params);
        return self::find($db, $id);
    }

    /** Soft-delete: archived venues free up a plan slot but keep history. */
    public static function archive(PDO $db, int $id): bool
    {
        if (!self::find($db, $id)) {
            return false;
        }
        $db->prepare('UPDATE venues SET is_active = 0 WHERE id = ?')->execute([$id]);
        return true;
    }

    /**
     * Whitelist + normalize incoming fields so callers can't write columns
     * we don't expose.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function sanitize(array $data): array
    {
        $allowed = [
            'name', 'address_line1', 'address_line2', 'city', 'region',
            'postal_code', 'country', 'lat', 'lng', 'timezone',
            'default_night_name', 'notes',
        ];
        $out = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $value = $data[$key];
            if ($key === 'lat' || $key === 'lng') {
                $out[$key] = ($value === '' || $value === null) ? null : (float)$value;
                continue;
            }
            $value = is_string($value) ? trim($value) : $value;
            $out[$key] = ($value === '' || $value === null) ? null : $value;
        }
        // name is required to be non-null when present.
        if (array_key_exists('name', $data)) {
            $out['name'] = trim((string)$data['name']);
        }
        return $out;
    }

    private static function uniqueSlug(PDO $db, string $name): string
    {
        $base = preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) ?: 'venue';
        $base = trim($base, '-') ?: 'venue';
        $slug = $base;
        $n = 1;
        $stmt = $db->prepare('SELECT 1 FROM venues WHERE slug = ? LIMIT 1');
        while (true) {
            $stmt->execute([$slug]);
            if (!$stmt->fetchColumn()) {
                return $slug;
            }
            $slug = $base . '-' . (++$n);
        }
    }
}
