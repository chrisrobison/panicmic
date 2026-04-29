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
use NextUp\Services\SongService;
use NextUp\Services\TenantBrandingService;
use NextUp\Services\YouTubeService;
use NextUp\Support\Env;
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

    $session = SessionService::current($db, $tenant['night_name']);
    $settings = SettingsService::all($db);

    if ($method === 'GET' && ((str_starts_with($path, '/admin') && $path !== '/admin/login') || $path === '/display/control') && empty($_SESSION['tenant_user'])) {
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
        $path === '/api/config' && $method === 'GET' => Response::json(['tenant' => publicTenant($tenant), 'session' => $session, 'settings' => $settings, 'csrf' => Security::csrfToken()]),
        $path === '/api/songs' && $method === 'GET' => Response::json(['songs' => SongService::search($db, $_GET)]),
        $path === '/api/queue' && $method === 'GET' => Response::json(['queue' => QueueService::queue($db, (int)$session['id']), 'display' => DisplayService::state($db, (int)$session['id'])]),
        in_array($path, ['/api/requests', '/requests'], true) && $method === 'POST' => submitRequest($db, $tenant, $session, $settings),
        (bool)preg_match('#^/api/requests/(\d+)/status$#', $path, $m) && $method === 'PATCH' => updateStatus($db, $tenant, $session, (int)$m[1]),
        (bool)preg_match('#^/api/requests/(\d+)/youtube$#', $path, $m) && $method === 'POST' => attachYouTubeVideo($db, $session, (int)$m[1]),
        $path === '/api/queue/reorder' && $method === 'PATCH' => reorderQueue($db, $tenant, $session),
        $path === '/api/display/state' && $method === 'POST' => updateDisplay($db, $tenant, $session),
        $path === '/api/announcements' && $method === 'POST' => createAnnouncement($db, $tenant, $session),
        $path === '/api/admin/login' && $method === 'POST' => tenantLogin($db),
        $path === '/api/admin/logout' && $method === 'POST' => logoutTenant(),
        $path === '/api/admin/branding' && $method === 'GET' => tenantBranding($tenant),
        $path === '/api/admin/branding' && $method === 'POST' => updateTenantBranding($tenant),
        $path === '/api/admin/songs' && $method === 'POST' => createSong($db, $tenant),
        (bool)preg_match('#^/api/admin/songs/(\d+)$#', $path, $m) && $method === 'PATCH' => updateSong($db, $tenant, (int)$m[1]),
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
    if ($name === '' || strlen($name) > 160 || empty($input['song_id'])) {
        Response::json(['error' => 'Display name and song are required'], 400);
    }
    $token = $_SESSION['requester_token'] ??= bin2hex(random_bytes(32));
    $requestId = QueueService::submit($db, (int)$session['id'], $input, $token, (bool)($settings['prevent_duplicate_requests'] ?? true));
    autoAttachYouTubeVideo($db, $requestId);
    EventBus::publish($db, 'request:created', ['requestId' => $requestId]);
    EventBus::publish($db, 'queue:updated', ['queue' => QueueService::queue($db, (int)$session['id'])]);
    Response::json(['requestId' => $requestId]);
}

function autoAttachYouTubeVideo(PDO $db, int $requestId): void
{
    if (!YouTubeService::isEnabled()) {
        return;
    }
    $song = QueueService::requestSong($db, $requestId);
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
    $song = QueueService::requestSong($db, $requestId);
    if (!$song) {
        Response::json(['error' => 'Request not found'], 404);
    }
    $video = YouTubeService::findKaraokeVideo($song);
    if (!$video) {
        Response::json(['error' => 'No YouTube karaoke video found or YouTube is not configured'], 404);
    }
    YouTubeService::attachToRequest($db, $requestId, $video);
    EventBus::publish($db, 'request:youtube_attached', ['requestId' => $requestId, 'video' => $video]);
    EventBus::publish($db, 'queue:updated', ['queue' => QueueService::queue($db, (int)$session['id'])]);
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
    EventBus::publish($db, 'queue:updated', ['queue' => QueueService::queue($db, (int)$session['id'])]);
    Response::json(['ok' => true]);
}

/** @param array<string,mixed> $tenant @param array<string,mixed> $session */
function reorderQueue(PDO $db, array $tenant, array $session): never
{
    Auth::requireTenantRole('kj', 'tenant_admin');
    $ids = array_map('intval', Request::input()['request_ids'] ?? []);
    QueueService::reorder($db, (int)$session['id'], $ids);
    EventBus::publish($db, 'queue:updated', ['queue' => QueueService::queue($db, (int)$session['id'])]);
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
    Auth::requireSuper();
    if ($path === '/api/super/tenants' && $method === 'GET') {
        Response::json(['tenants' => $db->query('SELECT * FROM tenants ORDER BY created_at DESC')->fetchAll()]);
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
    Response::json(['error' => 'Not found'], 404);
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
