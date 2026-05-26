<?php

declare(strict_types=1);

namespace NextUp\Tests\Support;

use NextUp\Support\Security;
use PHPUnit\Framework\TestCase;

final class SecurityTest extends TestCase
{
    protected function setUp(): void
    {
        // Mock $_SESSION since we never call session_start in the test process.
        $_SESSION = [];
    }

    public function testCsrfTokenIsStableWithinSession(): void
    {
        $first = Security::csrfToken();
        $again = Security::csrfToken();
        self::assertSame($first, $again);
        self::assertNotSame('', $first);
        self::assertSame(64, strlen($first)); // 32 random bytes hex-encoded
    }

    public function testCsrfTokenChangesWhenSessionResets(): void
    {
        $first = Security::csrfToken();
        $_SESSION = [];
        $second = Security::csrfToken();
        self::assertNotSame($first, $second);
    }

    public function testCsrfTokenIsHex(): void
    {
        $token = Security::csrfToken();
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
    }
}
