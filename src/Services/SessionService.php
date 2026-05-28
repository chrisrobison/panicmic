<?php

declare(strict_types=1);

namespace NextUp\Services;

use PDO;

final class SessionService
{
    /** @return array<string,mixed>|null */
    public static function active(PDO $db): ?array
    {
        $row = $db->query(
            "SELECT * FROM karaoke_sessions
             WHERE status IN ('active','paused')
             ORDER BY starts_at DESC LIMIT 1"
        )->fetch();
        return $row ?: null;
    }

    /**
     * Returns the active session, creating one with the fallback name
     * if none exists. Used by the front controller to bootstrap a
     * session on first hit so existing flows keep working.
     *
     * @return array<string,mixed>
     */
    public static function current(PDO $db, string $fallbackName): array
    {
        $active = self::active($db);
        if ($active) {
            return $active;
        }
        return self::start($db, $fallbackName);
    }

    /**
     * Start a new session. Any other 'active' or 'paused' sessions are
     * archived first so only one session is live at a time.
     *
     * @return array<string,mixed>
     */
    public static function start(PDO $db, string $name): array
    {
        $db->exec("UPDATE karaoke_sessions SET status = 'archived', ends_at = COALESCE(ends_at, NOW()) WHERE status IN ('active', 'paused')");

        $stmt = $db->prepare("INSERT INTO karaoke_sessions (name, starts_at, status) VALUES (?, NOW(), 'active')");
        $stmt->execute([$name]);
        $id = (int)$db->lastInsertId();

        $db->prepare("INSERT INTO display_state (session_id, mode) VALUES (?, 'idle')")->execute([$id]);

        $row = $db->prepare('SELECT * FROM karaoke_sessions WHERE id = ?');
        $row->execute([$id]);
        return $row->fetch() ?: ['id' => $id, 'name' => $name, 'status' => 'active'];
    }

    /**
     * End the given session: archive status, stamp ends_at, snapshot
     * stats to audit_log for the night's record.
     */
    public static function end(PDO $db, int $sessionId, ?int $actorUserId = null): void
    {
        $stats = self::statsFor($db, $sessionId);

        $db->prepare(
            "UPDATE karaoke_sessions SET status = 'archived', ends_at = NOW() WHERE id = ?"
        )->execute([$sessionId]);

        $db->prepare(
            "UPDATE display_state SET mode = 'idle' WHERE session_id = ?"
        )->execute([$sessionId]);

        try {
            // MariaDB doesn't support CAST(? AS JSON); plain string
            // binding works against the JSON_VALID-protected JSON column
            // on both MariaDB and MySQL 8.
            $db->prepare(
                'INSERT INTO audit_log (user_id, action, entity_type, entity_id, metadata)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([
                $actorUserId,
                'session.ended',
                'karaoke_session',
                $sessionId,
                json_encode($stats, JSON_THROW_ON_ERROR),
            ]);
        } catch (\Throwable) {
            // audit_log schema variations across tenants — best-effort.
        }
    }

    /** @return array<string,int> */
    public static function statsFor(PDO $db, int $sessionId): array
    {
        $stmt = $db->prepare(
            "SELECT status, COUNT(*) c
             FROM queue_items
             WHERE session_id = ?
             GROUP BY status"
        );
        $stmt->execute([$sessionId]);
        $out = [
            'session_id' => $sessionId,
            'pending' => 0, 'up_next' => 0, 'now_singing' => 0,
            'completed' => 0, 'skipped' => 0, 'canceled' => 0, 'total' => 0,
        ];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string)$row['status']] = (int)$row['c'];
            $out['total'] += (int)$row['c'];
        }
        return $out;
    }
}
