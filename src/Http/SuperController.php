<?php

declare(strict_types=1);

namespace PanicMic\Http;

use PanicMic\Auth\Auth;
use PanicMic\Database\Connection;
use PanicMic\Services\BillingService;
use PanicMic\Services\ContentService;
use PanicMic\Services\LastfmService;
use PanicMic\Services\SharedCatalogService;
use PanicMic\Support\Impersonation;
use PanicMic\Support\Request;
use PanicMic\Support\Response;
use PanicMic\Support\Security;
use PanicMic\Support\Url;
use PDO;

/**
 * Routes under /super and /api/super. Kept as one controller because
 * the super-admin plane is small and shares the same auth pre-check.
 */
final class SuperController
{
    public static function dispatch(string $path, string $method): never
    {
        $db = Connection::super();

        if ($path === '/super/login' && $method === 'GET') {
            self::loginPage();
        }
        if ($method !== 'GET') {
            Security::requireCsrf();
        }
        if ($path === '/api/super/login' && $method === 'POST') {
            self::login($db);
        }
        if ($path === '/super/tenants' && $method === 'GET') {
            self::pageTenants();
        }
        if ($path === '/super/catalog' && $method === 'GET') {
            self::pageCatalog();
        }
        if ($path === '/super/logout' && $method === 'POST') {
            self::logout();
        }
        Auth::requireSuper();

        if ($path === '/api/super/tenants' && $method === 'GET') {
            Response::json(['tenants' => self::listTenantsWithDomains($db)]);
        }
        if (preg_match('#^/api/super/tenants/(\d+)$#', $path, $m) && $method === 'GET') {
            $tenant = self::loadTenantWithDomains($db, (int)$m[1]);
            if (!$tenant) {
                Response::json(['error' => 'Tenant not found'], 404);
            }
            Response::json(['tenant' => $tenant]);
        }
        if (preg_match('#^/api/super/tenants/(\d+)$#', $path, $m) && $method === 'PATCH') {
            self::updateTenantRecord($db, (int)$m[1]);
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
            self::createTenant($db);
        }
        if (preg_match('#^/super/tenants/(\d+)/domains$#', $path, $m) && $method === 'POST') {
            $input = Request::input();
            $stmt = $db->prepare('INSERT INTO tenant_domains (tenant_id, domain, is_primary) VALUES (?, ?, ?)');
            $stmt->execute([(int)$m[1], strtolower(trim((string)$input['domain'])), !empty($input['is_primary']) ? 1 : 0]);
            Response::json(['ok' => true]);
        }
        if (preg_match('#^/super/tenants/(\d+)/provision$#', $path, $m) && $method === 'POST') {
            self::provision($db, (int)$m[1]);
        }
        if (preg_match('#^/api/super/tenants/(\d+)/handoff$#', $path, $m) && $method === 'POST') {
            self::generateHandoff($db, (int)$m[1]);
        }
        if ($path === '/api/super/catalog' && $method === 'GET') {
            Response::json(SharedCatalogService::search($db, $_GET));
        }
        if ($path === '/api/super/catalog/stats' && $method === 'GET') {
            Response::json(['total' => SharedCatalogService::count($db)]);
        }
        if ($path === '/api/super/catalog/import' && $method === 'POST') {
            self::importSharedCatalog($db);
        }
        if ($path === '/api/super/catalog/enrich' && $method === 'POST') {
            self::enrichSharedCatalog($db);
        }
        if ($path === '/api/super/catalog/export' && $method === 'GET') {
            self::exportSharedCatalog($db);
        }
        if (preg_match('#^/api/super/catalog/(\d+)$#', $path, $m) && $method === 'DELETE') {
            SharedCatalogService::delete($db, (int)$m[1]);
            Response::json(['ok' => true]);
        }
        if ($path === '/api/super/plans' && $method === 'GET') {
            Response::json(['plans' => BillingService::plans($db)]);
        }
        if (preg_match('#^/api/super/tenants/(\d+)/subscription$#', $path, $m) && $method === 'GET') {
            Response::json(['subscription' => BillingService::subscription($db, (int)$m[1])]);
        }
        if (preg_match('#^/api/super/tenants/(\d+)/subscription$#', $path, $m) && $method === 'PATCH') {
            $input = Request::input();
            if (isset($input['plan_code'])) {
                BillingService::setPlan($db, (int)$m[1], (string)$input['plan_code']);
            }
            if (isset($input['subscription_status'])) {
                BillingService::setStatus($db, (int)$m[1], (string)$input['subscription_status']);
            }
            Response::json(['subscription' => BillingService::subscription($db, (int)$m[1])]);
        }
        Response::json(['error' => 'Not found'], 404);
    }

    /* ------------------- handlers ------------------- */

    private static function loginPage(): never
    {
        PageRenderer::render(
            'super-login',
            ['venue_name' => 'PanicMic', 'night_name' => 'Super Admin', 'primary_color' => '#22c55e', 'accent_color' => '#facc15'],
            ['id' => 0, 'name' => 'Super Admin'],
        );
    }

    private static function login(PDO $db): never
    {
        $input = Request::input();
        $email = trim((string)($input['email'] ?? ''));
        $bucket = Security::loginBucket('super:' . $email);
        Security::rateLimitDb($db, $bucket, 5, 300);

        $stmt = $db->prepare('SELECT * FROM super_admin_users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if (!$user || !password_verify((string)($input['password'] ?? ''), $user['password_hash'])) {
            Response::json(['error' => 'Invalid credentials'], 401);
        }
        Security::rateLimitDbClear($db, $bucket);
        // Rotate the session id at the privilege boundary before elevating.
        Security::regenerateSession();
        $_SESSION['super_admin'] = ['id' => (int)$user['id'], 'email' => $user['email'], 'display_name' => $user['display_name']];
        Response::json(['user' => $_SESSION['super_admin']]);
    }

    private static function pageTenants(): never
    {
        if (empty($_SESSION['super_admin'])) {
            Response::redirect('/super/login');
        }
        PageRenderer::render(
            'super-tenants',
            ['venue_name' => 'PanicMic', 'night_name' => 'Super Admin', 'primary_color' => '#22c55e', 'accent_color' => '#facc15'],
            ['id' => 0, 'name' => 'Super Admin'],
        );
    }

    private static function pageCatalog(): never
    {
        if (empty($_SESSION['super_admin'])) {
            Response::redirect('/super/login');
        }
        PageRenderer::render(
            'super-catalog',
            ['venue_name' => 'PanicMic', 'night_name' => 'Shared Catalog', 'primary_color' => '#22c55e', 'accent_color' => '#facc15'],
            ['id' => 0, 'name' => 'Super Admin'],
        );
    }

    private static function logout(): never
    {
        Auth::requireSuper();
        unset($_SESSION['super_admin']);
        Response::json(['ok' => true]);
    }

    private static function createTenant(PDO $db): never
    {
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

    private static function provision(PDO $db, int $tenantId): never
    {
        $stmt = $db->prepare('SELECT * FROM tenants WHERE id = ?');
        $stmt->execute([$tenantId]);
        $tenant = $stmt->fetch();
        if (!$tenant) {
            Response::json(['error' => 'Tenant not found'], 404);
        }
        try {
            self::provisionTenant($tenant);
            Response::json(['ok' => true]);
        } catch (\Throwable $error) {
            Response::json(['error' => 'Provisioning failed: ' . $error->getMessage()], 500);
        }
    }

    /** @param array<string,mixed> $tenant */
    private static function provisionTenant(array $tenant): void
    {
        // Delegated to TenantProvisioner so the signup flow can call the
        // same path directly (status='provisioning' → ready) without
        // duplicating the CREATE DATABASE + migrations sequence. The
        // service uses Connection::provisioner() internally for the
        // elevated DDL credentials introduced in Phase 8.
        \PanicMic\Services\TenantProvisioner::provision($tenant);
    }

    private static function generateHandoff(PDO $db, int $tenantId): never
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

    /**
     * Enrich a bounded batch of un-enriched shared songs from Last.fm.
     * The CLI script (scripts/enrich-lastfm.php) is the bulk path; this
     * lets a super-admin top up from the catalog UI without shell access.
     */
    private static function enrichSharedCatalog(PDO $superDb): never
    {
        @set_time_limit(0);
        if (!LastfmService::isEnabled()) {
            Response::json(['error' => 'Last.fm is not configured (LASTFM_API_KEY is empty).'], 400);
        }
        $batch = max(1, min(100, (int)(Request::input()['limit'] ?? 25)));
        $rows = $superDb->query(
            "SELECT id, title, artist FROM shared_songs
             WHERE is_active = 1 AND lastfm_enriched_at IS NULL
             ORDER BY id ASC LIMIT {$batch}"
        )->fetchAll(PDO::FETCH_ASSOC);

        $processed = 0;
        $withArt = 0;
        foreach ($rows as $row) {
            $info = LastfmService::trackInfo((string)$row['artist'], (string)$row['title']);
            SharedCatalogService::applyLastfm($superDb, (int)$row['id'], $info ?? []);
            if (!empty($info['album_art_url'])) {
                $withArt++;
            }
            $processed++;
            usleep(200000); // ~5 req/s
        }
        $remaining = (int)$superDb->query(
            'SELECT COUNT(*) FROM shared_songs WHERE is_active = 1 AND lastfm_enriched_at IS NULL'
        )->fetchColumn();
        Response::json(['processed' => $processed, 'with_art' => $withArt, 'remaining' => $remaining]);
    }

    private static function importSharedCatalog(PDO $superDb): never
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
            $batch[] = array_combine($header, $row);
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

    private static function exportSharedCatalog(PDO $superDb): never
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
    private static function listTenantsWithDomains(PDO $db): array
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
    private static function loadTenantWithDomains(PDO $db, int $tenantId): ?array
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

    private static function updateTenantRecord(PDO $db, int $tenantId): never
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
        Response::json(['tenant' => self::loadTenantWithDomains($db, $tenantId)]);
    }
}
