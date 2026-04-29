<?php

declare(strict_types=1);

namespace NextUp\Services;

use PDO;

final class QueueService
{
    /** @return list<array<string,mixed>> */
    public static function queue(PDO $db, int $sessionId): array
    {
        $stmt = $db->prepare(
            "SELECT qi.id queue_item_id, qi.position, qi.status queue_status,
                    sr.id request_id, sr.party_type, sr.notes, sr.status request_status, sr.created_at,
                    sr.youtube_video_id, sr.youtube_title, sr.youtube_channel_title, sr.youtube_url, sr.youtube_matched_at,
                    s.id singer_id, s.display_name singer_name,
                    songs.id song_id, songs.title, songs.artist, songs.genre, songs.decade
             FROM queue_items qi
             JOIN song_requests sr ON sr.id = qi.request_id
             JOIN singers s ON s.id = sr.singer_id
             JOIN songs ON songs.id = sr.song_id
             WHERE qi.session_id = ?
             ORDER BY qi.position ASC"
        );
        $stmt->execute([$sessionId]);
        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public static function requestSong(PDO $db, int $requestId, ?int $sessionId = null): ?array
    {
        $where = 'sr.id = ?';
        $params = [$requestId];
        if ($sessionId !== null) {
            $where .= ' AND sr.session_id = ?';
            $params[] = $sessionId;
        }
        $stmt = $db->prepare(
            'SELECT sr.id request_id, songs.id song_id, songs.title, songs.artist
             FROM song_requests sr
             JOIN songs ON songs.id = sr.song_id
             WHERE ' . $where . '
             LIMIT 1'
        );
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @param array<string,mixed> $data */
    public static function submit(PDO $db, int $sessionId, array $data, string $requesterToken, bool $preventDuplicate): int
    {
        return self::tx($db, function () use ($db, $sessionId, $data, $requesterToken, $preventDuplicate): int {
            if ($preventDuplicate) {
                $check = $db->prepare("SELECT id FROM song_requests WHERE session_id = ? AND requester_token = ? AND status IN ('pending','up_next','now_singing') LIMIT 1");
                $check->execute([$sessionId, $requesterToken]);
                if ($check->fetch()) {
                    throw new \RuntimeException('You already have an active request in the queue.');
                }
            }

            $name = trim((string)$data['display_name']);
            $stmt = $db->prepare('INSERT INTO singers (display_name) VALUES (?)');
            $stmt->execute([$name]);
            $singerId = (int)$db->lastInsertId();

            $stmt = $db->prepare('INSERT INTO song_requests (session_id, singer_id, song_id, party_type, notes, requester_token) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $sessionId,
                $singerId,
                (int)$data['song_id'],
                in_array($data['party_type'] ?? 'solo', ['solo', 'duet', 'group'], true) ? $data['party_type'] : 'solo',
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
