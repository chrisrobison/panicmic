<?php

declare(strict_types=1);

namespace PanicMic\Http;

use PanicMic\Auth\Auth;
use PanicMic\Services\DisplayService;
use PanicMic\Services\EventBus;
use PanicMic\Support\Request;
use PanicMic\Support\Response;
use PDO;

final class DisplayController
{
    /** @param array<string,mixed> $tenant @param array<string,mixed> $session */
    public static function updateState(PDO $db, array $tenant, array $session): never
    {
        Auth::requireTenantRole('kj', 'tenant_admin');
        $input = Request::input();
        $screen = self::resolveScreen($input['screen'] ?? null);
        DisplayService::update($db, (int)$session['id'], $input, $_SESSION['tenant_user']['id'] ?? null, $screen);
        $display = DisplayService::state($db, (int)$session['id'], $screen);
        EventBus::publish($db, 'display:state_changed', ['screen' => $screen, 'display' => $display]);
        Response::json(['display' => $display]);
    }

    /** @param array<string,mixed> $tenant @param array<string,mixed> $session */
    public static function showState(PDO $db, array $tenant, array $session): never
    {
        $screen = self::resolveScreen($_GET['screen'] ?? null);
        Response::json(['display' => DisplayService::state($db, (int)$session['id'], $screen)]);
    }

    /** @param array<string,mixed> $tenant @param array<string,mixed> $session */
    public static function listScreens(PDO $db, array $tenant, array $session): never
    {
        Auth::requireTenantRole('kj', 'tenant_admin');
        Response::json(['screens' => DisplayService::listScreens($db, (int)$session['id'])]);
    }

    /** @param array<string,mixed> $tenant @param array<string,mixed> $session */
    public static function saveScreen(PDO $db, array $tenant, array $session): never
    {
        Auth::requireTenantRole('tenant_admin');
        DisplayService::upsertScreen($db, (int)$session['id'], Request::input());
        Response::json(['screens' => DisplayService::listScreens($db, (int)$session['id'])]);
    }

    /** @param array<string,mixed> $tenant @param array<string,mixed> $session */
    public static function deleteScreen(PDO $db, array $tenant, array $session, string $screen): never
    {
        Auth::requireTenantRole('tenant_admin');
        if ($screen === DisplayService::DEFAULT_SCREEN) {
            Response::json(['error' => 'Cannot delete the main screen'], 400);
        }
        DisplayService::removeScreen($db, (int)$session['id'], $screen);
        Response::json(['screens' => DisplayService::listScreens($db, (int)$session['id'])]);
    }

    private static function resolveScreen(mixed $raw): string
    {
        $clean = preg_replace('/[^a-z0-9_-]/i', '', (string)($raw ?? '')) ?: DisplayService::DEFAULT_SCREEN;
        return $clean;
    }

    /**
     * Trigger a synchronized play across display screens. Publishes a
     * display:cue event (load the video, don't play) followed by a
     * display:play_at event carrying a future wall-clock timestamp so
     * every connected display starts at approximately the same moment.
     *
     * The WebSocket daemon picks these events off the EventBus and pushes
     * them to displays. When the daemon isn't running, displays fall back
     * to short-polling and pick the same events up out of /api/events.
     *
     * @param array<string,mixed> $tenant @param array<string,mixed> $session
     */
    public static function triggerPlay(PDO $db, array $tenant, array $session): never
    {
        Auth::requireTenantRole('kj', 'tenant_admin');
        $input = Request::input();

        $screen = preg_replace('/[^a-z0-9_-]/i', '', (string)($input['screen'] ?? 'all')) ?: 'all';
        $requestId = isset($input['request_id']) ? (int)$input['request_id'] : null;
        $startDelayMs = max(0, min(10000, (int)($input['start_delay_ms'] ?? 2000)));
        $offsetSeconds = max(0.0, (float)($input['offset_seconds'] ?? 0.0));

        // Validate the request belongs to this session and pull the video
        // sources. The self-hosted video URL lives on songs, so join it in.
        if ($requestId !== null) {
            $stmt = $db->prepare(
                'SELECT sr.id, sr.youtube_video_id, sr.youtube_url, sr.manual_video_url,
                        songs.video_url AS song_video_url
                 FROM song_requests sr
                 LEFT JOIN songs ON songs.id = sr.song_id
                 WHERE sr.id = ? AND sr.session_id = ? LIMIT 1'
            );
            $stmt->execute([$requestId, (int)$session['id']]);
            $req = $stmt->fetch();
            if (!$req) {
                Response::json(['error' => 'Request not found'], 404);
            }
        } else {
            $req = [];
        }

        $commandId = bin2hex(random_bytes(8)); // 16-char hex token
        $startAtServerMs = (int)(microtime(true) * 1000) + $startDelayMs;

        // Determine video info for the cue event.
        $ytId = $req['youtube_video_id'] ?? null;
        if (!$ytId && !empty($req['youtube_url'])) {
            $m = [];
            if (preg_match('/(?:v=|youtu\.be\/|\/embed\/|\/shorts\/)([A-Za-z0-9_-]{6,})/', (string)$req['youtube_url'], $m)) {
                $ytId = $m[1];
            }
        }
        $videoUrl = $req['manual_video_url'] ?? '';
        if (!$videoUrl && !empty($req['song_video_url'])) {
            $videoUrl = (string)$req['song_video_url'];
        }
        $provider = $ytId ? 'youtube' : ($videoUrl ? 'self_hosted' : 'none');

        // Publish display:cue event (WS daemon pushes this to displays).
        EventBus::publish($db, 'display:cue', [
            'screen' => $screen,
            'requestId' => $requestId,
            'video' => [
                'provider' => $provider,
                'youtubeVideoId' => $ytId ?? '',
                'videoUrl' => (string)$videoUrl,
            ],
        ]);

        // Publish display:play_at event (WS daemon pushes this to displays).
        EventBus::publish($db, 'display:play_at', [
            'screen' => $screen,
            'requestId' => $requestId,
            'commandId' => $commandId,
            'startAtServerMs' => $startAtServerMs,
            'offsetSeconds' => $offsetSeconds,
        ]);

        Response::json(['commandId' => $commandId, 'startAtServerMs' => $startAtServerMs]);
    }

    /** @param array<string,mixed> $tenant @param array<string,mixed> $session */
    public static function announce(PDO $db, array $tenant, array $session): never
    {
        Auth::requireTenantRole('kj', 'tenant_admin');
        $message = trim((string)(Request::input()['message'] ?? ''));
        if ($message === '' || strlen($message) > 500) {
            Response::json(['error' => 'Announcement message is required'], 400);
        }
        $stmt = $db->prepare('INSERT INTO announcements (session_id, message, created_by) VALUES (?, ?, ?)');
        $stmt->execute([(int)$session['id'], $message, $_SESSION['tenant_user']['id'] ?? null]);
        $id = (int)$db->lastInsertId();
        DisplayService::update($db, (int)$session['id'], ['mode' => 'announcement', 'announcement_id' => $id], $_SESSION['tenant_user']['id'] ?? null);
        EventBus::publish($db, 'announcement:shown', ['id' => $id, 'message' => $message]);
        EventBus::publish($db, 'display:state_changed', ['display' => DisplayService::state($db, (int)$session['id'])]);
        Response::json(['id' => $id, 'announcement_id' => $id]);
    }
}
