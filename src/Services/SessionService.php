<?php

declare(strict_types=1);

namespace NextUp\Services;

use PDO;

final class SessionService
{
    /** @return array<string,mixed> */
    public static function current(PDO $db, string $fallbackName): array
    {
        $session = $db->query("SELECT * FROM karaoke_sessions WHERE status IN ('active','paused') ORDER BY starts_at DESC LIMIT 1")->fetch();
        if ($session) {
            return $session;
        }

        $stmt = $db->prepare("INSERT INTO karaoke_sessions (name, starts_at, status) VALUES (?, NOW(), 'active')");
        $stmt->execute([$fallbackName]);
        $id = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO display_state (session_id, mode) VALUES (?, 'idle')")->execute([$id]);
        return ['id' => $id, 'name' => $fallbackName, 'status' => 'active', 'requests_paused' => 0, 'queue_locked' => 0];
    }
}
