<?php

declare(strict_types=1);

namespace NextUp\Services;

use PDO;

final class DisplayService
{
    public const DEFAULT_SCREEN = 'main';

    /**
     * Allowed display modes. now_singing is whitelisted so the KJ can
     * explicitly fire the player; the front controller also bumps
     * display state to now_singing automatically when a request
     * transitions to that status.
     */
    private const MODES = ['idle', 'queue', 'now_singing', 'clean_stage', 'announcement'];

    /** @return array<string,mixed> */
    public static function state(PDO $db, int $sessionId, string $screen = self::DEFAULT_SCREEN): array
    {
        $stmt = $db->prepare(
            "SELECT ds.*, sr.id request_id, singers.display_name singer_name,
                    songs.title, songs.artist,
                    sr.youtube_video_id, sr.youtube_url, sr.youtube_title,
                    songs.video_url AS song_video_url, songs.video_provider, songs.provider_url,
                    a.message announcement
             FROM display_state ds
             LEFT JOIN song_requests sr ON sr.id = ds.now_request_id
             LEFT JOIN singers ON singers.id = sr.singer_id
             LEFT JOIN songs ON songs.id = sr.song_id
             LEFT JOIN announcements a ON a.id = ds.announcement_id
             WHERE ds.session_id = ? AND ds.screen = ?
             LIMIT 1"
        );
        $stmt->execute([$sessionId, $screen]);
        $row = $stmt->fetch();
        if ($row) {
            return $row;
        }
        // Ensure a row exists so subsequent updates have something to upsert.
        $db->prepare("INSERT IGNORE INTO display_state (session_id, screen, mode) VALUES (?, ?, 'idle')")
           ->execute([$sessionId, $screen]);
        return [
            'session_id' => $sessionId,
            'screen' => $screen,
            'mode' => 'idle',
        ];
    }

    /** @param array<string,mixed> $data */
    public static function update(PDO $db, int $sessionId, array $data, ?int $userId, string $screen = self::DEFAULT_SCREEN): void
    {
        $mode = $data['mode'] ?? 'queue';
        if (!in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException('Invalid display mode');
        }
        $stmt = $db->prepare(
            'INSERT INTO display_state (session_id, screen, mode, now_request_id, announcement_id, sponsor_slide_url, tip_qr_url, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               mode = VALUES(mode),
               now_request_id = VALUES(now_request_id),
               announcement_id = VALUES(announcement_id),
               sponsor_slide_url = VALUES(sponsor_slide_url),
               tip_qr_url = VALUES(tip_qr_url),
               updated_by = VALUES(updated_by)'
        );
        $stmt->execute([
            $sessionId,
            $screen,
            $mode,
            $data['now_request_id'] ?? null,
            $data['announcement_id'] ?? null,
            $data['sponsor_slide_url'] ?? null,
            $data['tip_qr_url'] ?? null,
            $userId,
        ]);
    }

    /**
     * List all configured screens for the session. If none have been
     * configured yet, returns a default "main" entry so callers always
     * have something to render.
     *
     * @return list<array<string,mixed>>
     */
    public static function listScreens(PDO $db, int $sessionId): array
    {
        $stmt = $db->prepare(
            'SELECT screen, label, layout, default_volume, show_qr, show_queue
             FROM display_screens
             WHERE session_id = ?
             ORDER BY screen ASC'
        );
        $stmt->execute([$sessionId]);
        $rows = $stmt->fetchAll();
        if (!$rows) {
            return [[
                'screen' => self::DEFAULT_SCREEN,
                'label' => 'Main projector',
                'layout' => 'main',
                'default_volume' => 80,
                'show_qr' => 1,
                'show_queue' => 1,
            ]];
        }
        return $rows;
    }

    /** @param array<string,mixed> $data */
    public static function upsertScreen(PDO $db, int $sessionId, array $data): void
    {
        $screen = preg_replace('/[^a-z0-9_-]/i', '', (string)($data['screen'] ?? ''));
        if (!$screen) {
            throw new \InvalidArgumentException('Screen id is required');
        }
        $layout = in_array($data['layout'] ?? '', ['main', 'lyrics', 'lobby', 'stage', 'custom'], true)
            ? (string)$data['layout']
            : 'main';
        $stmt = $db->prepare(
            'INSERT INTO display_screens (session_id, screen, label, layout, default_volume, show_qr, show_queue)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               label = VALUES(label),
               layout = VALUES(layout),
               default_volume = VALUES(default_volume),
               show_qr = VALUES(show_qr),
               show_queue = VALUES(show_queue)'
        );
        $stmt->execute([
            $sessionId,
            $screen,
            trim((string)($data['label'] ?? $screen)),
            $layout,
            max(0, min(100, (int)($data['default_volume'] ?? 80))),
            !empty($data['show_qr']) ? 1 : 0,
            !empty($data['show_queue']) ? 1 : 0,
        ]);
    }

    public static function removeScreen(PDO $db, int $sessionId, string $screen): void
    {
        $db->prepare('DELETE FROM display_screens WHERE session_id = ? AND screen = ?')
           ->execute([$sessionId, $screen]);
        $db->prepare('DELETE FROM display_state WHERE session_id = ? AND screen = ?')
           ->execute([$sessionId, $screen]);
    }
}
