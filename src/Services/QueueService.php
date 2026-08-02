<?php

declare(strict_types=1);

namespace PanicMic\Services;

use PDO;

final class QueueService
{
    /** @return list<array<string,mixed>> */
    public static function queue(PDO $db, int $sessionId, ?PDO $superDb = null): array
    {
        $stmt = $db->prepare(
            "SELECT qi.id queue_item_id, qi.position, qi.status queue_status,
                    sr.id request_id, sr.party_type, sr.notes, sr.status request_status, sr.created_at, sr.updated_at status_updated_at,
                    sr.reviewed_at, sr.is_priority,
                    sr.youtube_video_id, sr.youtube_title, sr.youtube_channel_title, sr.youtube_url, sr.youtube_matched_at,
                    sr.manual_video_url, sr.manual_video_attached_at,
                    sr.song_id, sr.shared_song_id, sr.custom_song_title, sr.custom_song_artist,
                    s.id singer_id, s.display_name singer_name,
                    songs.title local_title, songs.artist local_artist, songs.genre local_genre, songs.decade local_decade,
                    songs.album local_album, songs.album_art_url local_album_art_url,
                    songs.video_url local_video_url, songs.provider_url local_provider_url, songs.video_provider local_video_provider,
                    (SELECT COUNT(*) FROM song_requests sr2 WHERE sr2.singer_id = sr.singer_id AND sr2.session_id = qi.session_id) singer_request_count
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
            // Catalog song → shared catalog → KJ-typed walk-up title.
            $row['title'] = $row['local_title']
                ?? ($shared['title'] ?? null)
                ?? ($row['custom_song_title'] ?: '(unknown song)');
            $row['artist'] = $row['local_artist']
                ?? ($shared['artist'] ?? null)
                ?? ($row['custom_song_artist'] ?: '');
            $row['genre'] = $row['local_genre'] ?? ($shared['genre'] ?? null);
            $row['decade'] = $row['local_decade'] ?? ($shared['decade'] ?? null);
            $row['album'] = $row['local_album'] ?? ($shared['album'] ?? null);
            $row['album_art_url'] = $row['local_album_art_url'] ?? ($shared['album_art_url'] ?? null);
            $row['video_url'] = $row['local_video_url'] ?? null;
            $row['provider_url'] = $row['local_provider_url'] ?? null;
            $row['video_provider'] = $row['local_video_provider'] ?? null;
            $row['song_source'] = !empty($row['song_id'])
                ? 'local'
                : (!empty($row['shared_song_id'])
                    ? 'shared'
                    : (!empty($row['custom_song_title']) ? 'walkup' : null));
            $row['is_new'] = ((int)($row['singer_request_count'] ?? 0)) <= 1;
            $row['is_priority'] = !empty($row['is_priority']);
            $row['is_incoming'] = $row['queue_status'] === 'pending' && empty($row['reviewed_at']);
            unset($row['local_title'], $row['local_artist'], $row['local_genre'], $row['local_decade'],
                  $row['local_album'], $row['local_album_art_url'],
                  $row['local_video_url'], $row['local_provider_url'], $row['local_video_provider'],
                  $row['singer_request_count']);
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
            // Serialize submissions for one karaoke session. This makes both
            // duplicate-name checks and MAX(position)+1 atomic under
            // concurrent public requests.
            $sessionLock = $db->prepare('SELECT id FROM karaoke_sessions WHERE id = ? FOR UPDATE');
            $sessionLock->execute([$sessionId]);
            if (!$sessionLock->fetchColumn()) {
                throw new \InvalidArgumentException('The karaoke session no longer exists');
            }

            if ($preventDuplicate) {
                // Limit is per singer name, not per device. This lets a shared
                // device (kiosk/iPad) serve multiple singers without blocking
                // them, while still preventing the same name from queuing up
                // twice at once.
                $nameCheck = $db->prepare(
                    "SELECT sr.id FROM song_requests sr
                     JOIN singers s ON s.id = sr.singer_id
                     WHERE s.session_id = ? AND s.display_name = ?
                       AND sr.status IN ('pending','up_next','now_singing')
                     LIMIT 1"
                );
                $nameCheck->execute([$sessionId, trim((string)$data['display_name'])]);
                if ($nameCheck->fetch()) {
                    throw new \RuntimeException('You already have an active request in the queue.');
                }
            }

            $name = trim((string)$data['display_name']);
            // Upsert the singer scoped to this session: reuse the row for
            // (session_id, display_name) and bump last_seen_at. The
            // LAST_INSERT_ID(id) trick makes lastInsertId() return the
            // existing id when the duplicate-key branch fires. Different
            // sessions can hold separate rows for the same display_name —
            // the same person across different nights should be tracked
            // per-night for stats and orphan cleanup.
            $db->prepare(
                'INSERT INTO singers (session_id, display_name, last_seen_at) VALUES (?, ?, NOW())
                 ON DUPLICATE KEY UPDATE last_seen_at = NOW(), id = LAST_INSERT_ID(id)'
            )->execute([$sessionId, $name]);
            $singerId = (int)$db->lastInsertId();

            return self::insertRequest($db, $sessionId, $singerId, [
                'song_id' => $songId,
                'shared_song_id' => $sharedSongId,
                'party_type' => $data['party_type'] ?? 'solo',
                'notes' => $data['notes'] ?? null,
                'requester_token' => $requesterToken,
            ]);
        });
    }

    /**
     * Upsert the singer row for this session and return its id.
     *
     * Shared by the public request flow and the KJ walk-up flow so both
     * track singers identically (per-session rows, bumped last_seen_at).
     */
    private static function upsertSinger(PDO $db, int $sessionId, string $displayName): int
    {
        $db->prepare(
            'INSERT INTO singers (session_id, display_name, last_seen_at) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE last_seen_at = NOW(), id = LAST_INSERT_ID(id)'
        )->execute([$sessionId, $displayName]);
        return (int)$db->lastInsertId();
    }

    /**
     * Insert the request row plus its queue item at the end of the rotation.
     * Must be called inside a transaction that holds the session lock.
     *
     * @param array<string,mixed> $fields
     */
    private static function insertRequest(PDO $db, int $sessionId, int $singerId, array $fields): int
    {
        $partyType = in_array($fields['party_type'] ?? 'solo', ['solo', 'duet', 'group'], true)
            ? (string)($fields['party_type'] ?? 'solo')
            : 'solo';

        $stmt = $db->prepare(
            'INSERT INTO song_requests
                (session_id, singer_id, song_id, shared_song_id, custom_song_title, custom_song_artist,
                 party_type, notes, requester_token, reviewed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $sessionId,
            $singerId,
            $fields['song_id'] ?? null,
            $fields['shared_song_id'] ?? null,
            $fields['custom_song_title'] ?? null,
            $fields['custom_song_artist'] ?? null,
            $partyType,
            trim((string)($fields['notes'] ?? '')) ?: null,
            $fields['requester_token'] ?? null,
            // Pre-reviewed requests skip the incoming tray entirely.
            !empty($fields['pre_reviewed']) ? date('Y-m-d H:i:s') : null,
        ]);
        $requestId = (int)$db->lastInsertId();

        $nextPosition = $db->prepare('SELECT COALESCE(MAX(position), 0) + 1 FROM queue_items WHERE session_id = ?');
        $nextPosition->execute([$sessionId]);
        $position = (int)$nextPosition->fetchColumn();
        $db->prepare('INSERT INTO queue_items (session_id, request_id, position) VALUES (?, ?, ?)')
           ->execute([$sessionId, $requestId, $position]);

        return $requestId;
    }

    /**
     * Add a walk-up singer on the KJ's behalf.
     *
     * Deliberately not the public submit() path. That one enforces rules
     * aimed at the room — an 8-per-minute rate limit, the requests-paused
     * gate, duplicate-name prevention, and a mandatory catalog match — all
     * of which are wrong for the person running the show:
     *
     *   - A KJ working through a stack of paper slips trips the rate limit.
     *   - Pausing public requests is exactly when the KJ still needs to add
     *     someone, so the pause gate blocked the one user it shouldn't.
     *   - A walk-up frequently names a song that isn't in either catalog.
     *   - The request landed as 'pending' in the incoming tray rather than
     *     the rotation, so the KJ added a singer and nothing appeared to
     *     happen.
     *
     * Walk-ups are therefore pre-reviewed (straight into the rotation) and
     * accept a free-text song. The KJ is the trusted operator here; the
     * guard rails that matter are still enforced (session must be live, the
     * singer needs a name, a song must be identified somehow).
     *
     * @param array<string,mixed> $data
     * @return array{requestId:int,singerId:int}
     */
    public static function submitWalkUp(PDO $db, int $sessionId, array $data, ?PDO $superDb = null): array
    {
        $name = trim((string)($data['display_name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('A singer name is required');
        }
        if (mb_strlen($name) > 160) {
            throw new \InvalidArgumentException('That singer name is too long (160 characters max)');
        }

        $songId = !empty($data['song_id']) ? (int)$data['song_id'] : null;
        $sharedSongId = !empty($data['shared_song_id']) ? (int)$data['shared_song_id'] : null;
        $customTitle = trim((string)($data['custom_song_title'] ?? ''));
        $customArtist = trim((string)($data['custom_song_artist'] ?? ''));

        if ($songId && $sharedSongId) {
            throw new \InvalidArgumentException('Pick exactly one song');
        }
        if (!$songId && !$sharedSongId && $customTitle === '') {
            throw new \InvalidArgumentException('Pick a song from the catalog or type a title');
        }
        if (mb_strlen($customTitle) > 200 || mb_strlen($customArtist) > 200) {
            throw new \InvalidArgumentException('Song title and artist are limited to 200 characters');
        }
        if ($songId && !SongService::find($db, $songId)) {
            throw new \InvalidArgumentException('Selected catalog song does not exist');
        }
        if ($sharedSongId && (!$superDb || !SharedCatalogService::exists($superDb, $sharedSongId))) {
            throw new \InvalidArgumentException('Selected shared song is not available');
        }

        // A catalog match wins; the typed text is only a fallback identity.
        if ($songId || $sharedSongId) {
            $customTitle = '';
            $customArtist = '';
        }

        return self::tx($db, function () use (
            $db, $sessionId, $name, $songId, $sharedSongId, $customTitle, $customArtist, $data
        ): array {
            $sessionLock = $db->prepare(
                "SELECT id FROM karaoke_sessions
                 WHERE id = ? AND status IN ('live','active','paused') FOR UPDATE"
            );
            $sessionLock->execute([$sessionId]);
            if (!$sessionLock->fetchColumn()) {
                throw new \InvalidArgumentException('Start the night before adding singers');
            }

            $singerId = self::upsertSinger($db, $sessionId, $name);
            $requestId = self::insertRequest($db, $sessionId, $singerId, [
                'song_id' => $songId,
                'shared_song_id' => $sharedSongId,
                'custom_song_title' => $customTitle !== '' ? $customTitle : null,
                'custom_song_artist' => $customArtist !== '' ? $customArtist : null,
                'party_type' => $data['party_type'] ?? 'solo',
                'notes' => $data['notes'] ?? null,
                // No requester_token: there is no phone to tie this to, so
                // the singer cannot self-cancel a KJ-entered walk-up.
                'requester_token' => null,
                'pre_reviewed' => true,
            ]);

            return ['requestId' => $requestId, 'singerId' => $singerId];
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

    /**
     * Set (or clear, when $url is null) a KJ-supplied external video link
     * on a request. Scoped to the session so a KJ can only touch requests
     * in their own room. Returns false when the request does not exist.
     */
    public static function setManualVideo(PDO $db, int $sessionId, int $requestId, ?string $url): bool
    {
        $exists = $db->prepare('SELECT 1 FROM song_requests WHERE id = ? AND session_id = ? LIMIT 1');
        $exists->execute([$requestId, $sessionId]);
        if (!$exists->fetchColumn()) {
            return false;
        }
        $db->prepare(
            'UPDATE song_requests
             SET manual_video_url = ?, manual_video_attached_at = ' . ($url === null ? 'NULL' : 'NOW()') . '
             WHERE id = ? AND session_id = ?'
        )->execute([$url, $requestId, $sessionId]);
        return true;
    }

    /** @param list<int> $requestIds */
    public static function reorder(PDO $db, int $sessionId, array $requestIds): void
    {
        self::tx($db, function () use ($db, $sessionId, $requestIds): void {
            self::applyOrder($db, $sessionId, $requestIds);
        });
    }

    /**
     * Approve a pending, unreviewed request into the rotation queue.
     * With $fastTrack, it's also moved to the front of the line (right
     * behind whatever's already up next / singing) instead of keeping its
     * arrival-order position. Returns false if the request isn't a
     * still-pending, unreviewed one in this session.
     */
    public static function approve(PDO $db, int $sessionId, int $requestId, bool $fastTrack = false): bool
    {
        return self::tx($db, function () use ($db, $sessionId, $requestId, $fastTrack): bool {
            $exists = $db->prepare(
                "SELECT 1 FROM song_requests WHERE id = ? AND session_id = ? AND status = 'pending' AND reviewed_at IS NULL LIMIT 1"
            );
            $exists->execute([$requestId, $sessionId]);
            if (!$exists->fetchColumn()) {
                return false;
            }
            $db->prepare('UPDATE song_requests SET reviewed_at = NOW() WHERE id = ? AND session_id = ?')
               ->execute([$requestId, $sessionId]);

            if ($fastTrack) {
                $ids = $db->prepare('SELECT request_id FROM queue_items WHERE session_id = ? ORDER BY position ASC');
                $ids->execute([$sessionId]);
                /** @var list<int> $order */
                $order = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN));
                $order = array_values(array_filter($order, static fn (int $id): bool => $id !== $requestId));
                array_unshift($order, $requestId);
                self::applyOrder($db, $sessionId, $order);
            }
            return true;
        });
    }

    /** Toggle the KJ-facing VIP/priority badge on a request. */
    public static function setPriority(PDO $db, int $sessionId, int $requestId, bool $priority): bool
    {
        $stmt = $db->prepare('UPDATE song_requests SET is_priority = ? WHERE id = ? AND session_id = ?');
        $stmt->execute([$priority ? 1 : 0, $requestId, $sessionId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Assign 1..N positions to the given request ids, in that order.
     * Setting final positions directly, one row at a time, can trip the
     * (session_id, position) unique index whenever the new order isn't a
     * pure append (e.g. swapping two adjacent rows collides with whichever
     * row hasn't moved yet). Bumping every row out of the target range
     * first guarantees no intermediate collision.
     *
     * @param list<int> $requestIds
     */
    private static function applyOrder(PDO $db, int $sessionId, array $requestIds): void
    {
        $current = $db->prepare(
            'SELECT request_id FROM queue_items WHERE session_id = ? ORDER BY position ASC FOR UPDATE'
        );
        $current->execute([$sessionId]);
        /** @var list<int> $existing */
        $existing = array_map('intval', $current->fetchAll(PDO::FETCH_COLUMN));
        if (!$existing) {
            return;
        }

        $valid = array_fill_keys($existing, true);
        $seen = [];
        $order = [];
        foreach ($requestIds as $requestId) {
            $requestId = (int)$requestId;
            if (isset($valid[$requestId]) && !isset($seen[$requestId])) {
                $order[] = $requestId;
                $seen[$requestId] = true;
            }
        }
        // A stale browser may omit a request that arrived moments earlier.
        // Keep those items in their prior relative order instead of leaving
        // them at an ever-growing temporary position.
        foreach ($existing as $requestId) {
            if (!isset($seen[$requestId])) {
                $order[] = $requestId;
            }
        }

        $db->prepare('UPDATE queue_items SET position = position + 1000000 WHERE session_id = ?')
           ->execute([$sessionId]);
        $stmt = $db->prepare('UPDATE queue_items SET position = ? WHERE session_id = ? AND request_id = ?');
        $position = 1;
        foreach ($order as $requestId) {
            $stmt->execute([$position++, $sessionId, $requestId]);
        }
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
