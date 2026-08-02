<?php

declare(strict_types=1);

namespace PanicMic\Http;

use PanicMic\Auth\Auth;
use PanicMic\Support\Env;
use PanicMic\Support\Response;
use PanicMic\Support\WsManager;
use PDO;
use Throwable;

/**
 * Operational health for monitoring and the admin UI.
 *
 * Written after a missing database column silently disabled every realtime
 * write in the app for an extended period. The only evidence was a log file
 * nobody was tailing. Nothing surfaced the fault to an operator, and the UI
 * cheerfully reported "Connecting…" the whole time.
 *
 * Public callers get liveness only. Signed-in KJs/admins get the detail —
 * schema drift and daemon state are useful to them and to a monitor with
 * credentials, but they are not for anonymous visitors.
 */
final class HealthController
{
    /**
     * GET /api/health
     *
     * Always 200 for a reachable app; the payload carries the verdict so a
     * monitor can alert on `status` without treating a degraded-but-serving
     * app as unreachable. `degraded` means "serving requests, but something
     * an operator must fix".
     */
    public static function index(PDO $db): never
    {
        $detailed = Auth::currentTenantActor() !== null || Auth::actingAsSuper();

        $checks = [
            'database' => self::checkDatabase($db),
            'schema'   => self::checkSchema($db),
            'realtime' => self::checkRealtime(),
        ];

        $status = 'ok';
        foreach ($checks as $check) {
            if ($check['status'] === 'fail') {
                $status = 'degraded';
                break;
            }
        }

        if (!$detailed) {
            Response::json(['status' => $status]);
        }

        Response::json([
            'status' => $status,
            'checks' => $checks,
            'time'   => date(DATE_ATOM),
        ]);
    }

    /** @return array{status:string,detail:string} */
    private static function checkDatabase(PDO $db): array
    {
        try {
            $db->query('SELECT 1')->fetchColumn();
            return ['status' => 'ok', 'detail' => 'reachable'];
        } catch (Throwable $e) {
            return ['status' => 'fail', 'detail' => 'unreachable: ' . $e->getMessage()];
        }
    }

    /**
     * Schema drift for THIS tenant: migration files on disk that the
     * ledger has no record of. This is the exact failure that broke the
     * app, so it gets a first-class check rather than a log line.
     *
     * @return array{status:string,detail:string,pending?:list<string>}
     */
    private static function checkSchema(PDO $db): array
    {
        try {
            $files = glob(dirname(__DIR__, 2) . '/migrations/tenant/*.sql') ?: [];
            if ($files === []) {
                return ['status' => 'ok', 'detail' => 'no migration files found'];
            }

            $hasLedger = (bool)$db->query("SHOW TABLES LIKE 'schema_migrations'")->fetchColumn();
            if (!$hasLedger) {
                return ['status' => 'fail', 'detail' => 'schema_migrations ledger is missing'];
            }

            $applied = $db->query('SELECT filename FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
            $appliedSet = array_flip(array_map('strval', $applied));

            $pending = [];
            foreach ($files as $path) {
                $name = basename($path);
                if (!isset($appliedSet[$name])) {
                    $pending[] = $name;
                }
            }

            if ($pending !== []) {
                return [
                    'status'  => 'fail',
                    'detail'  => count($pending) . ' migration(s) pending — run `make migrate`',
                    'pending' => $pending,
                ];
            }
            return ['status' => 'ok', 'detail' => count($applied) . ' migration(s) applied'];
        } catch (Throwable $e) {
            return ['status' => 'fail', 'detail' => 'schema check failed: ' . $e->getMessage()];
        }
    }

    /** @return array{status:string,detail:string} */
    private static function checkRealtime(): array
    {
        $enabled = strtolower((string)(Env::get('WEBSOCKET_ENABLED', 'true') ?? 'true')) === 'true';
        if (!$enabled) {
            return ['status' => 'ok', 'detail' => 'websocket disabled; clients short-poll'];
        }
        try {
            return WsManager::isRunning()
                ? ['status' => 'ok', 'detail' => 'websocket daemon running']
                // Not a hard failure: ws.js falls back to short-polling, so
                // the app still works, just less promptly.
                : ['status' => 'warn', 'detail' => 'websocket daemon not running; clients fall back to short-poll'];
        } catch (Throwable $e) {
            return ['status' => 'warn', 'detail' => 'daemon state unknown: ' . $e->getMessage()];
        }
    }

    /**
     * Pending-migration names for the current tenant, for the admin banner.
     * Never throws — a broken check must not break the page it warns on.
     *
     * @return list<string>
     */
    public static function pendingMigrations(PDO $db): array
    {
        $result = self::checkSchema($db);
        return $result['pending'] ?? [];
    }
}
