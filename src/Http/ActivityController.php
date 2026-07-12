<?php

declare(strict_types=1);

namespace PanicMic\Http;

use PanicMic\Auth\Auth;
use PanicMic\Database\Connection;
use PanicMic\Services\SharedCatalogService;
use PanicMic\Support\Response;
use PDO;

/**
 * Human-readable activity feed for the KJ dashboard, built entirely from
 * the realtime_events table that EventBus already writes on every
 * meaningful action. No new storage — this just formats what's already
 * there and filters out the chatty/technical events (queue:updated,
 * display:cue, display:play_at, clock pings, ...).
 */
final class ActivityController
{
    private const VISIBLE = [
        'request:created', 'request:approved', 'request:status_changed',
        'request:youtube_attached', 'request:manual_video',
        'request:video_cached', 'request:video_cache_failed',
        'display:state_changed', 'announcement:shown',
    ];

    /** @param array<string,mixed> $tenant @param array<string,mixed> $session */
    public static function index(PDO $db, array $tenant, array $session): never
    {
        Auth::requireTenantRole('kj', 'tenant_admin');
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 40)));

        $placeholders = implode(',', array_fill(0, count(self::VISIBLE), '?'));
        $stmt = $db->prepare(
            "SELECT id, event_name, payload, created_at FROM realtime_events
             WHERE event_name IN ($placeholders)
             ORDER BY id DESC LIMIT ?"
        );
        $i = 1;
        foreach (self::VISIBLE as $name) {
            $stmt->bindValue($i++, $name, PDO::PARAM_STR);
        }
        $stmt->bindValue($i, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $requestIds = [];
        $decoded = [];
        foreach ($rows as $row) {
            $payload = json_decode((string)$row['payload'], true) ?: [];
            $decoded[(int)$row['id']] = $payload;
            if (!empty($payload['requestId'])) {
                $requestIds[] = (int)$payload['requestId'];
            }
        }
        $context = self::loadRequestContext($db, array_values(array_unique($requestIds)));
        $screens = self::loadScreenLabels($db, (int)$session['id']);

        $items = [];
        foreach ($rows as $row) {
            $line = self::format((string)$row['event_name'], $decoded[(int)$row['id']] ?? [], $context, $screens);
            if ($line === null) {
                continue;
            }
            $items[] = [
                'id' => (int)$row['id'],
                'event' => $row['event_name'],
                'message' => $line,
                'created_at' => $row['created_at'],
            ];
        }

        Response::json(['activity' => $items]);
    }

    /**
     * @param list<int> $requestIds
     * @return array<int,array{singer:string,title:?string,shared_song_id:?int}>
     */
    private static function loadRequestContext(PDO $db, array $requestIds): array
    {
        if (!$requestIds) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($requestIds), '?'));
        $stmt = $db->prepare(
            "SELECT sr.id, s.display_name singer_name, songs.title, sr.shared_song_id
             FROM song_requests sr
             JOIN singers s ON s.id = sr.singer_id
             LEFT JOIN songs ON songs.id = sr.song_id
             WHERE sr.id IN ($placeholders)"
        );
        $stmt->execute($requestIds);

        $out = [];
        $sharedIds = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int)$row['id']] = [
                'singer' => $row['singer_name'],
                'title' => $row['title'] ?: null,
                'shared_song_id' => $row['shared_song_id'] !== null ? (int)$row['shared_song_id'] : null,
            ];
            if (!$row['title'] && $row['shared_song_id']) {
                $sharedIds[] = (int)$row['shared_song_id'];
            }
        }
        if ($sharedIds) {
            $shared = SharedCatalogService::findMany(Connection::super(), $sharedIds);
            foreach ($out as $id => $ctx) {
                $sharedId = $ctx['shared_song_id'];
                if (!$ctx['title'] && $sharedId !== null && isset($shared[$sharedId])) {
                    $out[$id]['title'] = $shared[$sharedId]['title'];
                }
            }
        }
        return $out;
    }

    /** @return array<string,string> screen id => label */
    private static function loadScreenLabels(PDO $db, int $sessionId): array
    {
        $stmt = $db->prepare('SELECT screen, label FROM display_screens WHERE session_id = ?');
        $stmt->execute([$sessionId]);
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(string)$row['screen']] = (string)$row['label'];
        }
        return $map;
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<int,array{singer:string,title:?string,shared_song_id:?int}> $context
     * @param array<string,string> $screens
     */
    private static function format(string $event, array $payload, array $context, array $screens): ?string
    {
        $requestId = isset($payload['requestId']) ? (int)$payload['requestId'] : null;
        $ctx = $requestId !== null ? ($context[$requestId] ?? null) : null;
        $singer = $ctx['singer'] ?? 'A singer';
        $title = $ctx['title'] ?? null;

        return match ($event) {
            'request:created' => "New request from {$singer}" . ($title ? ": \"{$title}\"" : ''),
            'request:approved' => !empty($payload['fastTrack'])
                ? "{$singer} moved to the front of the queue"
                : "{$singer}'s request approved",
            'request:status_changed' => self::statusLine($singer, $title, (string)($payload['status'] ?? '')),
            'request:youtube_attached' => "Found a karaoke video for {$singer}" . ($title ? ": \"{$title}\"" : ''),
            'request:manual_video' => empty($payload['url']) ? "Video link removed for {$singer}" : "Video link updated for {$singer}",
            'request:video_cached' => "Local video ready for {$singer}" . ($title ? ": \"{$title}\"" : ''),
            'request:video_cache_failed' => "Local video mirror failed for {$singer} — using the YouTube embed",
            'announcement:shown' => 'Announcement: "' . self::truncate((string)($payload['message'] ?? ''), 80) . '"',
            'display:state_changed' => self::displayLine($payload, $screens),
            default => null,
        };
    }

    private static function statusLine(string $singer, ?string $title, string $status): ?string
    {
        $song = $title ? " \"{$title}\"" : '';
        return match ($status) {
            'now_singing' => "{$singer} started singing{$song}",
            'up_next' => "{$singer} is up next",
            'completed' => "{$singer} finished{$song}",
            'skipped' => "{$singer}'s song was skipped",
            'canceled' => "{$singer}'s request was canceled",
            default => null,
        };
    }

    /** @param array<string,mixed> $payload @param array<string,string> $screens */
    private static function displayLine(array $payload, array $screens): ?string
    {
        $screen = (string)($payload['screen'] ?? 'main');
        $label = $screens[$screen] ?? ucfirst($screen);
        $mode = $payload['display']['mode'] ?? null;
        return match ($mode) {
            'announcement' => "{$label} display showed an announcement",
            'blackout' => "{$label} display blacked out",
            'now_singing' => "{$label} display switched to the stage",
            'idle' => "{$label} display went idle",
            'queue' => "{$label} display switched to the queue",
            'clean_stage' => "{$label} display switched to clean stage",
            default => null,
        };
    }

    private static function truncate(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
    }
}
