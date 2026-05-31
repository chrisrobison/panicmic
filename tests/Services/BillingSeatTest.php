<?php

declare(strict_types=1);

namespace PanicMic\Tests\Services;

use PanicMic\Services\BillingService;
use PanicMic\Services\VenueService;
use PanicMic\Tests\Support\DatabaseTestCase;

final class BillingSeatTest extends DatabaseTestCase
{
    private function addOperator(string $email, string $role): void
    {
        $this->tenantDb
            ->prepare('INSERT INTO users (email, password_hash, display_name, role, is_active) VALUES (?, ?, ?, ?, 1)')
            ->execute([$email, 'x', $email, $role]);
    }

    /** @return array<string,mixed> */
    private function standardTenant(): array
    {
        return ['plan_code' => 'standard', 'subscription_status' => 'active'];
    }

    public function testSingleKjPaysBaseRate(): void
    {
        $this->addOperator('kj1@test.local', 'kj');
        $summary = BillingService::summary($this->tenantDb, $this->superDb, $this->standardTenant());

        self::assertSame(900, $summary['base_monthly_cents']);
        self::assertSame(1, $summary['kj_seats']);
        self::assertSame(0, $summary['additional_kj']);
        self::assertSame(900, $summary['projected_monthly_cents']);
        self::assertSame(5, $summary['max_venues']);
    }

    public function testAdditionalKjsAddPerSeatFee(): void
    {
        $this->addOperator('kj1@test.local', 'kj');
        $this->addOperator('kj2@test.local', 'kj');
        $this->addOperator('owner@test.local', 'tenant_admin');

        $summary = BillingService::summary($this->tenantDb, $this->superDb, $this->standardTenant());

        self::assertSame(3, $summary['kj_seats']);
        self::assertSame(2, $summary['additional_kj']);
        // $9 base + 2 × $2.50 = $14.00.
        self::assertSame(1400, $summary['projected_monthly_cents']);
    }

    public function testVenueUsageReflectsActiveVenues(): void
    {
        VenueService::create($this->tenantDb, ['name' => 'A'], 5);
        VenueService::create($this->tenantDb, ['name' => 'B'], 5);
        $summary = BillingService::summary($this->tenantDb, $this->superDb, $this->standardTenant());
        self::assertSame(2, $summary['venues_used']);
    }
}
