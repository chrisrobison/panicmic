<?php

declare(strict_types=1);

namespace PanicMic\Tests\Services;

use PanicMic\Services\VenueService;
use PanicMic\Tests\Support\DatabaseTestCase;

final class VenueServiceTest extends DatabaseTestCase
{
    public function testCreateRejectsBlankName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        VenueService::create($this->tenantDb, ['name' => '  '], 5);
    }

    public function testEnforcesVenueCap(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            VenueService::create($this->tenantDb, ['name' => "Venue {$i}"], 5);
        }
        self::assertSame(5, VenueService::countActive($this->tenantDb));

        $this->expectException(\InvalidArgumentException::class);
        VenueService::create($this->tenantDb, ['name' => 'Venue 6'], 5);
    }

    public function testArchiveFreesASlot(): void
    {
        $first = VenueService::create($this->tenantDb, ['name' => 'Alpha'], 2);
        VenueService::create($this->tenantDb, ['name' => 'Beta'], 2);
        self::assertSame(2, VenueService::countActive($this->tenantDb));

        VenueService::archive($this->tenantDb, (int)$first['id']);
        self::assertSame(1, VenueService::countActive($this->tenantDb));

        // A slot is free again, so a new venue is accepted.
        $gamma = VenueService::create($this->tenantDb, ['name' => 'Gamma'], 2);
        self::assertArrayHasKey('id', $gamma);
    }

    public function testGeneratesUniqueSlugs(): void
    {
        $a = VenueService::create($this->tenantDb, ['name' => 'The Spot'], 0);
        $b = VenueService::create($this->tenantDb, ['name' => 'The Spot'], 0);
        self::assertSame('the-spot', $a['slug']);
        self::assertSame('the-spot-2', $b['slug']);
    }
}
