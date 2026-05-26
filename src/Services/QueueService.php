<?php

declare(strict_types=1);

namespace NextUp\Services;

use PDO;

final class QueueService
{
    /** @return list<array<string,mixed>> */
    public static function queue(PDO $db, int $sessionId, ?PDO $superDb = null): array
    {
        $stmt = $db->prepare(
            "SELECT qi.id queue_item_id, qi.position, qi.status queue_status,
                    sr.id request_id, sr.party_type, sr.notes, sr.status request_status, sr.created_at,
                    sr.youtube_video_id, sr.youtube_title, sr.youtube_channel_title, sr.youtube_url, sr.youtube_matched_at,
                    sr.song_id, sr.shared_song_id,
                    s.id singer_id, s.display_name singer_name,
                    songs.title local_title, songs.artist local_artist, songs.genre local_genre, songs.decade local_decade
             FROM queue_items qi
             JOIN song_requests sr ON sr.id = qi.request_id
             JOIN singers s ON s.id = sr.singer_id
             LEFT JOIN songs ON songs.id = sr.song_id
             WHERE qi.session_id = ?
             ORDER BY qi.position ASC"
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

        return array_map(static function (array $row) use ($sharedById): array {
            $shared = !empty($row['shared_song_id']) ? ($sharedById[(int)$row['shared_song_id']] ?? null) : null;
            $row['title'] = $row['local_title'] ?? ($shared['title'] ?? '(unknown song)');
            $row['artist'] = $row['local_artist'] ?? ($shared['artist'] ?? '');
            $row['genre'] = $row['local_genre'] ?? ($shared['genre'] ?? null);
            $row['decade'] = $row['local_decade'] ?? ($shared['decade'] ?? null);
            $row['song_source'] = !empty($row['song_id']) ? 'local' : (!empty($row['shared_song_id']) ? 'shared' : null);
            unset($row['local_title'], $row['local_artist'], $row['local_genre'], $row['local_decade']);
            return $row;
        }, $rows);
    }

    /** @return array<string,mixed>|null */
    public static function requestSong(PDO $db, int $requestId, ?PDO $superDb = null, ?int $sessionId = null): ?array
    {
        $where = 'sr.id = ?';
        $params = [$requestId];
        if ($sessionId !== null) {
            $where .= ' AND sr.session_id = ?';
            $params[] = $sessionId;
        }
        $stmt = $db->prepare(
            'SELECT sr.id request_id, sr.song_id, sr.shared_song_id,
                    songs.title local_title, songs.artist local_artist
             FROM song_requests sr
             LEFT JOIN songs ON songs.id = sr.song_id
             WHERE ' . $where . '
             LIMIT 1'
        );
        $stmt->execute($params);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        if (!empty($row['song_id']) && !empty($row['local_title'])) {
            return [
                'request_id' => (int)$row['request_id'],
                'song_id' => (int)$row['song_id'],
                'title' => $row['local_title'],
                'artist' => $row['local_artist'],
                'source' => 'local',
            ];
        }
        if (!empty($row['shared_song_id']) && $superDb) {
            $shared = SharedCatalogService::find($superDb, (int)$row['shared_song_id']);
            if ($shared) {
                return [
                    'request_id' => (int)$row['request_id'],
                    'shared_song_id' => (int)$shared['id'],
                    'title' => $shared['title'],
                    'artist' => $shared['artist'],
                    'source' => 'shared',
                ];
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function submit(PDO $db, int $sessionId, array $data, string $requesterToken, bool $preventDuplicate, ?PDO $superDb = null): int
    {
        $songId = !empty($data['song_id']) ? (int)$data['song_id'] : null;
        $sharedSongId = !empty($data['shared_song_id']) ? (int)$data['shared_song_id'] : null;
        if (!$songId && !$sharedSongId) {
            throw new \InvalidArgumentException('A song selection is required');
        }
        if ($songId && $sharedSongId) {
            throw new \InvalidArgumentException('Pick exactly one song');
        }
        if ($songId && !SongService::find($db, $songId)) {
            throw new \InvalidArgumentException('Selected catalog song does not exist');
        }
        if ($sharedSongId) {
            if (!$superDb || !SharedCatalogService::exists($superDb, $sharedSongId)) {
                throw new \InvalidArgumentException('Selected shared song is not available');
            }
        }

        return self::tx($db, function () use ($db, $sessionId, $data, $requesterToken, $preventDuplicate, $songId, $sharedSongId): int {
            if ($preventDuplicate) {
                $check = $db->prepare("SELECT id FROM song_requests WHERE session_id = ? AND requester_token = ? AND status IN ('pending','up_next','now_singing') LIMIT 1");
                $check->execute([$sessionId, $requesterToken]);
                if ($check->fetch()) {
                    throw new \RuntimeException('You already have an active request in the queue.');
                }
            }

            $name = trim((string)$data['display_name']);
            // Upsert the singer: reuse the existing row for the same
            // display_name and bump last_seen_at. The LAST_INSERT_ID(id)
            // trick makes lastInsertId() return the existing id when the
            // duplicate-key branch fires.
            $db->prepare(
                'INSERT INTO singers (display_name, last_seen_at) VALUES (?, NOW())
                 ON DUPLICATE KEY UPDATE last_seen_at = NOW(), id = LAST_INSERT_ID(id)'
            )->execute([$name]);
            $singerId = (int)$db->lastInsertId();

            $stmt = $db->prepare('INSERT INTO song_requests (session_id, singer_id, song_id, shared_song_id, party_type, notes, requester_token) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $sessionId,
                $singerId,
                $songId,
                $sharedSongId,
                in_array($data['party_type'] ?? 'solo', ['solo', 'duet', 'group'], true) ? ($data['party_type'] ?? 'solo') : 'solo',
                trim((string)($data['notes'] ?? '')) ?: null,
                $requesterToken,
            ]);
            $requestId = (int)$db->lastInsertId();

            $position = (int)$db->query("SELECT COALESCE(MAX(position), 0) + 1 next_position FROM queue_items WHERE session_id = {$sessionId}")->fetchColumn();
            $stmt = $db->prepare('INSERT INTO queue_items (session_id, request_id, position) VALUES (?, ?, ?)');
            $stmt->execute([$sessionId, $requestId, $position]);
            return $requestId;
        });
    }

    public static function setStatus(PDO $db, int $sessionId, int $requestId, string $status): void
    {
        if (!in_array($status, ['pending', 'up_next', 'now_singing', 'completed', 'skipped', 'canceled'], true)) {
            throw new \InvalidArgumentException('Invalid request status');
        }
        self::tx($db, function () use ($db, $sessionId, $requestId, $status): void {
            if ($status === 'now_singing') {
                $db->prepare("UPDATE song_requests SET status = 'completed' WHERE session_id = ? AND status = 'now_singing' AND id <> ?")->execute([$sessionId, $requestId]);
                $db->prepare("UPDATE queue_items SET status = 'completed' WHERE session_id = ? AND status = 'now_singing' AND request_id <> ?")->execute([$sessionId, $requestId]);
            }
            $db->prepare('UPDATE song_requests SET status = ? WHERE id = ? AND session_id = ?')->execute([$status, $requestId, $sessionId]);
            $db->prepare('UPDATE queue_items SET status = ? WHERE request_id = ? AND session_id = ?')->execute([$status, $requestId, $sessionId]);
        });
    }

    /** @param list<int> $requestIds */
    public static function reorder(PDO $db, int $sessionId, array $requestIds): void
    {
        self::tx($db, function () use ($db, $sessionId, $requestIds): void {
            $stmt = $db->prepare('UPDATE queue_items SET position = ? WHERE session_id = ? AND request_id = ?');
            $position = 1;
            foreach ($requestIds as $requestId) {
                $stmt->execute([$position++, $sessionId, $requestId]);
            }
        });
    }

    private static function tx(PDO $db, callable $callback): mixed
    {
        $db->beginTransaction();
        try {
            $result = $callback();
            $db->commit();
            return $result;
        } catch (\Throwable $error) {
            $db->rollBack();
            throw $error;
        }
    }
}
