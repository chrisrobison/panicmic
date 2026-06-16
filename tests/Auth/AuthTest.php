<?php

declare(strict_types=1);

namespace PanicMic\Tests\Auth;

use PanicMic\Auth\Auth;
use PanicMic\Support\Security;
use PanicMic\Tests\Support\DatabaseTestCase;

final class AuthTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Auth::resetActiveMemo();
        Security::setSessionRotator(null);
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        Security::setSessionRotator(null);
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

    public function testAttemptSuperForTenantSucceedsWithSuperCredentials(): void
    {
        $hash = password_hash('superpw', PASSWORD_DEFAULT);
        $this->superDb
            ->prepare('INSERT INTO super_admin_users (email, password_hash, display_name) VALUES (?, ?, ?)')
            ->execute(['boss@x', $hash, 'Boss']);

        $actor = Auth::attemptSuperForTenant($this->superDb, 'boss@x', 'superpw');
        self::assertNotNull($actor);
        self::assertSame('super_admin', $actor['role']);
        self::assertArrayHasKey('super_admin', $_SESSION);
        self::assertTrue(Auth::actingAsSuper());
    }

    public function testAttemptSuperForTenantRejectsWrongPassword(): void
    {
        $hash = password_hash('superpw', PASSWORD_DEFAULT);
        $this->superDb
            ->prepare('INSERT INTO super_admin_users (email, password_hash, display_name) VALUES (?, ?, ?)')
            ->execute(['boss2@x', $hash, 'Boss']);

        self::assertNull(Auth::attemptSuperForTenant($this->superDb, 'boss2@x', 'wrong'));
        self::assertArrayNotHasKey('super_admin', $_SESSION);
    }

    /* ------- session rotation on login: fixation defense ------- */

    public function testAttemptTenantRotatesSessionIdBeforeElevating(): void
    {
        $hash = password_hash('right', PASSWORD_DEFAULT);
        $this->tenantDb
            ->prepare("INSERT INTO users (email, password_hash, display_name, role, is_active) VALUES (?, ?, ?, 'kj', 1)")
            ->execute(['rot@x', $hash, 'Rot']);

        $rotated = false;
        Security::setSessionRotator(function () use (&$rotated): void {
            $rotated = true;
            // The id must rotate BEFORE the session is elevated, so an
            // attacker's planted pre-auth id never becomes an authed one.
            self::assertArrayNotHasKey('tenant_user', $_SESSION);
        });

        $user = Auth::attemptTenant($this->tenantDb, 'rot@x', 'right');
        self::assertNotNull($user);
        self::assertTrue($rotated, 'successful tenant login must rotate the session id');
        self::assertArrayHasKey('tenant_user', $_SESSION);
    }

    public function testFailedTenantLoginDoesNotRotateSession(): void
    {
        $hash = password_hash('right', PASSWORD_DEFAULT);
        $this->tenantDb
            ->prepare("INSERT INTO users (email, password_hash, display_name, role, is_active) VALUES (?, ?, ?, 'kj', 1)")
            ->execute(['rot2@x', $hash, 'Rot']);

        $rotated = false;
        Security::setSessionRotator(function () use (&$rotated): void {
            $rotated = true;
        });

        self::assertNull(Auth::attemptTenant($this->tenantDb, 'rot2@x', 'wrong'));
        self::assertFalse($rotated, 'a failed login must not rotate the session id');
    }

    public function testAttemptSuperForTenantRotatesSession(): void
    {
        $hash = password_hash('superpw', PASSWORD_DEFAULT);
        $this->superDb
            ->prepare('INSERT INTO super_admin_users (email, password_hash, display_name) VALUES (?, ?, ?)')
            ->execute(['rotboss@x', $hash, 'Boss']);

        $rotated = false;
        Security::setSessionRotator(function () use (&$rotated): void {
            $rotated = true;
            self::assertArrayNotHasKey('super_admin', $_SESSION);
        });

        $actor = Auth::attemptSuperForTenant($this->superDb, 'rotboss@x', 'superpw');
        self::assertNotNull($actor);
        self::assertTrue($rotated, 'successful super login must rotate the session id');
    }

    /* ------- userHasRole: predicate behind requireTenantRole ------- */

    public function testUserHasRoleRejectsNullUser(): void
    {
        self::assertFalse(Auth::userHasRole(null, ['kj']));
    }

    public function testUserHasRoleRejectsNonArray(): void
    {
        self::assertFalse(Auth::userHasRole('not-an-array', ['kj']));
        self::assertFalse(Auth::userHasRole(42, ['kj']));
    }

    public function testUserHasRoleAcceptsMatchingRole(): void
    {
        $user = ['id' => 1, 'role' => 'kj'];
        self::assertTrue(Auth::userHasRole($user, ['kj', 'tenant_admin']));
    }

    public function testUserHasRoleRejectsWrongRole(): void
    {
        $user = ['id' => 1, 'role' => 'guest'];
        self::assertFalse(Auth::userHasRole($user, ['kj', 'tenant_admin']));
    }

    public function testUserHasRoleRejectsMissingRoleKey(): void
    {
        $user = ['id' => 1]; // no 'role' set
        self::assertFalse(Auth::userHasRole($user, ['kj']));
    }
}
