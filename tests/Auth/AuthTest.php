<?php

declare(strict_types=1);

namespace NextUp\Tests\Auth;

use NextUp\Auth\Auth;
use NextUp\Tests\Support\DatabaseTestCase;

final class AuthTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Auth::resetActiveMemo();
        $_SESSION = [];
    }

    public function testEnsureSessionUserActiveNoOpsWhenNoSession(): void
    {
        Auth::ensureSessionUserActive($this->tenantDb);
        $this->addToAssertionCount(1);
    }

    public function testEnsureSessionUserActivePassesForActiveUser(): void
    {
        $hash = password_hash('x', PASSWORD_DEFAULT);
        $this->tenantDb
            ->prepare("INSERT INTO users (email, password_hash, display_name, role, is_active) VALUES (?, ?, ?, 'kj', 1)")
            ->execute(['active@x', $hash, 'Active']);
        $id = (int)$this->tenantDb->lastInsertId();
        $_SESSION['tenant_user'] = ['id' => $id, 'email' => 'active@x', 'role' => 'kj', 'display_name' => 'Active'];

        Auth::ensureSessionUserActive($this->tenantDb);
        self::assertArrayHasKey('tenant_user', $_SESSION);
    }

    public function testAttemptTenantRejectsDeactivatedUser(): void
    {
        $hash = password_hash('right', PASSWORD_DEFAULT);
        $this->tenantDb
            ->prepare("INSERT INTO users (email, password_hash, display_name, role, is_active) VALUES (?, ?, ?, 'kj', 0)")
            ->execute(['dead@x', $hash, 'Dead']);

        self::assertNull(Auth::attemptTenant($this->tenantDb, 'dead@x', 'right'));
    }

    public function testAttemptTenantRejectsWrongPassword(): void
    {
        $hash = password_hash('right', PASSWORD_DEFAULT);
        $this->tenantDb
            ->prepare("INSERT INTO users (email, password_hash, display_name, role, is_active) VALUES (?, ?, ?, 'kj', 1)")
            ->execute(['alive@x', $hash, 'Alive']);

        self::assertNull(Auth::attemptTenant($this->tenantDb, 'alive@x', 'wrong'));
    }

    public function testAttemptTenantSetsSessionOnSuccess(): void
    {
        $hash = password_hash('right', PASSWORD_DEFAULT);
        $this->tenantDb
            ->prepare("INSERT INTO users (email, password_hash, display_name, role, is_active) VALUES (?, ?, ?, 'tenant_admin', 1)")
            ->execute(['ok@x', $hash, 'Admin']);

        $user = Auth::attemptTenant($this->tenantDb, 'ok@x', 'right');
        self::assertNotNull($user);
        self::assertSame('ok@x', $user['email']);
        self::assertSame('tenant_admin', $user['role']);
        self::assertArrayHasKey('tenant_user', $_SESSION);
    }
}
