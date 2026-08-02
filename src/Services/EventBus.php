<?php

declare(strict_types=1);

namespace PanicMic\Services;

use PDO;

final class EventBus
{
    /** Drop events older than this on each publish to bound table size. */
    public const RETENTION_INTERVAL = '1 HOUR';

    /** @param array<string,mixed>|list<mixed> $payload */
    public static function publish(PDO $db, string $event, array $payload, ?int $sessionId = null): int
    {
        // MariaDB's JSON type is an alias for LONGTEXT with an implicit
        // JSON_VALID() check; explicit CAST(? AS JSON) is unsupported.
        // Plain string binding works against MariaDB and MySQL 8 alike.
        $stmt = $db->prepare(
            'INSERT INTO realtime_events (session_id, event_name, payload) VALUES (?, ?, ?)'
        );
        $stmt->execute([$sessionId, $event, json_encode($payload, JSON_THROW_ON_ERROR)]);
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
    public static function after(PDO $db, int $lastId, ?int $sessionId = null): array
    {
        if ($sessionId === null) {
            $stmt = $db->prepare(
                'SELECT id, session_id, event_name, payload
                 FROM realtime_events
                 WHERE id > ?
                 ORDER BY id ASC
                 LIMIT 100'
            );
            $stmt->execute([$lastId]);
        } else {
            // Tenant-wide events have a NULL session_id and are relevant to
            // every active session. Show-specific events only reach clients
            // attached to that exact session.
            $stmt = $db->prepare(
                'SELECT id, session_id, event_name, payload
                 FROM realtime_events
                 WHERE id > ? AND (session_id IS NULL OR session_id = ?)
                 ORDER BY id ASC
                 LIMIT 100'
            );
            $stmt->execute([$lastId, $sessionId]);
        }
        return array_map(static function (array $row): array {
            $row['session_id'] = $row['session_id'] !== null ? (int)$row['session_id'] : null;
            $row['payload'] = json_decode($row['payload'], true) ?: [];
            return $row;
        }, $stmt->fetchAll());
    }
}
