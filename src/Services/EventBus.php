<?php

declare(strict_types=1);

namespace PanicMic\Services;

use PanicMic\Support\ErrorReporter;
use PDO;
use Throwable;

/**
 * Best-effort realtime fan-out.
 *
 * The event bus is a *notification* channel, not a system of record: the
 * queue, display state, and catalog all live in their own tables and are
 * already committed by the time we publish. A broken bus must therefore
 * degrade to "clients refresh a little later" and never fail the business
 * write that triggered it.
 *
 * This is not hypothetical. Migration 014 added realtime_events.session_id;
 * until it was applied, every publish() threw and took all 43 of its call
 * sites down with it — adding a singer, approving a request, changing
 * display state. The whole app looked broken because a notification insert
 * failed. Hence: publish() and after() swallow and log their own errors.
 */
final class EventBus
{
    /** Drop events older than this on each publish to bound table size. */
    public const RETENTION_INTERVAL = '1 HOUR';

    /**
     * Publish an event. Returns the new event id, or 0 when publishing
     * failed — callers must treat 0 as "not delivered", never as an error
     * worth aborting their own work for.
     *
     * @param array<string,mixed>|list<mixed> $payload
     */
    public static function publish(PDO $db, string $event, array $payload, ?int $sessionId = null): int
    {
        try {
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
        } catch (Throwable $error) {
            ErrorReporter::report($error, "EventBus::publish failed for '{$event}' (realtime degraded, write preserved)");
            return 0;
        }
    }

    /**
     * Read events after $lastId. Returns an empty list when the read fails,
     * so a degraded bus stalls realtime updates rather than 500-ing the
     * poll/SSE endpoint that called it.
     *
     * @return list<array<string,mixed>>
     */
    public static function after(PDO $db, int $lastId, ?int $sessionId = null): array
    {
        try {
            return self::query($db, $lastId, $sessionId);
        } catch (Throwable $error) {
            ErrorReporter::report($error, 'EventBus::after failed (realtime degraded)');
            return [];
        }
    }

    /** @return list<array<string,mixed>> */
    private static function query(PDO $db, int $lastId, ?int $sessionId): array
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
