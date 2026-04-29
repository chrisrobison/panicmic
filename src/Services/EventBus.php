<?php

declare(strict_types=1);

namespace NextUp\Services;

use PDO;

final class EventBus
{
    /** @param array<string,mixed>|list<mixed> $payload */
    public static function publish(PDO $db, string $event, array $payload): void
    {
        $stmt = $db->prepare('INSERT INTO realtime_events (event_name, payload) VALUES (?, CAST(? AS JSON))');
        $stmt->execute([$event, json_encode($payload, JSON_THROW_ON_ERROR)]);
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
