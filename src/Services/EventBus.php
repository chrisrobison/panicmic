<?php

declare(strict_types=1);

namespace NextUp\Services;

use PDO;

final class EventBus
{
    /** Drop events older than this on each publish to bound table size. */
    public const RETENTION_INTERVAL = '1 HOUR';

    /** @param array<string,mixed>|list<mixed> $payload */
    public static function publish(PDO $db, string $event, array $payload): int
    {
        $stmt = $db->prepare('INSERT INTO realtime_events (event_name, payload) VALUES (?, CAST(? AS JSON))');
        $stmt->execute([$event, json_encode($payload, JSON_THROW_ON_ERROR)]);
        $id = (int)$db->lastInsertId();

        // Inexpensive retention sweep: indexed by created_at via the
        // idx_realtime_events_created index defined in migration 001.
        // Runs on every publish so the table stays bounded without a
        // separate cron. Read lastInsertId BEFORE this DELETE because
        // some PDO/MySQL builds clear it after subsequent statements.
        $db->exec('DELETE FROM realtime_events WHERE created_at < (NOW() - INTERVAL ' . self::RETENTION_INTERVAL . ')');

        return $id;
    }

    /** @return list<array<string,mixed>> */
    public static function after(PDO $db, int $lastId): array
    {
        $stmt = $db->prepare('SELECT id, event_name, payload FROM realtime_events WHERE id > ? ORDER BY id ASC LIMIT 100');
        $stmt->execute([$lastId]);
        return array_map(static function (array $row): array {
            $row['payload'] = json_decode($row['payload'], true) ?: [];
            return $row;
        }, $stmt->fetchAll());
    }
}
