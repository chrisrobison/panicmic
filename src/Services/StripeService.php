<?php

declare(strict_types=1);

namespace PanicMic\Services;

use PanicMic\Support\Env;
use PDO;

/**
 * Stripe Checkout + webhook scaffold. Stays SDK-free (zero runtime
 * deps) by talking to the Stripe REST API over plain HTTP. Implements
 * the minimum surface PLAN.md Phase 7 calls for:
 *
 *   - createCheckoutSession(): build a hosted-checkout session for a
 *     given plan and return the redirect URL.
 *   - handleWebhook(): verify the Stripe-Signature header and dispatch
 *     a small set of events to BillingService.
 *
 * Stripe keys live in .env:
 *   STRIPE_SECRET_KEY      = sk_live_… or sk_test_…
 *   STRIPE_WEBHOOK_SECRET  = whsec_…
 *   STRIPE_PRICE_STARTER   = price_…  (mapped via plans.code)
 *   STRIPE_PRICE_PRO       = price_…
 *
 * Until keys are present the methods throw a configuration error —
 * controllers should surface that as a 503 so super-admin can still
 * use the read-only billing screen without billing being live.
 */
final class StripeService
{
    private const API_BASE = 'https://api.stripe.com/v1';
    /** Tolerate up to 5 minutes of clock skew on the webhook signature. */
    private const SIGNATURE_TOLERANCE_SECONDS = 300;

    public static function isConfigured(): bool
    {
        $key = (string)(Env::get('STRIPE_SECRET_KEY', '') ?? '');
        return $key !== '';
    }

    /**
     * Create a Checkout session for a given tenant + plan and return
     * the URL to redirect the operator to. Stores the resulting
     * stripe_customer_id back on the tenant row when Stripe assigns one.
     *
     * @param array<string,mixed> $tenant Row from super.tenants — must
     *                                    include id, slug, primary_domain
     *                                    (or fall back to host header).
     * @param string $planCode One of the keys in plans.code.
     * @param string $host     Host header for building success/cancel URLs.
     */
    public static function createCheckoutSession(PDO $superDb, array $tenant, string $planCode, string $host): string
    {
        if (!self::isConfigured()) {
            throw new \RuntimeException('Stripe is not configured (STRIPE_SECRET_KEY missing)');
        }
        $price = self::priceIdFor($planCode);
        if ($price === null) {
            throw new \InvalidArgumentException("No Stripe price ID mapped for plan '{$planCode}'");
        }

        $scheme = (!empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
        $base = "{$scheme}://{$host}";

        $params = [
            'mode' => 'subscription',
            'success_url' => $base . '/admin/billing?status=success&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url'  => $base . '/admin/billing?status=cancelled',
            'client_reference_id' => (string)$tenant['id'],
            'line_items[0][price]' => $price,
            'line_items[0][quantity]' => 1,
            'subscription_data[metadata][tenant_id]' => (string)$tenant['id'],
            'metadata[tenant_id]' => (string)$tenant['id'],
            'metadata[plan_code]' => $planCode,
        ];
        $existingCustomer = (string)($tenant['stripe_customer_id'] ?? '');
        if ($existingCustomer !== '') {
            $params['customer'] = $existingCustomer;
        }

        $response = self::stripeRequest('POST', '/checkout/sessions', $params);
        if (!isset($response['url'])) {
            throw new \RuntimeException('Stripe did not return a checkout URL: ' . json_encode($response));
        }
        if (!empty($response['customer']) && $existingCustomer === '') {
            $superDb->prepare('UPDATE tenants SET stripe_customer_id = ? WHERE id = ?')
                ->execute([(string)$response['customer'], (int)$tenant['id']]);
        }
        return (string)$response['url'];
    }

    /**
     * Verify the Stripe-Signature header and dispatch the event. The
     * raw POST body is required (signed bytes must match exactly), so
     * controllers should pass file_get_contents('php://input') in.
     *
     * @param string  $rawBody       Untouched request body.
     * @param string  $signatureHdr  Value of HTTP_STRIPE_SIGNATURE.
     * @return array{event_type:string, handled:bool}
     */
    public static function handleWebhook(PDO $superDb, string $rawBody, string $signatureHdr): array
    {
        $secret = (string)(Env::get('STRIPE_WEBHOOK_SECRET', '') ?? '');
        if ($secret === '') {
            throw new \RuntimeException('STRIPE_WEBHOOK_SECRET is not set');
        }
        if (!self::verifySignature($rawBody, $signatureHdr, $secret)) {
            throw new \RuntimeException('Invalid webhook signature');
        }
        $event = json_decode($rawBody, true);
        if (!is_array($event) || !isset($event['type'])) {
            throw new \RuntimeException('Malformed webhook payload');
        }
        $type = (string)$event['type'];
        $object = $event['data']['object'] ?? [];

        switch ($type) {
            case 'checkout.session.completed':
                self::onCheckoutCompleted($superDb, is_array($object) ? $object : []);
                return ['event_type' => $type, 'handled' => true];

            case 'customer.subscription.updated':
            case 'customer.subscription.created':
                self::onSubscriptionChanged($superDb, is_array($object) ? $object : []);
                return ['event_type' => $type, 'handled' => true];

            case 'customer.subscription.deleted':
                self::onSubscriptionDeleted($superDb, is_array($object) ? $object : []);
                return ['event_type' => $type, 'handled' => true];

            case 'invoice.payment_failed':
                self::onPaymentFailed($superDb, is_array($object) ? $object : []);
                return ['event_type' => $type, 'handled' => true];

            default:
                // Acknowledge but ignore — Stripe retries unhandled events
                // by default, and we'd rather absorb the noise than fail.
                return ['event_type' => $type, 'handled' => false];
        }
    }

    private static function priceIdFor(string $planCode): ?string
    {
        $envKey = 'STRIPE_PRICE_' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '_', $planCode) ?? '');
        $id = (string)(Env::get($envKey, '') ?? '');
        return $id !== '' ? $id : null;
    }

    /** @param array<string,scalar|string> $params */
    private static function stripeRequest(string $method, string $endpoint, array $params): array
    {
        $key = (string)(Env::get('STRIPE_SECRET_KEY', '') ?? '');
        $url = self::API_BASE . $endpoint;
        $body = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => "Authorization: Bearer {$key}\r\n"
                          . "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $body,
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);
        $resp = @file_get_contents($url, false, $context);
        if ($resp === false) {
            throw new \RuntimeException("Stripe request failed: {$method} {$endpoint}");
        }
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode($resp, true) ?? [];
        if (isset($decoded['error'])) {
            $msg = is_array($decoded['error']) ? ($decoded['error']['message'] ?? 'Stripe error') : 'Stripe error';
            throw new \RuntimeException("Stripe error: {$msg}");
        }
        return $decoded;
    }

    /**
     * Verify the Stripe-Signature header using HMAC-SHA256.
     * Header shape: "t=<unix>,v1=<sig>[,v1=<sig>...]"
     */
    private static function verifySignature(string $body, string $headerValue, string $secret): bool
    {
        if ($headerValue === '') {
            return false;
        }
        $parts = explode(',', $headerValue);
        $timestamp = null;
        $signatures = [];
        foreach ($parts as $part) {
            [$k, $v] = array_pad(explode('=', $part, 2), 2, '');
            if ($k === 't') {
                $timestamp = (int)$v;
            } elseif ($k === 'v1') {
                $signatures[] = $v;
            }
        }
        if ($timestamp === null || $signatures === []) {
            return false;
        }
        // Replay protection.
        if (abs(time() - $timestamp) > self::SIGNATURE_TOLERANCE_SECONDS) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
        foreach ($signatures as $sig) {
            if (hash_equals($expected, $sig)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $session */
    private static function onCheckoutCompleted(PDO $superDb, array $session): void
    {
        $tenantId = (int)($session['client_reference_id'] ?? ($session['metadata']['tenant_id'] ?? 0));
        if ($tenantId <= 0) {
            return;
        }
        $customer = (string)($session['customer'] ?? '');
        $subscription = (string)($session['subscription'] ?? '');
        $planCode = (string)($session['metadata']['plan_code'] ?? '');

        $superDb->prepare(
            'UPDATE tenants
             SET stripe_customer_id = COALESCE(NULLIF(?, ""), stripe_customer_id),
                 stripe_subscription_id = COALESCE(NULLIF(?, ""), stripe_subscription_id),
                 plan_code = COALESCE(NULLIF(?, ""), plan_code),
                 subscription_status = "active"
             WHERE id = ?'
        )->execute([$customer, $subscription, $planCode, $tenantId]);
    }

    /** @param array<string,mixed> $subscription */
    private static function onSubscriptionChanged(PDO $superDb, array $subscription): void
    {
        $subId = (string)($subscription['id'] ?? '');
        $status = (string)($subscription['status'] ?? '');
        if ($subId === '' || $status === '') {
            return;
        }
        $map = [
            'active'   => 'active',
            'trialing' => 'trialing',
            'past_due' => 'past_due',
            'unpaid'   => 'past_due',
            'canceled' => 'canceled',
            'incomplete'         => 'past_due',
            'incomplete_expired' => 'canceled',
        ];
        $local = $map[$status] ?? 'past_due';
        $superDb->prepare(
            'UPDATE tenants SET subscription_status = ? WHERE stripe_subscription_id = ?'
        )->execute([$local, $subId]);
    }

    /** @param array<string,mixed> $subscription */
    private static function onSubscriptionDeleted(PDO $superDb, array $subscription): void
    {
        $subId = (string)($subscription['id'] ?? '');
        if ($subId === '') {
            return;
        }
        $superDb->prepare(
            'UPDATE tenants SET subscription_status = "canceled" WHERE stripe_subscription_id = ?'
        )->execute([$subId]);
    }

    /** @param array<string,mixed> $invoice */
    private static function onPaymentFailed(PDO $superDb, array $invoice): void
    {
        $subId = (string)($invoice['subscription'] ?? '');
        if ($subId === '') {
            return;
        }
        $superDb->prepare(
            'UPDATE tenants SET subscription_status = "past_due" WHERE stripe_subscription_id = ?'
        )->execute([$subId]);
    }
}
