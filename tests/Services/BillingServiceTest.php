<?php

declare(strict_types=1);

namespace PanicMic\Tests\Services;

use PanicMic\Services\BillingService;
use PanicMic\Tests\Support\DatabaseTestCase;

final class BillingServiceTest extends DatabaseTestCase
{
    public function testPlansSeeded(): void
    {
        $plans = BillingService::plans($this->superDb);
        $codes = array_column($plans, 'code');
        self::assertContains('trial', $codes);
        self::assertContains('starter', $codes);
        self::assertContains('pro', $codes);
    }

    public function testHasAccessActive(): void
    {
        self::assertTrue(BillingService::hasAccess(['subscription_status' => 'active']));
    }

    public function testHasAccessTrialing(): void
    {
        self::assertTrue(BillingService::hasAccess([
            'subscription_status' => 'trialing',
            'trial_ends_at' => date('Y-m-d H:i:s', time() + 86400),
        ]));
    }

    public function testHasNoAccessExpiredTrial(): void
    {
        self::assertFalse(BillingService::hasAccess([
            'subscription_status' => 'trialing',
            'trial_ends_at' => date('Y-m-d H:i:s', time() - 86400),
        ]));
    }

    public function testHasNoAccessPastDue(): void
    {
        self::assertFalse(BillingService::hasAccess(['subscription_status' => 'past_due']));
    }

    public function testSetStatusValidates(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BillingService::setStatus($this->superDb, $this->tenantId, 'bogus');
    }

    public function testSubscriptionReturnsTenantState(): void
    {
        BillingService::setPlan($this->superDb, $this->tenantId, 'starter');
        BillingService::setStatus($this->superDb, $this->tenantId, 'active');
        $sub = BillingService::subscription($this->superDb, $this->tenantId);
        self::assertSame('starter', $sub['plan_code']);
        self::assertSame('active', $sub['subscription_status']);
        self::assertSame('Starter', $sub['plan_name']);
    }
}
