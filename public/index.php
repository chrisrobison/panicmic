<?php

declare(strict_types=1);

use NextUp\Auth\Auth;
use NextUp\Database\Connection;
use NextUp\Services\BillingService;
use NextUp\Http\AuthController;
use NextUp\Http\BrandingController;
use NextUp\Http\ContentController;
use NextUp\Http\DisplayController;
use NextUp\Http\PageRenderer;
use NextUp\Http\QueueController;
use NextUp\Http\SessionController;
use NextUp\Http\SettingsController;
use NextUp\Http\SignupController;
use NextUp\Http\SongController;
use NextUp\Http\SuperController;
use NextUp\Services\ContentService;
use NextUp\Services\DisplayService;
use NextUp\Services\QueueService;
use NextUp\Services\SessionService;
use NextUp\Services\SettingsService;
use NextUp\Services\SongService;
use NextUp\Support\AccessLog;
use NextUp\Support\Env;
use NextUp\Support\ErrorReporter;
use NextUp\Support\Request;
use NextUp\Support\Response;
use NextUp\Support\Security;
use NextUp\Tenant\TenantContext;

require dirname(__DIR__) . '/src/autoload.php';

Env::load(dirname(__DIR__) . '/.env');
ErrorReporter::install();
AccessLog::begin();
Security::startSession();
Security::headers();

$path = Request::path();
$method = Request::method();

// The dedicated signup host (e.g. signup.panicmic.com) serves the
// venue-signup UI without doing tenant resolution. Any path on this host
// other than the signup flow gets a 404 — tenant-scoped routes never
// belong here. We have to detect this before TenantContext::resolve()
// runs because that path would reject the host as unrecognised.
$signupHost = strtolower((string)Env::get('SIGNUP_HOST', '') ?? '');
$onSignupHost = $signupHost !== '' && TenantContext::host() === $signupHost;

// The dedicated super-admin host (e.g. admin.panicmic.com) serves only
// the /super and /api/super routes. Tenant-scoped paths return 404 from
// this host so it can be exposed publicly without leaking tenant
// surfaces. Same detection-before-resolve pattern as SIGNUP_HOST.
$superHost = strtolower((string)Env::get('SUPER_HOST', '') ?? '');
$onSuperHost = $superHost !== '' && TenantContext::host() === $superHost;

$tenantContext = ($onSignupHost || $onSuperHost) ? null : TenantContext::resolve();

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

    if ($onSignupHost) {
        // On the signup host, "/" and "/signup" both render the landing.
        // Everything else returns 404 — there is no tenant on this host
        // and we never want to leak tenant-shaped responses from here.
        if (($path === '/' || $path === '/signup') && $method === 'GET') {
            SignupController::page();
        }
        if ($path === '/api/signup' && $method === 'POST') {
            Security::requireCsrf();
            SignupController::register();
        }
        if ($path === '/signup/accept' && $method === 'GET') {
            SignupController::acceptPage();
        }
        if ($path === '/api/signup/accept' && $method === 'POST') {
            Security::requireCsrf();
            SignupController::accept();
        }
        Response::json(['error' => 'Not found'], 404);
    }

    if ($onSuperHost) {
        // On the super-admin host, "/" redirects to the tenant list and
        // only /super and /api/super dispatch. Everything else 404s so
        // this host can sit on the public internet without exposing any
        // tenant surface area.
        if ($path === '/' && $method === 'GET') {
            Response::redirect('/super/tenants');
        }
        if (str_starts_with($path, '/super') || str_starts_with($path, '/api/super')) {
            SuperController::dispatch($path, $method);
        }
        Response::json(['error' => 'Not found'], 404);
    }

    // Legacy behavior: when SUPER_HOST is unset, /super is served from
    // any allowed host. When SUPER_HOST is set, /super is only served
    // from that host (handled in the $onSuperHost branch above) so
    // tenant hosts return a clean 404 for these paths.
    if ($superHost === ''
        && (str_starts_with($path, '/super') || str_starts_with($path, '/api/super'))
    ) {
        SuperController::dispatch($path, $method);
    }

    // Public signup flow (shared across all hostnames; resolves against
    // the super DB). These run before tenant resolution because
    // /signup lives on the marketing host without a tenant context.
    if ($path === '/signup' && $method === 'GET') {
        SignupController::page();
    }
    if ($path === '/api/signup' && $method === 'POST') {
        Security::requireCsrf();
        SignupController::register();
    }
    if ($path === '/signup/accept' && $method === 'GET') {
        SignupController::acceptPage();
    }
    if ($path === '/api/signup/accept' && $method === 'POST') {
        Security::requireCsrf();
        SignupController::accept();
    }
    if (!$tenantContext) {
        Response::json(['error' => 'Tenant context required'], 400);
    }

    $db = $tenantContext->db;
    $tenant = $tenantContext->tenant;

    // Tag this request for the structured access log.
    $_SERVER['NEXTUP_TENANT_SLUG'] = (string)($tenant['slug'] ?? '');

    if (str_starts_with($path, '/files')) {
        $filePath = substr($path, strlen('/files')) ?: '';
        ContentService::serve((string)$tenant['slug'], $filePath);
    }

    AuthController::consumeImpersonationToken($tenant);

    $session = SessionService::current($db, $tenant['night_name']);
    $settings = SettingsService::all($db);

    // Admin routes require an active tenant session or super impersonation.
    if ($method === 'GET'
        && ((str_starts_with($path, '/admin') && $path !== '/admin/login') || $path === '/display/control')
        && empty($_SESSION['tenant_user'])
        && empty($_SESSION['super_admin'])
    ) {
        Response::redirect('/admin/login');
    }

    // Register the request-scoped DB so Auth::requireTenantRole can
    // re-check is_active inside any controller without threading PDO
    // through every signature.
    Auth::useDb($db);

    // Honor user deactivation immediately instead of waiting for session
    // expiry — covers GET routes that don't pass through requireTenantRole.
    if (!empty($_SESSION['tenant_user'])) {
        Auth::ensureSessionUserActive($db);
    }

    if ($method !== 'GET') {
        Security::requireCsrf();
    }

    // Paywall: write actions are blocked when the tenant subscription
    // has lapsed. Read paths (browsing the catalog, watching the queue)
    // stay open so a singer can still see what's going on while the
    // owner sorts out billing.
    $isMutation = in_array($method, ['POST', 'PATCH', 'DELETE'], true);
    $isBillingExempt = in_array($path, [
        '/api/admin/login', '/api/admin/logout',
        '/api/admin/end-impersonation', '/admin/end-impersonation',
        '/api/admin/branding', '/api/admin/settings',
    ], true);
    if ($isMutation && !$isBillingExempt && !BillingService::hasAccess($tenant)) {
        Response::json([
            'error' => 'This venue\'s subscription is inactive. Visit /super or contact support to reactivate.',
            'subscription_status' => $tenant['subscription_status'] ?? 'inactive',
        ], 402);
    }

    match (true) {
        // ----- Public pages
        $path === '/' && $method === 'GET' => PageRenderer::render('public', $tenant, $session),
        $path === '/songs' && $method === 'GET' => PageRenderer::render('songs', $tenant, $session),
        $path === '/me' && $method === 'GET' => PageRenderer::render('me', $tenant, $session),
        $path === '/help' && $method === 'GET' => PageRenderer::render('help', $tenant, $session),
        $path === '/admin/login' && $method === 'GET' => PageRenderer::render('admin-login', $tenant, $session),
        in_array($path, ['/admin/dashboard', '/admin/queue', '/admin/singers', '/display/control'], true) && $method === 'GET' => PageRenderer::render('admin-dashboard', $tenant, $session),
        $path === '/admin/songs' && $method === 'GET' => PageRenderer::render('admin-songs', $tenant, $session),
        $path === '/admin/content' && $method === 'GET' => PageRenderer::render('admin-content', $tenant, $session),
        $path === '/admin/settings' && $method === 'GET' => PageRenderer::render('admin-settings', $tenant, $session),
        $path === '/admin/promote' && $method === 'GET' => PageRenderer::render('admin-promote', $tenant, $session),
        $path === '/admin/help' && $method === 'GET' => PageRenderer::render('admin-help', $tenant, $session),
        in_array($path, ['/display', '/display/fullscreen'], true) && $method === 'GET' => PageRenderer::render('display', $tenant, $session),

        // ----- Public API
        $path === '/api/config' && $method === 'GET' => Response::json([
            'tenant' => PageRenderer::publicTenant($tenant),
            'session' => $session,
            'settings' => $settings,
            'csrf' => Security::csrfToken(),
            'actor' => Auth::currentTenantActor(),
            'actingAsSuper' => Auth::actingAsSuper(),
        ]),
        in_array($path, ['/api/songs', '/api/catalog'], true) && $method === 'GET' => Response::json(SongService::blendedSearch($db, Connection::super(), $_GET)),
        $path === '/api/queue' && $method === 'GET' => Response::json([
            'queue' => QueueService::queue($db, (int)$session['id'], Connection::super()),
            'display' => DisplayService::state($db, (int)$session['id']),
        ]),
        in_array($path, ['/api/requests', '/requests'], true) && $method === 'POST' => QueueController::submit($db, $tenant, $session, $settings),
        (bool)preg_match('#^/api/requests/(\d+)/status$#', $path, $m) && $method === 'PATCH' => QueueController::updateStatus($db, $tenant, $session, (int)$m[1]),
        (bool)preg_match('#^/api/requests/(\d+)/youtube$#', $path, $m) && $method === 'POST' => QueueController::attachYouTubeVideo($db, $session, (int)$m[1]),
        $path === '/api/queue/reorder' && $method === 'PATCH' => QueueController::reorder($db, $tenant, $session),
        $path === '/api/display/state' && $method === 'GET' => DisplayController::showState($db, $tenant, $session),
        $path === '/api/display/state' && $method === 'POST' => DisplayController::updateState($db, $tenant, $session),
        $path === '/api/display/screens' && $method === 'GET' => DisplayController::listScreens($db, $tenant, $session),
        $path === '/api/display/screens' && $method === 'POST' => DisplayController::saveScreen($db, $tenant, $session),
        (bool)preg_match('#^/api/display/screens/([a-z0-9_-]+)$#', $path, $m) && $method === 'DELETE' => DisplayController::deleteScreen($db, $tenant, $session, $m[1]),
        $path === '/api/announcements' && $method === 'POST' => DisplayController::announce($db, $tenant, $session),
        $path === '/api/events' && $method === 'GET' => QueueController::events($db),

        // ----- Auth + impersonation
        $path === '/api/admin/login' && $method === 'POST' => AuthController::tenantLogin($db),
        $path === '/api/admin/logout' && $method === 'POST' => AuthController::logoutTenant(),
        $path === '/admin/end-impersonation' && $method === 'GET' => AuthController::endImpersonation(),
        $path === '/api/admin/end-impersonation' && $method === 'POST' => AuthController::endImpersonation(),

        // ----- Tenant admin API
        $path === '/api/admin/sessions/start' && $method === 'POST' => SessionController::start($db, $tenant, $session),
        $path === '/api/admin/sessions/end' && $method === 'POST' => SessionController::end($db, $tenant, $session),
        $path === '/api/admin/settings' && $method === 'GET' => SettingsController::index($db),
        $path === '/api/admin/settings' && $method === 'POST' => SettingsController::update($db),
        $path === '/api/admin/branding' && $method === 'GET' => BrandingController::show($tenant),
        $path === '/api/admin/branding' && $method === 'POST' => BrandingController::update($tenant),
        $path === '/api/admin/songs' && $method === 'GET' => SongController::listAdmin($db),
        $path === '/api/admin/songs' && $method === 'POST' => SongController::create($db, $tenant),
        $path === '/api/admin/songs/import-playlist' && $method === 'POST' => SongController::importYouTubePlaylist($db),
        $path === '/api/admin/songs/export' && $method === 'GET' => SongController::exportCatalog($db, $tenant),
        (bool)preg_match('#^/api/admin/songs/(\d+)$#', $path, $m) && $method === 'PATCH' => SongController::update($db, $tenant, (int)$m[1]),
        (bool)preg_match('#^/api/admin/songs/(\d+)$#', $path, $m) && $method === 'DELETE' => SongController::delete($db, (int)$m[1]),
        $path === '/api/admin/content' && $method === 'GET' => ContentController::index($tenant),
        $path === '/api/admin/content' && $method === 'POST' => ContentController::upload($tenant),

        default => Response::json(['error' => 'Not found'], 404),
    };
} catch (Throwable $error) {
    $status = $error instanceof InvalidArgumentException ? 400 : 500;
    // Only ship 5xx to the error tracker; 400-class errors are user
    // input problems, not server bugs.
    if ($status >= 500) {
        ErrorReporter::report($error, "Front controller {$method} {$path}");
    }
    Response::json(['error' => $error->getMessage()], $status);
}
