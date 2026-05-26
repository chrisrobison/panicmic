<?php

declare(strict_types=1);

namespace NextUp\Services;

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
