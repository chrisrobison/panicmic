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

    /* ------- csrfTokenMatches: verify side of the round-trip ------- */

    public function testCsrfTokenMatchesAcceptsRoundTrip(): void
    {
        $token = Security::csrfToken();
        self::assertTrue(Security::csrfTokenMatches($token));
    }

    public function testCsrfTokenMatchesRejectsWrongToken(): void
    {
        Security::csrfToken(); // populate session
        self::assertFalse(Security::csrfTokenMatches('deadbeef'));
        self::assertFalse(Security::csrfTokenMatches(''));
    }

    public function testCsrfTokenMatchesRejectsWhenSessionUnseeded(): void
    {
        // No prior csrfToken() call — session has nothing to compare against.
        self::assertFalse(Security::csrfTokenMatches('anything'));
    }

    public function testCsrfTokenMatchesIsConstantTime(): void
    {
        $token = Security::csrfToken();
        // hash_equals returns false for length-mismatched strings, but
        // shouldn't short-circuit on early bytes. We can't directly
        // assert timing here, but we can assert false for similar-prefix.
        self::assertFalse(Security::csrfTokenMatches(substr($token, 0, -1) . 'x'));
    }
}
