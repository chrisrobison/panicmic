<?php

declare(strict_types=1);

namespace PanicMic\Http;

use PanicMic\Auth\Auth;
use PanicMic\Database\Connection;
use PanicMic\Services\BillingService;
use PanicMic\Services\StripeService;
use PanicMic\Support\Request;
use PanicMic\Support\Response;
use PDO;

/**
 * Self-serve billing endpoints. PLAN.md Phase 7 (Stripe Checkout + webhooks).
 */
final class BillingController
{
    /**
     * POST /api/billing/checkout — tenant_admin only. Returns
     * { url: "https://checkout.stripe.com/..." } to redirect to.
     *
     * @param array<string,mixed> $tenant
     */
    public static function checkout(PDO $db, array $tenant): never
    {
        Auth::requireTenantRole('tenant_admin');
        if (!StripeService::isConfigured()) {
            Response::json(['error' => 'Billing is not yet enabled on this deployment.'], 503);
        }
        $input = Request::input();
        $planCode = (string)($input['plan'] ?? '');
        if ($planCode === '') {
            Response::json(['error' => 'plan is required'], 400);
        }
        try {
            $super = Connection::super();
            // Refresh tenant row from super DB so we see the latest
            // stripe_customer_id; the request-scoped $tenant may be stale.
            $stmt = $super->prepare('SELECT id, slug, stripe_customer_id FROM tenants WHERE id = ?');
            $stmt->execute([(int)$tenant['id']]);
            $row = $stmt->fetch() ?: [];
            $row = array_merge($tenant, $row);

            $host = (string)($_SERVER['HTTP_HOST'] ?? '');
            $url = StripeService::createCheckoutSession($super, $row, $planCode, $host);
            Response::json(['url' => $url]);
        } catch (\Throwable $e) {
            \PanicMic\Support\ErrorReporter::report($e, 'stripe checkout');
            Response::json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /webhooks/stripe — public, signature-verified. Stripe sends
     * subscription lifecycle events here. Returns 200 on success so
     * Stripe stops retrying.
     */
    public static function webhook(): never
    {
        $rawBody = (string)file_get_contents('php://input');
        $signature = (string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');
        try {
            $result = StripeService::handleWebhook(Connection::super(), $rawBody, $signature);
            Response::json(['ok' => true, 'event' => $result['event_type'], 'handled' => $result['handled']]);
        } catch (\Throwable $e) {
            // 400 on bad signature so Stripe surfaces it in their dashboard;
            // 5xx triggers retries (good for transient DB failures).
            $msg = $e->getMessage();
            $code = str_contains($msg, 'signature') || str_contains($msg, 'Malformed') ? 400 : 500;
            \PanicMic\Support\ErrorReporter::report($e, 'stripe webhook');
            Response::json(['error' => $msg], $code);
        }
    }

    /**
     * GET /api/billing/plans — public to authenticated tenant_admin.
     * Convenience read for the billing UI.
     */
    public static function plans(): never
    {
        Auth::requireTenantRole('tenant_admin');
        Response::json(['plans' => BillingService::plans(Connection::super())]);
    }

    /**
     * GET /api/admin/billing — read-only plan/usage snapshot for the
     * settings panel: plan, venue usage, KJ seats, projected total.
     *
     * @param array<string,mixed> $tenant
     */
    public static function summary(PDO $db, array $tenant): never
    {
        Auth::requireTenantRole('kj', 'tenant_admin');
        Response::json(['billing' => BillingService::summary($db, Connection::super(), $tenant)]);
    }
}
