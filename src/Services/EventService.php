<?php

declare(strict_types=1);

namespace PanicMic\Services;

use DateTimeImmutable;
use PDO;

/**
 * Concrete calendar entries (`events`): one-off shows the KJ adds by hand
 * plus the occurrences materialized from recurring schedules. Also the
 * read side for the public events page (upcoming nights + past setlists).
 */
final class EventService
{
    /** @return array<string,mixed>|null */
    public static function find(PDO $db, int $id): ?array
    {
        $stmt = $db->prepare(
            'SELECT e.*, v.name AS venue_name, v.city AS venue_city, v.region AS venue_region
             FROM events e JOIN venues v ON v.id = e.venue_id
             WHERE e.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Events within an inclusive date range, for the KJ calendar.
     *
     * @return list<array<string,mixed>>
     */
    public static function range(PDO $db, string $from, string $to): array
    {
        $stmt = $db->prepare(
            "SELECT e.*, v.name AS venue_name
             FROM events e JOIN venues v ON v.id = e.venue_id
             WHERE e.scheduled_for >= ? AND e.scheduled_for < DATE_ADD(?, INTERVAL 1 DAY)
             ORDER BY e.scheduled_for ASC"
        );
        $stmt->execute([$from, $to]);
        return $stmt->fetchAll();
    }

    /**
     * Upcoming nights for the public page (and the dashboard quick-pick).
     *
     * @return list<array<string,mixed>>
     */
    public static function upcoming(PDO $db, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $db->prepare(
            "SELECT e.id, e.name, e.scheduled_for, e.status,
                    v.name AS venue_name, v.city AS venue_city, v.region AS venue_region
             FROM events e JOIN venues v ON v.id = e.venue_id
             WHERE e.status IN ('scheduled','live')
               AND e.scheduled_for >= DATE_SUB(NOW(), INTERVAL 12 HOUR)
             ORDER BY e.scheduled_for ASC
             LIMIT {$limit}"
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Past nights that actually ran (closed sessions are the ground truth),
     * joined to their venue + event when known.
     *
     * @return list<array<string,mixed>>
     */
    public static function past(PDO $db, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $db->prepare(
            "SELECT ks.id AS session_id, ks.name, ks.starts_at, ks.ends_at,
                    v.name AS venue_name, v.city AS venue_city, v.region AS venue_region,
                    (SELECT COUNT(*) FROM song_requests sr
                       WHERE sr.session_id = ks.id AND sr.status = 'completed') AS songs_count
             FROM karaoke_sessions ks
             LEFT JOIN venues v ON v.id = ks.venue_id
             WHERE ks.status = 'closed'
             ORDER BY COALESCE(ks.ends_at, ks.starts_at) DESC
             LIMIT {$limit}"
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * The setlist for a finished night: who sang what. Resolves both
     * local-catalog and shared-catalog songs (mirrors QueueService).
     *
     * @return array<string,mixed>|null Session header + performances, or null if unknown.
     */
    public static function setlistFor(PDO $db, int $sessionId, ?PDO $superDb = null): ?array
    {
        $header = $db->prepare(
            'SELECT ks.id AS session_id, ks.name, ks.starts_at, ks.ends_at, ks.status,
                    v.name AS venue_name, v.city AS venue_city, v.region AS venue_region
             FROM karaoke_sessions ks
             LEFT JOIN venues v ON v.id = ks.venue_id
             WHERE ks.id = ? LIMIT 1'
        );
        $header->execute([$sessionId]);
        $session = $header->fetch();
        if (!$session) {
            return null;
        }

        $stmt = $db->prepare(
            "SELECT sr.id, sr.song_id, sr.shared_song_id, sr.status,
                    s.display_name AS singer_name,
                    songs.title AS local_title, songs.artist AS local_artist
             FROM song_requests sr
             JOIN singers s ON s.id = sr.singer_id
             LEFT JOIN songs ON songs.id = sr.song_id
             WHERE sr.session_id = ? AND sr.status = 'completed'
             ORDER BY sr.updated_at ASC, sr.id ASC"
        );
        $stmt->execute([$sessionId]);
        $rows = $stmt->fetchAll();

        $sharedIds = [];
        foreach ($rows as $row) {
            if (!empty($row['shared_song_id'])) {
                $sharedIds[] = (int)$row['shared_song_id'];
            }
        }
        $sharedById = $superDb && $sharedIds
            ? SharedCatalogService::findMany($superDb, $sharedIds)
            : [];

        $performances = array_map(static function (array $row) use ($sharedById): array {
            $shared = !empty($row['shared_song_id']) ? ($sharedById[(int)$row['shared_song_id']] ?? null) : null;
            return [
                'singer_name' => $row['singer_name'],
                'title' => $row['local_title'] ?? ($shared['title'] ?? '(unknown song)'),
                'artist' => $row['local_artist'] ?? ($shared['artist'] ?? ''),
            ];
        }, $rows);

        $session['performances'] = $performances;
        return $session;
    }

    /**
     * Create a one-off event (schedule_id = NULL).
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function createOneOff(PDO $db, array $data, ?int $createdBy = null): array
    {
        $venueId = (int)($data['venue_id'] ?? 0);
        if ($venueId <= 0) {
            throw new \InvalidArgumentException('A venue is required');
        }
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('An event name is required');
        }
        $scheduledFor = self::normalizeDateTime((string)($data['scheduled_for'] ?? ''));
        if ($scheduledFor === null) {
            throw new \InvalidArgumentException('A valid date and time is required');
        }
        $db->prepare(
            'INSERT INTO events (venue_id, schedule_id, name, scheduled_for, status, created_by)
             VALUES (?, NULL, ?, ?, ?, ?)'
        )->execute([$venueId, $name, $scheduledFor, 'scheduled', $createdBy]);
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
        $fields = [];
        if (array_key_exists('name', $data)) {
            $name = trim((string)$data['name']);
            if ($name === '') {
                throw new \InvalidArgumentException('An event name is required');
            }
            $fields['name'] = $name;
        }
        if (array_key_exists('scheduled_for', $data)) {
            $dt = self::normalizeDateTime((string)$data['scheduled_for']);
            if ($dt === null) {
                throw new \InvalidArgumentException('A valid date and time is required');
            }
            $fields['scheduled_for'] = $dt;
        }
        if (array_key_exists('venue_id', $data)) {
            $fields['venue_id'] = (int)$data['venue_id'];
        }
        if (array_key_exists('status', $data)) {
            $status = (string)$data['status'];
            if (!in_array($status, ['scheduled', 'live', 'closed', 'canceled'], true)) {
                throw new \InvalidArgumentException('Invalid event status');
            }
            $fields['status'] = $status;
        }
        if ($fields) {
            $assignments = implode(', ', array_map(static fn (string $c): string => "{$c} = ?", array_keys($fields)));
            $params = array_values($fields);
            $params[] = $id;
            $db->prepare("UPDATE events SET {$assignments} WHERE id = ?")->execute($params);
        }
        return self::find($db, $id);
    }

    /** Cancel a single occurrence; the unique key keeps re-materialize from resurrecting it. */
    public static function cancel(PDO $db, int $id): bool
    {
        if (!self::find($db, $id)) {
            return false;
        }
        $db->prepare("UPDATE events SET status = 'canceled' WHERE id = ?")->execute([$id]);
        return true;
    }

    private static function normalizeDateTime(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        // Accept the HTML datetime-local format (Y-m-dTH:i) plus standard
        // SQL datetimes.
        $value = str_replace('T', ' ', $value);
        try {
            $dt = new DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
        return $dt->format('Y-m-d H:i:s');
    }
}
