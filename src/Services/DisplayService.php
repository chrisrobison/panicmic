<?php

declare(strict_types=1);

namespace NextUp\Services;

use PDO;

final class DisplayService
{
    /** @return array<string,mixed> */
    public static function state(PDO $db, int $sessionId): array
    {
        $stmt = $db->prepare(
            "SELECT ds.*, sr.id request_id, singers.display_name singer_name, songs.title, songs.artist, a.message announcement
             FROM display_state ds
             LEFT JOIN song_requests sr ON sr.id = ds.now_request_id
             LEFT JOIN singers ON singers.id = sr.singer_id
             LEFT JOIN songs ON songs.id = sr.song_id
             LEFT JOIN announcements a ON a.id = ds.announcement_id
             WHERE ds.session_id = ?"
        );
        $stmt->execute([$sessionId]);
        return $stmt->fetch() ?: ['mode' => 'idle'];
    }

    /** @param array<string,mixed> $data */
    public static function update(PDO $db, int $sessionId, array $data, ?int $userId): void
    {
        $mode = $data['mode'] ?? 'queue';
        if (!in_array($mode, ['idle', 'queue', 'now_singing', 'clean_stage', 'announcement'], true)) {
            throw new \InvalidArgumentException('Invalid display mode');
        }
        $stmt = $db->prepare(
            'INSERT INTO display_state (session_id, mode, now_request_id, announcement_id, sponsor_slide_url, tip_qr_url, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE mode = VALUES(mode), now_request_id = VALUES(now_request_id), announcement_id = VALUES(announcement_id),
             sponsor_slide_url = VALUES(sponsor_slide_url), tip_qr_url = VALUES(tip_qr_url), updated_by = VALUES(updated_by)'
        );
        $stmt->execute([
            $sessionId,
            $mode,
            $data['now_request_id'] ?? null,
            $data['announcement_id'] ?? null,
            $data['sponsor_slide_url'] ?? null,
            $data['tip_qr_url'] ?? null,
            $userId,
        ]);
    }
}
