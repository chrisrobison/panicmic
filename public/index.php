<?php

declare(strict_types=1);

use NextUp\Auth\Auth;
use NextUp\Database\Connection;
use NextUp\Services\ContentService;
use NextUp\Services\DisplayService;
use NextUp\Services\EventBus;
use NextUp\Services\QueueService;
use NextUp\Services\SessionService;
use NextUp\Services\SettingsService;
use NextUp\Services\SharedCatalogService;
use NextUp\Services\SongService;
use NextUp\Services\TenantBrandingService;
use NextUp\Services\YouTubeService;
use NextUp\Support\Env;
use NextUp\Support\Impersonation;
use NextUp\Support\Request;
use NextUp\Support\Response;
use NextUp\Support\Security;
use NextUp\Support\Url;
use NextUp\Tenant\TenantContext;

require dirname(__DIR__) . '/src/autoload.php';

Env::load(dirname(__DIR__) . '/.env');
Security::startSession();
Security::headers();

$path = Request::path();
$method = Request::method();
$tenantContext = TenantContext::resolve();

try {
    if ($path === '/health') {
        Response::json(['ok' => true]);
    }

    if ($path === '/csrf') {
        Response::json(['csrf' => Security::csrfToken()]);
    }

    if (str_starts_with($path, '/assets/')) {
        http_response_code(404);
        exit;
    }

    if (str_starts_with($path, '/super') || str_starts_with($path, '/api/super')) {
        handleSuper($path, $method);
    }

    if (!$tenantContext) {
        Response::json(['error' => 'Tenant context required'], 400);
    }

    $db = $tenantContext->db;
    $tenant = $tenantContext->tenant;

    if (str_starts_with($path, '/files')) {
        $filePath = substr($path, strlen('/files')) ?: '';
        ContentService::serve((string)$tenant['slug'], $filePath);
    }

    consumeImpersonationToken($tenant);

    $session = SessionService::current($db, $tenant['night_name']);
    $settings = SettingsService::all($db);

    if ($method === 'GET' && ((str_starts_with($path, '/admin') && $path !== '/admin/login') || $path === '/display/control') && empty($_SESSION['tenant_user']) && empty($_SESSION['super_admin'])) {
        Response::redirect('/admin/login');
    }

    if ($method !== 'GET') {
        Security::requireCsrf();
    }

    match (true) {
        $path === '/' && $method === 'GET' => page('public', $tenant, $session),
        $path === '/songs' && $method === 'GET' => page('songs', $tenant, $session),
        $path === '/me' && $method === 'GET' => page('me', $tenant, $session),
        $path === '/admin/login' && $method === 'GET' => page('admin-login', $tenant, $session),
        $path === '/admin/dashboard' && $method === 'GET' => page('admin-dashboard', $tenant, $session),
        $path === '/admin/queue' && $method === 'GET' => page('admin-dashboard', $tenant, $session),
        $path === '/admin/songs' && $method === 'GET' => page('admin-songs', $tenant, $session),
        $path === '/admin/content' && $method === 'GET' => page('admin-content', $tenant, $session),
        $path === '/admin/singers' && $method === 'GET' => page('admin-dashboard', $tenant, $session),
        $path === '/admin/settings' && $method === 'GET' => page('admin-settings', $tenant, $session),
        $path === '/display/control' && $method === 'GET' => page('admin-dashboard', $tenant, $session),
        in_array($path, ['/display', '/display/fullscreen'], true) && $method === 'GET' => page('display', $tenant, $session),
        $path === '/api/config' && $method === 'GET' => Response::json([
            'tenant' => publicTenant($tenant),
            'session' => $session,
            'settings' => $settings,
            'csrf' => Security::csrfToken(),
            'actor' => Auth::currentTenantActor(),
            'actingAsSuper' => Auth::actingAsSuper(),
        ]),
        $path === '/api/songs' && $method === 'GET' => Response::json(SongService::blendedSearch($db, Connection::super(), $_GET)),
        $path === '/api/catalog' && $method === 'GET' => Response::json(SongService::blendedSearch($db, Connection::super(), $_GET)),
        $path === '/api/queue' && $method === 'GET' => Response::json([
            'queue' => QueueService::queue($db, (int)$session['id'], Connection::super()),
            'display' => DisplayService::state($db, (int)$session['id']),
        ]),
        in_array($path, ['/api/requests', '/requests'], true) && $method === 'POST' => submitRequest($db, $tenant, $session, $settings),
        (bool)preg_match('#^/api/requests/(\d+)/status$#', $path, $m) && $method === 'PATCH' => updateStatus($db, $tenant, $session, (int)$m[1]),
        (bool)preg_match('#^/api/requests/(\d+)/youtube$#', $path, $m) && $method === 'POST' => attachYouTubeVideo($db, $session, (int)$m[1]),
        $path === '/api/queue/reorder' && $method === 'PATCH' => reorderQueue($db, $tenant, $session),
        $path === '/api/display/state' && $method === 'POST' => updateDisplay($db, $tenant, $session),
        $path === '/api/announcements' && $method === 'POST' => createAnnouncement($db, $tenant, $session),
        $path === '/api/admin/login' && $method === 'POST' => tenantLogin($db),
        $path === '/api/admin/logout' && $method === 'POST' => logoutTenant(),
        $path === '/admin/end-impersonation' && $method === 'GET' => endImpersonation(),
        $path === '/api/admin/end-impersonation' && $method === 'POST' => endImpersonation(),
        $path === '/api/admin/settings' && $method === 'GET' => listSettings($db),
        $path === '/api/admin/settings' && $method === 'POST' => updateSettings($db),
        $path === '/api/admin/branding' && $method === 'GET' => tenantBranding($tenant),
        $path === '/api/admin/branding' && $method === 'POST' => updateTenantBranding($tenant),
        $path === '/api/admin/songs' && $method === 'GET' => listAdminSongs($db),
        $path === '/api/admin/songs' && $method === 'POST' => createSong($db, $tenant),
        $path === '/api/admin/songs/import-playlist' && $method === 'POST' => importYouTubePlaylist($db),
        $path === '/api/admin/songs/export' && $method === 'GET' => exportTenantCatalog($db, $tenant),
        (bool)preg_match('#^/api/admin/songs/(\d+)$#', $path, $m) && $method === 'PATCH' => updateSong($db, $tenant, (int)$m[1]),
        (bool)preg_match('#^/api/admin/songs/(\d+)$#', $path, $m) && $method === 'DELETE' => deleteSong($db, (int)$m[1]),
        $path === '/api/admin/content' && $method === 'GET' => listContent($tenant),
        $path === '/api/admin/content' && $method === 'POST' => uploadContent($tenant),
        $path === '/api/events' && $method === 'GET' => sse($db),
        default => Response::json(['error' => 'Not found'], 404),
    };
} catch (Throwable $error) {
    $status = $error instanceof InvalidArgumentException ? 400 : 500;
    Response::json(['error' => $error->getMessage()], $status);
}

/** @param array<string,mixed> $tenant @param array<string,mixed> $session */
function page(string $page, array $tenant, array $session): never
{
    $csrf = Security::csrfToken();
    $basePath = Url::basePath();
    require dirname(__DIR__) . '/views/layout.php';
    exit;
}

/** @param array<string,mixed> $tenant */
function publicTenant(array $tenant): array
{
    return [
        'id' => (int)$tenant['id'],
        'slug' => $tenant['slug'],
        'venueName' => $tenant['venue_name'],
        'nightName' => $tenant['night_name'],
        'logoUrl' => $tenant['logo_url'],
        'profileImageUrl' => $tenant['profile_image_url'] ?? null,
        'backgroundImageUrl' => $tenant['background_image_url'] ?? null,
        'backgroundColor' => $tenant['background_color'] ?? '#101216',
        'surfaceColor' => $tenant['surface_color'] ?? '#191d24',
        'textColor' => $tenant['text_color'] ?? '#f5f7fb',
        'primaryColor' => $tenant['primary_color'],
        'accentColor' => $tenant['accent_color'],
        'timezone' => $tenant['timezone'],
        'signupMode' => $tenant['signup_mode'],
        'publicRequestUrl' => $tenant['public_request_url'],
        'projectionUrl' => $tenant['projection_url'],
    ];
}

function tenantLogin(PDO $db): never
{
    $input = Request::input();
    $user = Auth::attemptTenant($db, trim((string)($input['email'] ?? '')), (string)($input['password'] ?? ''));
    if (!$user) {
        Response::json(['error' => 'Invalid credentials'], 401);
    }
    Response::json(['user' => $user]);
}

function logoutTenant(): never
{
    unset($_SESSION['tenant_user']);
    Response::json(['ok' => true]);
}

/** @param array<string,mixed> $tenant @param array<string,mixed> $session @param array<string,mixed> $settings */
function submitRequest(PDO $db, array $tenant, array $session, array $settings): never
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
    autoAttachYouTubeVideo($db, $requestId, $settings);
    EventBus::publish($db, 'request:created', ['requestId' => $requestId]);
    EventBus::publish($db, 'queue:updated', ['queue' => QueueService::queue($db, (int)$session['id'], Connection::super())]);
    Response::json(['requestId' => $requestId]);
}

/** @param array<string,mixed> $settings */
function autoAttachYouTubeVideo(PDO $db, int $requestId, array $settings): void
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

/** @param array<string,mixed> $tenant */
function tenantBranding(array $tenant): never
{
    Auth::requireTenantRole('kj', 'tenant_admin');
    Response::json(['branding' => TenantBrandingService::get(Connection::super(), (int)$tenant['id'])]);
}

/** @param array<string,mixed> $tenant */
function updateTenantBranding(array $tenant): never
{
    Auth::requireTenantRole('tenant_admin');
    TenantBrandingService::update(Connection::super(), (int)$tenant['id'], Request::input());
    Response::json(['branding' => TenantBrandingService::get(Connection::super(), (int)$tenant['id'])]);
}

/** @param array<string,mixed> $session */
function attachYouTubeVideo(PDO $db, array $session, int $requestId): never
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
function updateStatus(PDO $db, array $tenant, array $session, int $requestId): never
{
    Auth::requireTenantRole('kj', 'tenant_admin');
    $input = Request::input();
    QueueService::setStatus($db, (int)$session['id'], $requestId, (string)($input['status'] ?? 'pending'));
    if (($input['status'] ?? '') === 'now_singing') {
        DisplayService::update($db, (int)$session['id'], ['mode' => 'now_singing', 'now_request_id' => $requestId], $_SESSION['tenant_user']['id'] ?? null);
        EventBus::publish($db, 'display:state_changed', ['display' => DisplayService::state($db, (int)$session['id'])]);
    }
    EventBus::publish($db, 'request:status_changed', ['requestId' => $requestId, 'status' => $input['status'] ?? null]);
    EventBus::publish($db, 'queue:updated', ['queue' => QueueService::queue($db, (int)$session['id'], Connection::super())]);
    Response::json(['ok' => true]);
}

/** @param array<string,mixed> $tenant @param array<string,mixed> $session */
function reorderQueue(PDO $db, array $tenant, array $session): never
{
    Auth::requireTenantRole('kj', 'tenant_admin');
    $ids = array_map('intval', Request::input()['request_ids'] ?? []);
    QueueService::reorder($db, (int)$session['id'], $ids);
    EventBus::publish($db, 'queue:updated', ['queue' => QueueService::queue($db, (int)$session['id'], Connection::super())]);
    Response::json(['ok' => true]);
}

/** @param array<string,mixed> $tenant @param array<string,mixed> $session */
function updateDisplay(PDO $db, array $tenant, array $session): never
{
    Auth::requireTenantRole('kj', 'tenant_admin');
    DisplayService::update($db, (int)$session['id'], Request::input(), $_SESSION['tenant_user']['id'] ?? null);
    $display = DisplayService::state($db, (int)$session['id']);
    EventBus::publish($db, 'display:state_changed', ['display' => $display]);
    Response::json(['display' => $display]);
}

/** @param array<string,mixed> $tenant @param array<string,mixed> $session */
function createAnnouncement(PDO $db, array $tenant, array $session): never
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
    Response::json(['id' => $id]);
}

/** @param array<string,mixed> $tenant */
function createSong(PDO $db, array $tenant): never
{
    Auth::requireTenantRole('kj', 'tenant_admin');
    $input = Request::input();
    if (trim((string)($input['title'] ?? '')) === '' || trim((string)($input['artist'] ?? '')) === '') {
        Response::json(['error' => 'Title and artist are required'], 400);
    }
    $id = SongService::create($db, $input);
    EventBus::publish($db, 'song:created', ['songId' => $id]);
    Response::json(['id' => $id]);
}

/** @param array<string,mixed> $tenant */
function updateSong(PDO $db, array $tenant, int $songId): never
{
    Auth::requireTenantRole('kj', 'tenant_admin');
    $input = Request::input();
    if (trim((string)($input['title'] ?? '')) === '' || trim((string)($input['artist'] ?? '')) === '') {
        Response::json(['error' => 'Title and artist are required'], 400);
    }
    SongService::update($db, $songId, $input);
    EventBus::publish($db, 'song:updated', ['songId' => $songId]);
    Response::json(['ok' => true]);
}

function deleteSong(PDO $db, int $songId): never
{
    Auth::requireTenantRole('kj', 'tenant_admin');
    SongService::delete($db, $songId);
    EventBus::publish($db, 'song:deleted', ['songId' => $songId]);
    Response::json(['ok' => true]);
}

function listAdminSongs(PDO $db): never
{
    Auth::requireTenantRole('kj', 'tenant_admin');
    Response::json(SongService::search($db, $_GET));
}

function listSettings(PDO $db): never
{
    Auth::requireTenantRole('kj', 'tenant_admin');
    Response::json([
        'settings' => SettingsService::all($db),
        'defaults' => SettingsService::DEFAULTS,
        'youtube_enabled' => YouTubeService::isEnabled(),
    ]);
}

function updateSettings(PDO $db): never
{
    Auth::requireTenantRole('tenant_admin');
    SettingsService::saveMany($db, Request::input());
    EventBus::publish($db, 'settings:updated', ['settings' => SettingsService::all($db)]);
    Response::json(['settings' => SettingsService::all($db)]);
}

function importYouTubePlaylist(PDO $db): never
{
    Auth::requireTenantRole('kj', 'tenant_admin');
    if (!YouTubeService::isEnabled()) {
        Response::json(['error' => 'YouTube API key is not configured'], 400);
    }
    $input = Request::input();
    $playlistId = YouTubeService::parsePlaylistId((string)($input['playlist'] ?? ''));
    if (!$playlistId) {
        Response::json(['error' => 'Could not extract a playlist ID from the input'], 400);
    }
    $rows = [];
    $skippedNoArtist = 0;
    try {
        foreach (YouTubeService::fetchPlaylist($playlistId) as $entry) {
            $parsed = YouTubeService::parseSongTitle($entry['video_title']);
            $artist = $parsed['artist'] !== '' ? $parsed['artist'] : $entry['channel'];
            $title = $parsed['title'];
            if ($artist === '' || $title === '') {
                $skippedNoArtist++;
                continue;
            }
            $rows[] = [
                'title' => $title,
                'artist' => $artist,
                'video_url' => 'https://www.youtube.com/watch?v=' . rawurlencode($entry['video_id']),
                'video_provider' => 'youtube',
                'provider_track_id' => $entry['video_id'],
                'provider_url' => 'https://www.youtube.com/watch?v=' . rawurlencode($entry['video_id']),
                'provider_metadata' => json_encode([
                    'youtube' => [
                        'playlist_id' => $playlistId,
                        'channel' => $entry['channel'],
                        'original_title' => $entry['video_title'],
                    ],
                ]),
            ];
        }
    } catch (\Throwable $error) {
        Response::json(['error' => $error->getMessage()], 502);
    }
    $result = SongService::bulkImport($db, $rows);
    EventBus::publish($db, 'song:imported', ['source' => 'youtube_playlist', 'imported' => $result['imported']]);
    Response::json([
        'imported' => $result['imported'],
        'skipped' => $result['skipped'] + $skippedNoArtist,
        'total_seen' => count($rows) + $skippedNoArtist,
    ]);
}

/** @param array<string,mixed> $tenant */
function exportTenantCatalog(PDO $db, array $tenant): never
{
    Auth::requireTenantRole('kj', 'tenant_admin');
    $filename = preg_replace('/[^A-Za-z0-9_-]+/', '-', strtolower((string)$tenant['slug'])) . '-songs.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['title', 'artist', 'genre', 'decade', 'popularity', 'external_id', 'video_url', 'video_provider', 'provider_track_id', 'provider_url', 'lyrics_url'], ';');
    $stmt = $db->query('SELECT title, artist, genre, decade, popularity, external_id, video_url, video_provider, provider_track_id, provider_url, lyrics_url FROM songs WHERE is_active = 1 ORDER BY artist, title');
    while (($row = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
        fputcsv($out, $row, ';');
    }
    fclose($out);
    exit;
}

/** @param array<string,mixed> $tenant */
function consumeImpersonationToken(array $tenant): void
{
    $raw = $_GET['super_token'] ?? null;
    if (!is_string($raw) || $raw === '') {
        return;
    }
    $info = Impersonation::verify($raw);
    if (!$info || $info['tenant_id'] !== (int)$tenant['id']) {
        return;
    }
    $stmt = Connection::super()->prepare('SELECT id, email, display_name FROM super_admin_users WHERE id = ?');
    $stmt->execute([$info['super_id']]);
    $admin = $stmt->fetch();
    if (!$admin) {
        return;
    }
    $_SESSION['super_admin'] = [
        'id' => (int)$admin['id'],
        'email' => $admin['email'],
        'display_name' => $admin['display_name'],
    ];
    $cleanQuery = $_GET;
    unset($cleanQuery['super_token']);
    $cleanPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if ($cleanQuery) {
        $cleanPath .= '?' . http_build_query($cleanQuery);
    }
    header('Location: ' . $cleanPath, true, 302);
    exit;
}

function endImpersonation(): never
{
    unset($_SESSION['super_admin']);
    if (Request::method() === 'POST') {
        Response::json(['ok' => true]);
    }
    Response::redirect('/admin/dashboard');
}

/** @param array<string,mixed> $tenant @return list<array<string,mixed>> */
function contentFiles(array $tenant): array
{
    return array_map(static function (array $file): array {
        $file['url'] = Url::path($file['url']);
        return $file;
    }, ContentService::list((string)$tenant['slug']));
}

/** @param array<string,mixed> $tenant */
function listContent(array $tenant): never
{
    Auth::requireTenantRole('kj', 'tenant_admin');
    Response::json(['files' => contentFiles($tenant)]);
}

/** @param array<string,mixed> $tenant */
function uploadContent(array $tenant): never
{
    Auth::requireTenantRole('kj', 'tenant_admin');
    if (empty($_FILES['content_file']) || !is_array($_FILES['content_file'])) {
        Response::json(['error' => 'No file uploaded'], 400);
    }
    $file = ContentService::storeUpload((string)$tenant['slug'], $_FILES['content_file']);
    $file['url'] = Url::path($file['url']);
    Response::json(['file' => $file, 'files' => contentFiles($tenant)]);
}

function sse(PDO $db): never
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

function handleSuper(string $path, string $method): never
{
    $db = Connection::super();
    if ($path === '/super/login' && $method === 'GET') {
        $tenant = ['venue_name' => 'NextUp', 'night_name' => 'Super Admin', 'primary_color' => '#22c55e', 'accent_color' => '#facc15'];
        $session = ['id' => 0, 'name' => 'Super Admin'];
        page('super-login', $tenant, $session);
    }
    if ($method !== 'GET') {
        Security::requireCsrf();
    }
    if ($path === '/api/super/login' && $method === 'POST') {
        $input = Request::input();
        $stmt = $db->prepare('SELECT * FROM super_admin_users WHERE email = ? LIMIT 1');
        $stmt->execute([trim((string)($input['email'] ?? ''))]);
        $user = $stmt->fetch();
        if (!$user || !password_verify((string)($input['password'] ?? ''), $user['password_hash'])) {
            Response::json(['error' => 'Invalid credentials'], 401);
        }
        $_SESSION['super_admin'] = ['id' => (int)$user['id'], 'email' => $user['email'], 'display_name' => $user['display_name']];
        Response::json(['user' => $_SESSION['super_admin']]);
    }
    if ($path === '/super/tenants' && $method === 'GET') {
        if (empty($_SESSION['super_admin'])) {
            Response::redirect('/super/login');
        }
        $tenant = ['venue_name' => 'NextUp', 'night_name' => 'Super Admin', 'primary_color' => '#22c55e', 'accent_color' => '#facc15'];
        $session = ['id' => 0, 'name' => 'Super Admin'];
        page('super-tenants', $tenant, $session);
    }
    if ($path === '/super/catalog' && $method === 'GET') {
        if (empty($_SESSION['super_admin'])) {
            Response::redirect('/super/login');
        }
        $tenant = ['venue_name' => 'NextUp', 'night_name' => 'Shared Catalog', 'primary_color' => '#22c55e', 'accent_color' => '#facc15'];
        $session = ['id' => 0, 'name' => 'Super Admin'];
        page('super-catalog', $tenant, $session);
    }
    if ($path === '/super/logout' && $method === 'POST') {
        Auth::requireSuper();
        unset($_SESSION['super_admin']);
        Response::json(['ok' => true]);
    }
    Auth::requireSuper();
    if ($path === '/api/super/tenants' && $method === 'GET') {
        Response::json(['tenants' => listTenantsWithDomains($db)]);
    }
    if (preg_match('#^/api/super/tenants/(\d+)$#', $path, $m) && $method === 'GET') {
        $tenant = loadTenantWithDomains($db, (int)$m[1]);
        if (!$tenant) {
            Response::json(['error' => 'Tenant not found'], 404);
        }
        Response::json(['tenant' => $tenant]);
    }
    if (preg_match('#^/api/super/tenants/(\d+)$#', $path, $m) && $method === 'PATCH') {
        updateTenantRecord($db, (int)$m[1]);
    }
    if (preg_match('#^/api/super/tenants/(\d+)/domains/(\d+)$#', $path, $m) && $method === 'DELETE') {
        $stmt = $db->prepare('DELETE FROM tenant_domains WHERE id = ? AND tenant_id = ?');
        $stmt->execute([(int)$m[2], (int)$m[1]]);
        Response::json(['ok' => true]);
    }
    if (preg_match('#^/api/super/tenants/(\d+)/domains/(\d+)$#', $path, $m) && $method === 'PATCH') {
        $input = Request::input();
        if (!empty($input['is_primary'])) {
            $db->prepare('UPDATE tenant_domains SET is_primary = 0 WHERE tenant_id = ?')->execute([(int)$m[1]]);
            $db->prepare('UPDATE tenant_domains SET is_primary = 1 WHERE id = ? AND tenant_id = ?')->execute([(int)$m[2], (int)$m[1]]);
        } else {
            $db->prepare('UPDATE tenant_domains SET is_primary = 0 WHERE id = ? AND tenant_id = ?')->execute([(int)$m[2], (int)$m[1]]);
        }
        Response::json(['ok' => true]);
    }
    if ($path === '/super/tenants' && $method === 'POST') {
        $input = Request::input();
        $database = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($input['database_name'] ?? '')));
        $stmt = $db->prepare('INSERT INTO tenants (slug, venue_name, night_name, database_name, timezone, signup_mode, public_request_url, projection_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            trim((string)$input['slug']),
            trim((string)$input['venue_name']),
            trim((string)$input['night_name']),
            $database,
            $input['timezone'] ?: 'America/Los_Angeles',
            $input['signup_mode'] ?: 'both',
            $input['public_request_url'] ?? null,
            $input['projection_url'] ?? null,
        ]);
        Response::json(['id' => (int)$db->lastInsertId()]);
    }
    if (preg_match('#^/super/tenants/(\d+)/domains$#', $path, $m) && $method === 'POST') {
        $input = Request::input();
        $stmt = $db->prepare('INSERT INTO tenant_domains (tenant_id, domain, is_primary) VALUES (?, ?, ?)');
        $stmt->execute([(int)$m[1], strtolower(trim((string)$input['domain'])), !empty($input['is_primary']) ? 1 : 0]);
        Response::json(['ok' => true]);
    }
    if (preg_match('#^/super/tenants/(\d+)/provision$#', $path, $m) && $method === 'POST') {
        $stmt = $db->prepare('SELECT * FROM tenants WHERE id = ?');
        $stmt->execute([(int)$m[1]]);
        $tenant = $stmt->fetch();
        if (!$tenant) {
            Response::json(['error' => 'Tenant not found'], 404);
        }
        try {
            provisionTenant($tenant);
            Response::json(['ok' => true]);
        } catch (Throwable $error) {
            Response::json(['error' => 'Provisioning failed: ' . $error->getMessage()], 500);
        }
    }
    if (preg_match('#^/api/super/tenants/(\d+)/handoff$#', $path, $m) && $method === 'POST') {
        generateTenantHandoff($db, (int)$m[1]);
    }
    if ($path === '/api/super/catalog' && $method === 'GET') {
        Response::json(SharedCatalogService::search($db, $_GET));
    }
    if ($path === '/api/super/catalog/stats' && $method === 'GET') {
        Response::json(['total' => SharedCatalogService::count($db)]);
    }
    if ($path === '/api/super/catalog/import' && $method === 'POST') {
        importSharedCatalog($db);
    }
    if ($path === '/api/super/catalog/export' && $method === 'GET') {
        exportSharedCatalog($db);
    }
    if (preg_match('#^/api/super/catalog/(\d+)$#', $path, $m) && $method === 'DELETE') {
        SharedCatalogService::delete($db, (int)$m[1]);
        Response::json(['ok' => true]);
    }
    Response::json(['error' => 'Not found'], 404);
}

function generateTenantHandoff(PDO $db, int $tenantId): never
{
    $stmt = $db->prepare(
        'SELECT t.id, t.slug, d.domain
         FROM tenants t
         LEFT JOIN tenant_domains d ON d.tenant_id = t.id
         WHERE t.id = ?
         ORDER BY d.is_primary DESC, d.domain ASC
         LIMIT 1'
    );
    $stmt->execute([$tenantId]);
    $row = $stmt->fetch();
    if (!$row || empty($row['domain'])) {
        Response::json(['error' => 'Tenant has no domain attached'], 400);
    }
    $token = Impersonation::sign((int)$_SESSION['super_admin']['id'], $tenantId);
    $scheme = (!empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
    $port = '';
    if (!empty($_SERVER['HTTP_HOST']) && ($colon = strrpos((string)$_SERVER['HTTP_HOST'], ':')) !== false) {
        $port = substr((string)$_SERVER['HTTP_HOST'], $colon);
    }
    $destination = $scheme . '://' . $row['domain'] . $port . Url::path('/admin/dashboard') . '?super_token=' . urlencode($token);
    Response::json(['url' => $destination, 'expires_in' => 300]);
}

function importSharedCatalog(PDO $superDb): never
{
    @set_time_limit(0);
    @ignore_user_abort(true);

    if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        Response::json(['error' => 'Upload failed'], 400);
    }
    $tmp = (string)$_FILES['file']['tmp_name'];
    $handle = fopen($tmp, 'r');
    if ($handle === false) {
        Response::json(['error' => 'Could not read uploaded file'], 400);
    }

    header('Content-Type: application/x-ndjson; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');

    $rawHeader = fgetcsv($handle, 0, ';', '"', '\\');
    if (!$rawHeader) {
        echo json_encode(['error' => 'Empty CSV']) . "\n";
        exit;
    }
    $header = array_map(static fn ($h) => strtolower(trim(str_replace([' ', '-'], '_', (string)$h))), $rawHeader);

    $batch = [];
    $batchSize = 500;
    $imported = 0;
    $skipped = 0;
    $seen = 0;

    while (($row = fgetcsv($handle, 0, ';', '"', '\\')) !== false) {
        if (count($row) !== count($header)) {
            $skipped++;
            continue;
        }
        $assoc = array_combine($header, $row);
        if ($assoc === false) {
            $skipped++;
            continue;
        }
        $batch[] = $assoc;
        $seen++;
        if (count($batch) >= $batchSize) {
            $result = SharedCatalogService::bulkImport($superDb, $batch);
            $imported += $result['imported'];
            $skipped += $result['skipped'];
            $batch = [];
            echo json_encode(['seen' => $seen, 'imported' => $imported, 'skipped' => $skipped]) . "\n";
            @ob_flush();
            flush();
        }
    }
    if ($batch) {
        $result = SharedCatalogService::bulkImport($superDb, $batch);
        $imported += $result['imported'];
        $skipped += $result['skipped'];
    }
    fclose($handle);
    echo json_encode(['seen' => $seen, 'imported' => $imported, 'skipped' => $skipped, 'done' => true]) . "\n";
    exit;
}

function exportSharedCatalog(PDO $superDb): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="shared-catalog.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['id', 'title', 'artist', 'year', 'duo', 'explicit', 'styles', 'languages'], ';');
    $stmt = $superDb->query('SELECT external_id, title, artist, year, duo, explicit, styles, languages FROM shared_songs WHERE is_active = 1 ORDER BY artist, title');
    while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
        $styles = $row['styles'] ? implode(',', json_decode((string)$row['styles'], true) ?: []) : '';
        $languages = $row['languages'] ? implode(',', json_decode((string)$row['languages'], true) ?: []) : '';
        fputcsv($out, [
            $row['external_id'],
            $row['title'],
            $row['artist'],
            $row['year'],
            $row['duo'],
            $row['explicit'],
            $styles,
            $languages,
        ], ';');
    }
    fclose($out);
    exit;
}

/** @return list<array<string,mixed>> */
function listTenantsWithDomains(PDO $db): array
{
    $tenants = $db->query('SELECT * FROM tenants ORDER BY created_at DESC')->fetchAll();
    if (!$tenants) {
        return [];
    }
    $domains = $db->query(
        'SELECT id, tenant_id, domain, is_primary
         FROM tenant_domains
         ORDER BY is_primary DESC, domain ASC'
    )->fetchAll();
    $byTenant = [];
    foreach ($domains as $row) {
        $byTenant[(int)$row['tenant_id']][] = [
            'id' => (int)$row['id'],
            'domain' => $row['domain'],
            'is_primary' => (bool)$row['is_primary'],
        ];
    }
    foreach ($tenants as &$tenant) {
        $tenant['id'] = (int)$tenant['id'];
        $tenant['domains'] = $byTenant[$tenant['id']] ?? [];
    }
    return $tenants;
}

/** @return array<string,mixed>|null */
function loadTenantWithDomains(PDO $db, int $tenantId): ?array
{
    $stmt = $db->prepare('SELECT * FROM tenants WHERE id = ? LIMIT 1');
    $stmt->execute([$tenantId]);
    $tenant = $stmt->fetch();
    if (!$tenant) {
        return null;
    }
    $stmt = $db->prepare(
        'SELECT id, domain, is_primary
         FROM tenant_domains
         WHERE tenant_id = ?
         ORDER BY is_primary DESC, domain ASC'
    );
    $stmt->execute([$tenantId]);
    $tenant['id'] = (int)$tenant['id'];
    $tenant['domains'] = array_map(static fn (array $row): array => [
        'id' => (int)$row['id'],
        'domain' => $row['domain'],
        'is_primary' => (bool)$row['is_primary'],
    ], $stmt->fetchAll());
    return $tenant;
}

function updateTenantRecord(PDO $db, int $tenantId): never
{
    $input = Request::input();
    $editable = [
        'slug', 'venue_name', 'night_name', 'timezone', 'signup_mode',
        'public_request_url', 'projection_url', 'status',
    ];
    $signupModes = ['display_name', 'account', 'both'];
    $statuses = ['active', 'suspended', 'provisioning'];

    $fields = [];
    $params = [];
    foreach ($editable as $key) {
        if (!array_key_exists($key, $input)) {
            continue;
        }
        $value = $input[$key];
        $value = is_string($value) ? trim($value) : $value;
        if ($key === 'signup_mode' && !in_array($value, $signupModes, true)) {
            Response::json(['error' => 'Invalid signup_mode'], 400);
        }
        if ($key === 'status' && !in_array($value, $statuses, true)) {
            Response::json(['error' => 'Invalid status'], 400);
        }
        if (in_array($key, ['public_request_url', 'projection_url'], true)) {
            $value = $value === '' ? null : $value;
            if ($value !== null && !filter_var($value, FILTER_VALIDATE_URL)) {
                Response::json(['error' => ucfirst(str_replace('_', ' ', $key)) . ' must be a URL'], 400);
            }
        }
        if (in_array($key, ['slug', 'venue_name', 'night_name'], true) && $value === '') {
            Response::json(['error' => ucfirst(str_replace('_', ' ', $key)) . ' is required'], 400);
        }
        $fields[] = "{$key} = ?";
        $params[] = $value;
    }

    if (!$fields) {
        Response::json(['error' => 'No editable fields supplied'], 400);
    }

    $params[] = $tenantId;
    $stmt = $db->prepare('UPDATE tenants SET ' . implode(', ', $fields) . ' WHERE id = ?');
    $stmt->execute($params);
    Response::json(['tenant' => loadTenantWithDomains($db, $tenantId)]);
}

/** @param array<string,mixed> $tenant */
function provisionTenant(array $tenant): void
{
    $root = dirname(__DIR__);
    $super = Connection::super();
    $dbName = $tenant['database_name'];
    if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName)) {
        throw new RuntimeException('Invalid database name');
    }
    $super->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $tenantDb = Connection::tenant($dbName);
    foreach (glob($root . '/migrations/tenant/*.sql') ?: [] as $file) {
        $tenantDb->exec(file_get_contents($file) ?: '');
    }
    try {
        ContentService::ensureTenantDirectory((string)$tenant['slug']);
    } catch (Throwable $error) {
        throw new RuntimeException('Tenant database was created, but the content folder could not be created. Make /content writable by PHP-FPM/Apache and retry. Details: ' . $error->getMessage(), 0, $error);
    }
}
