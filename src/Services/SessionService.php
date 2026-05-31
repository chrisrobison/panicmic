<?php

declare(strict_types=1);

namespace PanicMic\Services;

use PDO;

final class SessionService
{
    /** @return array<string,mixed>|null */
    public static function active(PDO $db): ?array
    {
        // The current lifecycle is draft → live → closed. Legacy databases
        // that haven't yet run migration 007 may still carry the old
        // ('scheduled','active','paused','archived') vocabulary, so accept
        // both 'live' and the legacy 'active'/'paused' values here.
        $row = $db->query(
            "SELECT * FROM karaoke_sessions
             WHERE status IN ('live','active','paused')
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
     * Read-only lookup: returns the most-recent session (live or closed)
     * without creating one. Used by public routes that should surface a
     * "We're closed for tonight" banner instead of silently spinning up
     * a fresh session whenever a visitor lands on the page.
     *
     * @return array<string,mixed>
     */
    public static function latest(PDO $db, string $fallbackName): array
    {
        $active = self::active($db);
        if ($active) {
            return $active;
        }
        $row = $db->query(
            "SELECT * FROM karaoke_sessions
             ORDER BY COALESCE(ends_at, starts_at) DESC LIMIT 1"
        )->fetch();
        if ($row) {
            return $row;
        }
        // Truly fresh tenant — no sessions ever recorded. Return a synthetic
        // 'closed' marker so the UI shows the closed banner rather than a
        // broken page; the first KJ action will create the real row.
        return [
            'id' => 0,
            'name' => $fallbackName,
            'status' => 'closed',
            'starts_at' => null,
            'ends_at' => null,
        ];
    }

    /**
     * Start a new session. Any other 'active' or 'paused' sessions are
     * archived first so only one session is live at a time (per account).
     * Optionally tags the session with the venue + scheduled event it was
     * started for; when an event is given it is flipped to 'live' and
     * linked back to the new session.
     *
     * @return array<string,mixed>
     */
    public static function start(PDO $db, string $name, ?int $venueId = null, ?int $eventId = null): array
    {
        // Close any in-flight sessions before starting a new one.
        $db->exec("UPDATE karaoke_sessions SET status = 'closed', ends_at = COALESCE(ends_at, NOW()) WHERE status IN ('live','active','paused')");

        $stmt = $db->prepare("INSERT INTO karaoke_sessions (name, venue_id, event_id, starts_at, status) VALUES (?, ?, ?, NOW(), 'live')");
        $stmt->execute([$name, $venueId, $eventId]);
        $id = (int)$db->lastInsertId();

        $db->prepare("INSERT INTO display_state (session_id, mode) VALUES (?, 'idle')")->execute([$id]);

        if ($eventId !== null) {
            $db->prepare("UPDATE events SET status = 'live', session_id = ? WHERE id = ?")
                ->execute([$id, $eventId]);
        }

        $row = $db->prepare('SELECT * FROM karaoke_sessions WHERE id = ?');
        $row->execute([$id]);
        return $row->fetch() ?: ['id' => $id, 'name' => $name, 'status' => 'live'];
    }

    /**
     * End the given session: archive status, stamp ends_at, snapshot
     * stats to audit_log for the night's record.
     */
    public static function end(PDO $db, int $sessionId, ?int $actorUserId = null): void
    {
        $stats = self::statsFor($db, $sessionId);

        $db->prepare(
            "UPDATE karaoke_sessions SET status = 'closed', ends_at = NOW() WHERE id = ?"
        )->execute([$sessionId]);

        $db->prepare(
            "UPDATE display_state SET mode = 'idle' WHERE session_id = ?"
        )->execute([$sessionId]);

        // Close the linked scheduled event, if any, so the calendar and
        // public page reflect that the night has finished.
        $db->prepare(
            "UPDATE events SET status = 'closed' WHERE session_id = ? AND status IN ('scheduled','live')"
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
