<?php

declare(strict_types=1);

namespace PanicMic\Http;

use PanicMic\Auth\Auth;
use PanicMic\Database\Connection;
use PanicMic\Services\DisplayService;
use PanicMic\Services\EventBus;
use PanicMic\Services\QueueService;
use PanicMic\Services\YouTubeService;
use PanicMic\Support\Request;
use PanicMic\Support\Response;
use PanicMic\Support\Security;
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
        Security::rateLimitDb(
            Connection::super(),
            Security::publicRequestBucket((int)$tenant['id']),
            120,
            3600,
        );
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
        $sessionId = (int)$session['id'];
        EventBus::publish($db, 'request:created', ['requestId' => $requestId], $sessionId);
        if (!empty($settings['auto_accept_requests']) && QueueService::approve($db, (int)$session['id'], $requestId)) {
            EventBus::publish($db, 'request:approved', ['requestId' => $requestId, 'fastTrack' => false], $sessionId);
        }
        EventBus::publish($db, 'queue:updated', ['queue' => QueueService::queue($db, $sessionId, Connection::super())], $sessionId);
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
            Response::json(['error' => YouTubeService::lastError() ?? 'No YouTube karaoke video found or YouTube is not configured'], 404);
        }
        YouTubeService::attachToRequest($db, $requestId, $video);
        $sessionId = (int)$session['id'];
        EventBus::publish($db, 'request:youtube_attached', ['requestId' => $requestId, 'video' => $video], $sessionId);
        EventBus::publish($db, 'queue:updated', ['queue' => QueueService::queue($db, $sessionId, Connection::super())], $sessionId);
        Response::json(['video' => $video]);
    }

    /**
     * Attach (or clear) a KJ-supplied external video link for non-YouTube
     * sources. An empty/blank url removes any existing link.
     *
     * @param array<string,mixed> $session
     */
    public static function attachManualVideo(PDO $db, array $session, int $requestId): never
    {
        Auth::requireTenantRole('kj', 'tenant_admin');
        $url = trim((string)(Request::json()['url'] ?? ''));
        if ($url !== '') {
            if (!preg_match('#^https?://#i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
                Response::json(['error' => 'Enter a valid http(s) video URL.'], 422);
            }
            if (strlen($url) > 512) {
                Response::json(['error' => 'That URL is too long (512 characters max).'], 422);
            }
        }
        $found = QueueService::setManualVideo($db, (int)$session['id'], $requestId, $url === '' ? null : $url);
        if (!$found) {
            Response::json(['error' => 'Request not found'], 404);
        }
        $sessionId = (int)$session['id'];
        EventBus::publish($db, 'request:manual_video', ['requestId' => $requestId, 'url' => $url], $sessionId);
        EventBus::publish($db, 'queue:updated', ['queue' => QueueService::queue($db, $sessionId, Connection::super())], $sessionId);
        Response::json(['manual_video_url' => $url === '' ? null : $url]);
    }

    /** @param array<string,mixed> $tenant @param array<string,mixed> $session */
    public static function updateStatus(PDO $db, array $tenant, array $session, int $requestId): never
    {
        Auth::requireTenantRole('kj', 'tenant_admin');
        $input = Request::input();
        $status = (string)($input['status'] ?? 'pending');
        QueueService::setStatus($db, (int)$session['id'], $requestId, $status);
        if ($status === 'now_singing') {
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
            ], (int)$session['id']);
        } elseif (in_array($status, ['completed', 'skipped', 'canceled'], true)) {
            // The act left the stage — clear it from any screen still showing
            // it so the "now singing" singer doesn't linger on the display.
            foreach (DisplayService::clearNowRequest($db, (int)$session['id'], $requestId) as $screen) {
                EventBus::publish($db, 'display:state_changed', [
                    'screen' => $screen,
                    'display' => DisplayService::state($db, (int)$session['id'], $screen),
                ], (int)$session['id']);
            }
        }
        $sessionId = (int)$session['id'];
        EventBus::publish($db, 'request:status_changed', ['requestId' => $requestId, 'status' => $input['status'] ?? null], $sessionId);
        EventBus::publish($db, 'queue:updated', ['queue' => QueueService::queue($db, $sessionId, Connection::super())], $sessionId);
        Response::json(['ok' => true]);
    }

    /**
     * Accept a pending, unreviewed request into the rotation queue.
     * {fast_track: true} additionally jumps it to the front of the line.
     *
     * @param array<string,mixed> $tenant @param array<string,mixed> $session
     */
    public static function approve(PDO $db, array $tenant, array $session, int $requestId): never
    {
        Auth::requireTenantRole('kj', 'tenant_admin');
        $fastTrack = !empty(Request::input()['fast_track']);
        if (!QueueService::approve($db, (int)$session['id'], $requestId, $fastTrack)) {
            Response::json(['error' => 'Request not found or already reviewed'], 404);
        }
        $sessionId = (int)$session['id'];
        EventBus::publish($db, 'request:approved', ['requestId' => $requestId, 'fastTrack' => $fastTrack], $sessionId);
        EventBus::publish($db, 'queue:updated', ['queue' => QueueService::queue($db, $sessionId, Connection::super())], $sessionId);
        Response::json(['ok' => true]);
    }

    /** @param array<string,mixed> $tenant @param array<string,mixed> $session */
    public static function setPriority(PDO $db, array $tenant, array $session, int $requestId): never
    {
        Auth::requireTenantRole('kj', 'tenant_admin');
        $priority = !empty(Request::input()['priority']);
        if (!QueueService::setPriority($db, (int)$session['id'], $requestId, $priority)) {
            Response::json(['error' => 'Request not found'], 404);
        }
        EventBus::publish($db, 'queue:updated', ['queue' => QueueService::queue($db, (int)$session['id'], Connection::super())], (int)$session['id']);
        Response::json(['ok' => true, 'is_priority' => $priority]);
    }

    /** @param array<string,mixed> $tenant @param array<string,mixed> $session */
    public static function reorder(PDO $db, array $tenant, array $session): never
    {
        Auth::requireTenantRole('kj', 'tenant_admin');
        $ids = array_map('intval', Request::input()['request_ids'] ?? []);
        QueueService::reorder($db, (int)$session['id'], $ids);
        EventBus::publish($db, 'queue:updated', ['queue' => QueueService::queue($db, (int)$session['id'], Connection::super())], (int)$session['id']);
        Response::json(['ok' => true]);
    }

    public static function events(PDO $db, int $sessionId): never
    {
        header('Cache-Control: no-store');
        $lastId = (int)($_GET['last_id'] ?? 0);
        $events = EventBus::after($db, $lastId, $sessionId);
        $maxId = $lastId;
        foreach ($events as $event) {
            $id = (int)$event['id'];
            if ($id > $maxId) {
                $maxId = $id;
            }
        }
        Response::json(['events' => $events, 'last_id' => $maxId]);
    }
}
