<?php

declare(strict_types=1);

use PanicMic\Auth\Auth;
use PanicMic\Database\Connection;
use PanicMic\Services\BillingService;
use PanicMic\Http\ActivityController;
use PanicMic\Http\AuthController;
use PanicMic\Http\BillingController;
use PanicMic\Http\BrandingController;
use PanicMic\Http\ContentController;
use PanicMic\Http\DisplayController;
use PanicMic\Http\EventController;
use PanicMic\Http\HealthController;
use PanicMic\Http\MarketingController;
use PanicMic\Http\PageRenderer;
use PanicMic\Http\PublicEventsController;
use PanicMic\Http\QueueController;
use PanicMic\Http\ScheduleController;
use PanicMic\Http\SessionController;
use PanicMic\Http\SettingsController;
use PanicMic\Http\SignupController;
use PanicMic\Http\SongController;
use PanicMic\Http\SuperController;
use PanicMic\Http\TeamController;
use PanicMic\Http\VenueController;
use PanicMic\Services\ContentService;
use PanicMic\Services\DisplayService;
use PanicMic\Services\QueueService;
use PanicMic\Services\SessionService;
use PanicMic\Services\SettingsService;
use PanicMic\Services\SongService;
use PanicMic\Support\AccessLog;
use PanicMic\Support\Env;
use PanicMic\Support\ErrorReporter;
use PanicMic\Support\Request;
use PanicMic\Support\Response;
use PanicMic\Support\Security;
use PanicMic\Support\Url;
use PanicMic\Tenant\TenantContext;

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

$marketingHost = strtolower((string)Env::get('MARKETING_HOST', 'panicmic.com') ?? 'panicmic.com');
$onMarketingHost = $marketingHost !== '' && TenantContext::host() === $marketingHost;

$tenantContext = ($onSignupHost || $onSuperHost || $onMarketingHost) ? null : TenantContext::resolve();

try {
    if ($path === '/health') {
        Response::json(['ok' => true]);
    }
    if ($path === '/csrf') {
        Response::json(['csrf' => Security::csrfToken()]);
    }
    // Stripe webhook arrives without tenant context and must not be
    // CSRF-protected. Signature verification inside the controller is
    // the auth path.
    if ($path === '/webhooks/stripe' && $method === 'POST') {
        BillingController::webhook();
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

    if ($onMarketingHost) {
        if ($path === '/' && $method === 'GET') {
            MarketingController::render('home');
        }
        if ($path === '/privacy' && $method === 'GET') {
            MarketingController::render('privacy');
        }
        if ($path === '/terms' && $method === 'GET') {
            MarketingController::render('terms');
        }
        if ($path === '/signup' && $method === 'GET') {
            if ($signupHost !== '') {
                Response::redirect('https://' . $signupHost . '/');
            }
            SignupController::page();
        }
        if ($path === '/api/signup' && $method === 'POST') {
            Security::requireCsrf();
            SignupController::register();
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
    $_SERVER['PANICMIC_TENANT_SLUG'] = (string)($tenant['slug'] ?? '');

    if (str_starts_with($path, '/files')) {
        $filePath = substr($path, strlen('/files')) ?: '';
        ContentService::serve((string)$tenant['slug'], $filePath);
    }

    AuthController::consumeImpersonationToken($tenant);

    // Session resolution: read-only vs bootstrapping.
    //
    // Creating a karaoke session is a deliberate act by the KJ, but the old
    // rule let any non-"public landing" request bootstrap one — including
    // /api/queue and /api/events, which displays poll continuously. A single
    // display left running could therefore conjure a session on its own, and
    // read paths disagreed with the /display page (which used latest()) about
    // which session was current. That divergence is exactly how a display
    // ends up rendering one session while the dashboard drives another.
    //
    // Rule: no GET ever creates a session. Reads observe; only KJ actions
    // (starting a night, or a mutation on an existing show) bootstrap one.
    $session = $method === 'GET'
        ? SessionService::latest($db, $tenant['night_name'])
        : SessionService::current($db, $tenant['night_name']);
    $settings = SettingsService::all($db);

    // Admin routes require an active tenant session or super impersonation.
    if ($method === 'GET'
        && ((str_starts_with($path, '/admin')
            && !in_array($path, [
                '/admin/login',
                '/admin/forgot-password',
                '/admin/reset-password',
            ], true)) || $path === '/display/control')
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
        '/api/admin/password-reset/request', '/api/admin/password-reset/confirm',
        '/api/admin/end-impersonation', '/admin/end-impersonation',
        '/api/admin/branding', '/api/admin/settings',
        // Allow lapsed tenants to reactivate via the billing UI.
        '/api/billing/checkout', '/api/billing/plans',
        // KJs must always be able to close an in-progress session even
        // if the subscription has lapsed — don't strand a live show.
        '/api/admin/sessions/end',
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
        $path === '/events' && $method === 'GET' => PageRenderer::render('public-events', $tenant, $session),
        $path === '/help' && $method === 'GET' => PageRenderer::render('help', $tenant, $session),
        $path === '/admin/login' && $method === 'GET' => PageRenderer::render('admin-login', $tenant, $session),
        $path === '/admin/forgot-password' && $method === 'GET' => PageRenderer::render('admin-forgot-password', $tenant, $session),
        $path === '/admin/reset-password' && $method === 'GET' => PageRenderer::render('admin-reset-password', $tenant, $session),
        // The KJ console. /admin/queue, /admin/singers and /display/control
        // are legacy aliases kept for existing bookmarks — the console holds
        // the queue, singers and display controls in one screen, so they are
        // no longer advertised separately in the sidebar.
        in_array($path, ['/admin/dashboard', '/admin/queue', '/admin/singers', '/display/control'], true) && $method === 'GET' => PageRenderer::render('admin-dashboard', $tenant, $session),
        $path === '/admin/songs' && $method === 'GET' => PageRenderer::render('admin-songs', $tenant, $session),
        $path === '/admin/venues' && $method === 'GET' => PageRenderer::render('admin-venues', $tenant, $session),
        $path === '/admin/schedule' && $method === 'GET' => PageRenderer::render('admin-schedule', $tenant, $session),
        $path === '/admin/content' && $method === 'GET' => PageRenderer::render('admin-content', $tenant, $session),
        $path === '/admin/settings' && $method === 'GET' => PageRenderer::render('admin-settings', $tenant, $session),
        $path === '/admin/team' && $method === 'GET' => (static function () use ($tenant, $session): never {
            Auth::requireTenantRole('tenant_admin');
            PageRenderer::render('admin-team', $tenant, $session);
        })(),
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
        // Deep health: database reachability, tenant schema drift, and
        // websocket daemon state. `/health` above is bare liveness and
        // runs before tenant resolution; this one needs $db.
        $path === '/api/health' && $method === 'GET' => HealthController::index($db),
        in_array($path, ['/api/songs', '/api/catalog'], true) && $method === 'GET' => Response::json(SongService::blendedSearch($db, Connection::super(), $_GET)),
        $path === '/api/queue' && $method === 'GET' => Response::json([
            'queue' => QueueService::queue($db, (int)$session['id'], Connection::super()),
            // Honor ?screen=<id> so display windows opened with a non-main
            // screen receive their own authoritative state. Defaults to
            // 'main' for backwards compatibility.
            'display' => DisplayService::state(
                $db,
                (int)$session['id'],
                preg_replace('/[^a-z0-9_-]/i', '', (string)($_GET['screen'] ?? '')) ?: 'main'
            ),
            'screen_config' => DisplayService::screen(
                $db,
                (int)$session['id'],
                preg_replace('/[^a-z0-9_-]/i', '', (string)($_GET['screen'] ?? '')) ?: 'main',
            ),
        ]),
        in_array($path, ['/api/requests', '/requests'], true) && $method === 'POST' => QueueController::submit($db, $tenant, $session, $settings),
        // KJ walk-up entry. Distinct from the public endpoint above: no
        // public rate limit, ignores the requests-paused gate, accepts a
        // free-text song, and lands straight in the rotation.
        $path === '/api/admin/requests' && $method === 'POST' => QueueController::addWalkUp($db, $tenant, $session, $settings),
        (bool)preg_match('#^/api/requests/(\d+)/status$#', $path, $m) && $method === 'PATCH' => QueueController::updateStatus($db, $tenant, $session, (int)$m[1]),
        (bool)preg_match('#^/api/requests/(\d+)/youtube$#', $path, $m) && $method === 'POST' => QueueController::attachYouTubeVideo($db, $session, (int)$m[1]),
        (bool)preg_match('#^/api/requests/(\d+)/manual-video$#', $path, $m) && $method === 'POST' => QueueController::attachManualVideo($db, $session, (int)$m[1]),
        (bool)preg_match('#^/api/requests/(\d+)/approve$#', $path, $m) && $method === 'POST' => QueueController::approve($db, $tenant, $session, (int)$m[1]),
        (bool)preg_match('#^/api/requests/(\d+)/priority$#', $path, $m) && $method === 'POST' => QueueController::setPriority($db, $tenant, $session, (int)$m[1]),
        $path === '/api/queue/reorder' && $method === 'PATCH' => QueueController::reorder($db, $tenant, $session),
        $path === '/api/display/state' && $method === 'GET' => DisplayController::showState($db, $tenant, $session),
        $path === '/api/display/state' && $method === 'POST' => DisplayController::updateState($db, $tenant, $session),
        $path === '/api/display/screens' && $method === 'GET' => DisplayController::listScreens($db, $tenant, $session),
        $path === '/api/display/screens' && $method === 'POST' => DisplayController::saveScreen($db, $tenant, $session),
        (bool)preg_match('#^/api/display/screens/([a-z0-9_-]+)$#', $path, $m) && $method === 'DELETE' => DisplayController::deleteScreen($db, $tenant, $session, $m[1]),
        $path === '/api/display/play' && $method === 'POST' => DisplayController::triggerPlay($db, $tenant, $session),
        $path === '/api/display/pause' && $method === 'POST' => DisplayController::pause($db, $tenant, $session),
        $path === '/api/announcements' && $method === 'POST' => DisplayController::announce($db, $tenant, $session),
        $path === '/api/admin/activity' && $method === 'GET' => ActivityController::index($db, $tenant, $session),
        $path === '/api/events' && $method === 'GET' => QueueController::events($db, (int)$session['id']),

        // ----- Public events / schedule (read-only, unauthenticated)
        $path === '/api/public/schedule' && $method === 'GET' => PublicEventsController::upcoming($db),
        $path === '/api/public/events/past' && $method === 'GET' => PublicEventsController::past($db),
        (bool)preg_match('#^/api/public/events/(\d+)$#', $path, $m) && $method === 'GET' => PublicEventsController::setlist($db, (int)$m[1]),

        // ----- Auth + impersonation
        $path === '/api/admin/login' && $method === 'POST' => AuthController::tenantLogin($db),
        $path === '/api/admin/password-reset/request' && $method === 'POST' => AuthController::requestPasswordReset($db, Url::origin()),
        $path === '/api/admin/password-reset/confirm' && $method === 'POST' => AuthController::resetPassword($db),
        $path === '/api/admin/logout' && $method === 'POST' => AuthController::logoutTenant(),
        // GET logout for the nav link: clears both the tenant session and
        // any super-admin impersonation, then returns to the login screen.
        $path === '/admin/logout' && $method === 'GET' => (static function (): never {
            unset($_SESSION['tenant_user'], $_SESSION['super_admin']);
            Security::regenerateSession();
            Response::redirect('/admin/login');
        })(),
        $path === '/admin/end-impersonation' && $method === 'GET' => AuthController::endImpersonation(),
        $path === '/api/admin/end-impersonation' && $method === 'POST' => AuthController::endImpersonation(),

        // ----- Tenant admin API
        $path === '/api/admin/sessions/start' && $method === 'POST' => SessionController::start($db, $tenant, $session),
        $path === '/api/admin/sessions/end' && $method === 'POST' => SessionController::end($db, $tenant, $session),
        $path === '/api/admin/team' && $method === 'GET' => TeamController::index($db),
        $path === '/api/admin/team' && $method === 'POST' => TeamController::create($db, $tenant, Url::origin()),
        (bool)preg_match('#^/api/admin/team/(\d+)$#', $path, $m) && $method === 'PATCH' => TeamController::update($db, (int)$m[1]),
        (bool)preg_match('#^/api/admin/team/(\d+)/invite$#', $path, $m) && $method === 'POST' => TeamController::resendInvite($db, (int)$m[1], Url::origin()),

        // ----- Venues
        $path === '/api/admin/venues' && $method === 'GET' => VenueController::index($db, $tenant, $session),
        $path === '/api/admin/venues' && $method === 'POST' => VenueController::create($db, $tenant, $session),
        (bool)preg_match('#^/api/admin/venues/(\d+)$#', $path, $m) && $method === 'PATCH' => VenueController::update($db, $tenant, $session, (int)$m[1]),
        (bool)preg_match('#^/api/admin/venues/(\d+)$#', $path, $m) && $method === 'DELETE' => VenueController::delete($db, $tenant, $session, (int)$m[1]),

        // ----- Recurring schedules
        $path === '/api/admin/schedules' && $method === 'GET' => ScheduleController::index($db, $tenant, $session),
        $path === '/api/admin/schedules' && $method === 'POST' => ScheduleController::create($db, $tenant, $session),
        (bool)preg_match('#^/api/admin/schedules/(\d+)$#', $path, $m) && $method === 'PATCH' => ScheduleController::update($db, $tenant, $session, (int)$m[1]),
        (bool)preg_match('#^/api/admin/schedules/(\d+)$#', $path, $m) && $method === 'DELETE' => ScheduleController::delete($db, $tenant, $session, (int)$m[1]),

        // ----- Calendar events (one-off + materialized occurrences)
        $path === '/api/admin/events' && $method === 'GET' => EventController::index($db, $tenant, $session),
        $path === '/api/admin/events' && $method === 'POST' => EventController::create($db, $tenant, $session),
        (bool)preg_match('#^/api/admin/events/(\d+)/start$#', $path, $m) && $method === 'POST' => EventController::start($db, $tenant, $session, (int)$m[1]),
        (bool)preg_match('#^/api/admin/events/(\d+)$#', $path, $m) && $method === 'PATCH' => EventController::update($db, $tenant, $session, (int)$m[1]),
        (bool)preg_match('#^/api/admin/events/(\d+)$#', $path, $m) && $method === 'DELETE' => EventController::cancel($db, $tenant, $session, (int)$m[1]),
        $path === '/api/admin/billing' && $method === 'GET' => BillingController::summary($db, $tenant),
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
        (bool)preg_match('#^/api/admin/songs/(\d+)/fetch-album-art$#', $path, $m) && $method === 'POST' => SongController::fetchAlbumArt($db, $tenant, (int)$m[1]),
        (bool)preg_match('#^/api/admin/songs/(\d+)/cache-album-art-url$#', $path, $m) && $method === 'POST' => SongController::cacheAlbumArtUrl($db, $tenant, (int)$m[1]),
        (bool)preg_match('#^/api/admin/songs/(\d+)/video$#', $path, $m) && $method === 'POST' => SongController::uploadVideo($db, $tenant, (int)$m[1]),
        $path === '/api/admin/content' && $method === 'GET' => ContentController::index($tenant),
        $path === '/api/admin/content' && $method === 'POST' => ContentController::upload($tenant),

        // ----- Self-serve billing (Stripe)
        $path === '/api/billing/plans' && $method === 'GET' => BillingController::plans(),
        $path === '/api/billing/checkout' && $method === 'POST' => BillingController::checkout($db, $tenant),

        default => Response::json(['error' => 'Not found'], 404),
    };
} catch (Throwable $error) {
    $status = $error instanceof InvalidArgumentException ? 400 : 500;
    // Only ship 5xx to the error tracker; 400-class errors are user
    // input problems, not server bugs.
    if ($status >= 500) {
        $errorId = bin2hex(random_bytes(8));
        ErrorReporter::report($error, "error_id={$errorId} Front controller {$method} {$path}");
        $message = Env::get('APP_ENV') === 'production'
            ? "Something went wrong. Reference: {$errorId}"
            : $error->getMessage();
        Response::json(['error' => $message, 'error_id' => $errorId], $status);
    }
    Response::json(['error' => $error->getMessage()], $status);
}
