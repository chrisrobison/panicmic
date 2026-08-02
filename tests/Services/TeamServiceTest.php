<?php

declare(strict_types=1);

namespace PanicMic\Tests\Services;

use PanicMic\Services\TeamService;
use PanicMic\Tests\Support\DatabaseTestCase;

final class TeamServiceTest extends DatabaseTestCase
{
    public function testCreateTeamMemberIssuesInviteToken(): void
    {
        $created = TeamService::create($this->tenantDb, [
            'email' => 'kj@test.local',
            'display_name' => 'Casey',
            'role' => 'kj',
        ]);

        self::assertGreaterThan(0, $created['user_id']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $created['token']);
        $member = TeamService::list($this->tenantDb)[0];
        self::assertSame('Casey', $member['display_name']);
        self::assertSame('kj', $member['role']);
    }

    public function testLastAdministratorCannotBeDeactivated(): void
    {
        $admin = TeamService::create($this->tenantDb, [
            'email' => 'owner@test.local',
            'display_name' => 'Owner',
            'role' => 'tenant_admin',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        TeamService::update($this->tenantDb, $admin['user_id'], [
            'display_name' => 'Owner',
            'role' => 'tenant_admin',
            'is_active' => false,
        ], 9999);
    }

    public function testAdministratorCanDeactivateKj(): void
    {
        $admin = TeamService::create($this->tenantDb, [
            'email' => 'owner@test.local',
            'display_name' => 'Owner',
            'role' => 'tenant_admin',
        ]);
        $kj = TeamService::create($this->tenantDb, [
            'email' => 'kj@test.local',
            'display_name' => 'KJ',
            'role' => 'kj',
        ]);

        self::assertTrue(TeamService::update($this->tenantDb, $kj['user_id'], [
            'display_name' => 'KJ',
            'role' => 'kj',
            'is_active' => false,
        ], $admin['user_id']));
        self::assertSame(0, (int)$this->tenantDb->query(
            "SELECT is_active FROM users WHERE id = {$kj['user_id']}"
        )->fetchColumn());
    }
}
