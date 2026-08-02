<?php

declare(strict_types=1);

namespace PanicMic\Tests\Services;

use PanicMic\Services\PasswordResetService;
use PanicMic\Tests\Support\DatabaseTestCase;

final class PasswordResetServiceTest extends DatabaseTestCase
{
    private function user(): int
    {
        $this->tenantDb->prepare(
            "INSERT INTO users (email, password_hash, display_name, role, is_active)
             VALUES ('owner@test.local', ?, 'Owner', 'tenant_admin', 1)"
        )->execute([password_hash('old-password-value', PASSWORD_DEFAULT)]);
        return (int)$this->tenantDb->lastInsertId();
    }

    public function testTokenIsHashedAndCanOnlyBeUsedOnce(): void
    {
        $userId = $this->user();
        $token = PasswordResetService::issue($this->tenantDb, $userId);
        $stored = (string)$this->tenantDb->query(
            'SELECT token_hash FROM password_reset_tokens LIMIT 1'
        )->fetchColumn();

        self::assertNotSame($token, $stored);
        self::assertSame(hash('sha256', $token), $stored);
        self::assertTrue(PasswordResetService::reset($this->tenantDb, $token, 'a-new-secure-password'));
        self::assertFalse(PasswordResetService::reset($this->tenantDb, $token, 'another-secure-password'));

        $hash = (string)$this->tenantDb->query(
            "SELECT password_hash FROM users WHERE id = {$userId}"
        )->fetchColumn();
        self::assertTrue(password_verify('a-new-secure-password', $hash));
    }

    public function testIssuingNewTokenInvalidatesPreviousToken(): void
    {
        $userId = $this->user();
        $old = PasswordResetService::issue($this->tenantDb, $userId);
        $new = PasswordResetService::issue($this->tenantDb, $userId);

        self::assertFalse(PasswordResetService::reset($this->tenantDb, $old, 'a-new-secure-password'));
        self::assertTrue(PasswordResetService::reset($this->tenantDb, $new, 'a-new-secure-password'));
    }

    public function testWeakPasswordIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PasswordResetService::validatePassword('too-short');
    }
}
