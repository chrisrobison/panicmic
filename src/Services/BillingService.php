<?php

declare(strict_types=1);

namespace PanicMic\Services;

use PDO;

/**
 * Reads + mutates tenant subscription state. Stripe integration is
 * scaffolded but not live; until Stripe keys are configured, super-admin
 * can flip subscription_status manually via the admin UI.
 */
final class BillingService
{
    public const STATUSES = ['trialing', 'active', 'past_due', 'canceled'];

    /** @return list<array<string,mixed>> */
    public static function plans(PDO $superDb): array
    {
        return $superDb->query(
            'SELECT id, code, name, monthly_cents, features
             FROM plans
             WHERE is_active = 1
             ORDER BY monthly_cents ASC'
        )->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public static function subscription(PDO $superDb, int $tenantId): ?array
    {
        $stmt = $superDb->prepare(
            'SELECT t.plan_code, t.subscription_status, t.trial_ends_at,
                    t.stripe_customer_id, t.stripe_subscription_id,
                    p.name AS plan_name, p.monthly_cents, p.features
             FROM tenants t
             LEFT JOIN plans p ON p.code = t.plan_code
             WHERE t.id = ?
             LIMIT 1'
        );
        $stmt->execute([$tenantId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function setStatus(PDO $superDb, int $tenantId, string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Invalid subscription status');
        }
        $superDb->prepare('UPDATE tenants SET subscription_status = ? WHERE id = ?')
            ->execute([$status, $tenantId]);
    }

    public static function setPlan(PDO $superDb, int $tenantId, string $planCode): void
    {
        $superDb->prepare('UPDATE tenants SET plan_code = ? WHERE id = ?')
            ->execute([$planCode, $tenantId]);
    }

    /**
     * Decode the features JSON for a plan code. Returns sensible defaults
     * matching the Standard plan when the plan or a key is missing so
     * callers always get usable numbers.
     *
     * @return array{max_venues:int,included_kj:int,additional_kj_cents:int,monthly_cents:int,plan_name:string}
     */
    public static function planLimits(PDO $superDb, string $planCode): array
    {
        $stmt = $superDb->prepare('SELECT name, monthly_cents, features FROM plans WHERE code = ? LIMIT 1');
        $stmt->execute([$planCode]);
        $row = $stmt->fetch() ?: [];
        $features = [];
        if (!empty($row['features'])) {
            $decoded = json_decode((string)$row['features'], true);
            $features = is_array($decoded) ? $decoded : [];
        }
        return [
            'plan_name' => (string)($row['name'] ?? 'Standard'),
            'monthly_cents' => (int)($row['monthly_cents'] ?? 900),
            'max_venues' => (int)($features['max_venues'] ?? 5),
            'included_kj' => (int)($features['included_kj'] ?? 1),
            'additional_kj_cents' => (int)($features['additional_kj_cents'] ?? 250),
        ];
    }

    /** Active venue cap for the tenant's plan (0 = unlimited). */
    public static function venueCap(PDO $superDb, array $tenant): int
    {
        return self::planLimits($superDb, (string)($tenant['plan_code'] ?? 'standard'))['max_venues'];
    }

    /**
     * Count operator seats (users who can run shows). The base plan
     * includes one; the rest are billed per seat.
     */
    public static function operatorSeatCount(PDO $tenantDb): int
    {
        return (int)$tenantDb->query(
            "SELECT COUNT(*) FROM users WHERE is_active = 1 AND role IN ('kj','tenant_admin')"
        )->fetchColumn();
    }

    /**
     * Read-only billing snapshot for the admin settings panel: plan,
     * venue usage, KJ seats and the projected monthly total. Actual Stripe
     * metering of seats is wired separately.
     *
     * @param array<string,mixed> $tenant
     * @return array<string,mixed>
     */
    public static function summary(PDO $tenantDb, PDO $superDb, array $tenant): array
    {
        $limits = self::planLimits($superDb, (string)($tenant['plan_code'] ?? 'standard'));
        $venuesUsed = VenueService::countActive($tenantDb);
        $seats = self::operatorSeatCount($tenantDb);
        $extraSeats = max(0, $seats - $limits['included_kj']);
        $projectedCents = $limits['monthly_cents'] + ($extraSeats * $limits['additional_kj_cents']);

        return [
            'plan_code' => (string)($tenant['plan_code'] ?? 'standard'),
            'plan_name' => $limits['plan_name'],
            'subscription_status' => $tenant['subscription_status'] ?? 'active',
            'base_monthly_cents' => $limits['monthly_cents'],
            'venues_used' => $venuesUsed,
            'max_venues' => $limits['max_venues'],
            'kj_seats' => $seats,
            'included_kj' => $limits['included_kj'],
            'additional_kj' => $extraSeats,
            'additional_kj_cents' => $limits['additional_kj_cents'],
            'projected_monthly_cents' => $projectedCents,
        ];
    }

    /**
     * Returns true when the tenant currently has access to paid
     * features. `trialing` counts as access as long as trial_ends_at
     * is in the future (or null, which is also treated as active).
     *
     * @param array<string,mixed> $tenant
     */
    public static function hasAccess(array $tenant): bool
    {
        $status = (string)($tenant['subscription_status'] ?? 'active');
        if ($status === 'active') {
            return true;
        }
        if ($status === 'trialing') {
            $endsAt = $tenant['trial_ends_at'] ?? null;
            if ($endsAt === null || $endsAt === '') {
                return true;
            }
            return strtotime((string)$endsAt) > time();
        }
        return false;
    }
}
