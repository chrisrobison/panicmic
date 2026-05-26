<?php

declare(strict_types=1);

namespace NextUp\Http;

use NextUp\Auth\Auth;
use NextUp\Database\Connection;
use NextUp\Services\DisplayService;
use NextUp\Services\EventBus;
use NextUp\Services\QueueService;
use NextUp\Services\YouTubeService;
use NextUp\Support\Request;
use NextUp\Support\Response;
use NextUp\Support\Security;
use PDO;

final class QueueController
{
    /**
     * @param array<string,mixed> $tenant
     * @param array<string,mixed> $session
     * @param array<string,mixed> $settings
     */
    public static function submit(PDO $db, array $tenant, array $session, array $settings): never
    {
        Security::rateLimit('public_request', 8, 60);
        if (!empty($session['requests_paused']) || !empty($session['queue_locked'])) {
            Response::json(['error' => 'Requests are currently closed'], 423);
        }
        $input = Request::input();
        $name = trim((string)($input['display_name'] ?? ''));
        if ($name === '' || strlen($name) > 160) {
            Response::json(['error' => 'A display name is required'], 400);
        }
        if (empty($input['song_id']) && empty($input['shared_song_id'])) {
            Response::json(['error' => 'A song selection is required'], 400);
        }
        $token = $_SESSION['requester_token'] ??= bin2hex(random_bytes(32));
        $requestId = QueueService::submit(
            $db,
            (int)$session['id'],
            $input,
            $token,
            (bool)($settings['prevent_duplicate_requests'] ?? true),
            Connection::super()
        );
        self::autoAttachYouTubeVideo($db, $requestId, $settings);
        EventBus::publish($db, 'request:created', ['requestId' => $requestId]);
        EventBus::publish($db, 'queue:updated', ['queue' => QueueService::queue($db, (int)$session['id'], Connection::super())]);
        Response::json(['requestId' => $requestId]);
    }

    /** @param array<string,mixed> $settings */
    private static function autoAttachYouTubeVideo(PDO $db, int $requestId, array $settings): void
    {
        $envEnabled = YouTubeService::isEnabled();
        $tenantEnabled = (bool)($settings['auto_attach_youtube'] ?? false) || (string)($settings['song_source'] ?? '') === 'catalog+youtube';
        if (!$envEnabled || !$tenantEnabled) {
            return;
        }
        $song = QueueService::requestSong($db, $requestId, Connection::super());
        if (!$song) {
            return;
        }
        $video = YouTubeService::findKaraokeVideo($song);
        if ($video) {
            YouTubeService::attachToRequest($db, $requestId, $video);
        }
    }

    /** @param array<string,mixed> $session */
    public static function attachYouTubeVideo(PDO $db, array $session, int $requestId): never
    {
        Auth::requireTenantRole('kj', 'tenant_admin');
        $song = QueueService::requestSong($db, $requestId, Connection::super());
        if (!$song) {
            Response::json(['error' => 'Request not found'], 404);
        }
        $video = YouTubeService::findKaraokeVideo($song);
        if (!$video) {
            Response::json(['error' => 'No YouTube karaoke video found or YouTube is not configured'], 404);
        }
        YouTubeService::attachToRequest($db, $requestId, $video);
        EventBus::publish($db, 'request:youtube_attached', ['requestId' => $requestId, 'video' => $video]);
        EventBus::publish($db, 'queue:updated', ['queue' => QueueService::queue($db, (int)$session['id'], Connection::super())]);
        Response::json(['video' => $video]);
    }

    /** @param array<string,mixed> $tenant @param array<string,mixed> $session */
    public static function updateStatus(PDO $db, array $tenant, array $session, int $requestId): never
    {
        Auth::requireTenantRole('kj', 'tenant_admin');
        $input = Request::input();
        QueueService::setStatus($db, (int)$session['id'], $requestId, (string)($input['status'] ?? 'pending'));
        if (($input['status'] ?? '') === 'now_singing') {
            $screen = preg_replace('/[^a-z0-9_-]/i', '', (string)($input['screen'] ?? '')) ?: DisplayService::DEFAULT_SCREEN;
            DisplayService::update(
                $db,
                (int)$session['id'],
                ['mode' => 'now_singing', 'now_request_id' => $requestId],
                $_SESSION['tenant_user']['id'] ?? null,
                $screen,
            );
            EventBus::publish($db, 'display:state_changed', [
                'screen' => $screen,
                'display' => DisplayService::state($db, (int)$session['id'], $screen),
            ]);
        }
        EventBus::publish($db, 'request:status_changed', ['requestId' => $requestId, 'status' => $input['status'] ?? null]);
        EventBus::publish($db, 'queue:updated', ['queue' => QueueService::queue($db, (int)$session['id'], Connection::super())]);
        Response::json(['ok' => true]);
    }

    /** @param array<string,mixed> $tenant @param array<string,mixed> $session */
    public static function reorder(PDO $db, array $tenant, array $session): never
    {
        Auth::requireTenantRole('kj', 'tenant_admin');
        $ids = array_map('intval', Request::input()['request_ids'] ?? []);
        QueueService::reorder($db, (int)$session['id'], $ids);
        EventBus::publish($db, 'queue:updated', ['queue' => QueueService::queue($db, (int)$session['id'], Connection::super())]);
        Response::json(['ok' => true]);
    }

    public static function sse(PDO $db): never
    {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        $lastId = (int)($_GET['last_id'] ?? 0);
        $deadline = time() + 25;
        while (time() < $deadline) {
            foreach (EventBus::after($db, $lastId) as $event) {
                $lastId = (int)$event['id'];
                echo "id: {$lastId}\n";
                echo 'event: ' . $event['event_name'] . "\n";
                echo 'data: ' . json_encode($event['payload'], JSON_THROW_ON_ERROR) . "\n\n";
                @ob_flush();
                flush();
            }
            sleep(1);
        }
        echo ": heartbeat\n\n";
        exit;
    }
}
