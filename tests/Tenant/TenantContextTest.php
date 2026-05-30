<?php

declare(strict_types=1);

namespace PanicMic\Tests\Tenant;

use PanicMic\Support\Env;
use PanicMic\Tenant\TenantContext;
use PHPUnit\Framework\TestCase;

final class TenantContextTest extends TestCase
{
    protected function setUp(): void
    {
        $_SERVER['HTTP_HOST'] = '';
        $_SERVER['HTTP_X_FORWARDED_HOST'] = '';
        // Env::load doesn't reset between tests; force TRUST_PROXY off by
        // overriding via $_ENV and getenv (Env::get checks both first).
        $_ENV['TRUST_PROXY'] = 'false';
        putenv('TRUST_PROXY=false');
    }

    public function testStripsPortFromHost(): void
    {
        $_SERVER['HTTP_HOST'] = 'bluebird.panicmic.com:8000';
        self::assertSame('bluebird.panicmic.com', TenantContext::host());
    }

    public function testLowercasesAndTrims(): void
    {
        $_SERVER['HTTP_HOST'] = '  Bluebird.PanicMic.COM  ';
        self::assertSame('bluebird.panicmic.com', TenantContext::host());
    }

    public function testHandlesIPv6BracketedHost(): void
    {
        $_SERVER['HTTP_HOST'] = '[::1]:8000';
        self::assertSame('::1', TenantContext::host());
    }

    public function testIgnoresForwardedHeaderWhenTrustProxyOff(): void
    {
        $_SERVER['HTTP_HOST'] = 'direct.panicmic.com';
        $_SERVER['HTTP_X_FORWARDED_HOST'] = 'evil.panicmic.com';
        self::assertSame('direct.panicmic.com', TenantContext::host());
    }

    public function testTrustsForwardedHeaderWhenTrustProxyOn(): void
    {
        $_ENV['TRUST_PROXY'] = 'true';
        putenv('TRUST_PROXY=true');
        $_SERVER['HTTP_HOST'] = 'proxy-internal';
        $_SERVER['HTTP_X_FORWARDED_HOST'] = 'public.panicmic.com';
        self::assertSame('public.panicmic.com', TenantContext::host());
    }

    public function testHandlesCommaSeparatedForwardedList(): void
    {
        $_ENV['TRUST_PROXY'] = 'true';
        putenv('TRUST_PROXY=true');
        $_SERVER['HTTP_X_FORWARDED_HOST'] = 'first.panicmic.com, hop2.example.com';
        self::assertSame('first.panicmic.com', TenantContext::host());
    }

    public function testAllowsExactHostFromAllowList(): void
    {
        $_ENV['ALLOWED_HOSTS'] = 'app.test,bluebird.test';
        putenv('ALLOWED_HOSTS=app.test,bluebird.test');
        self::assertTrue(TenantContext::isAllowedHost('app.test'));
        self::assertTrue(TenantContext::isAllowedHost('bluebird.test'));
    }

    public function testAllowsWildcardSubdomain(): void
    {
        $_ENV['ALLOWED_HOSTS'] = '*.panicmic.com';
        putenv('ALLOWED_HOSTS=*.panicmic.com');
        self::assertTrue(TenantContext::isAllowedHost('venue1.panicmic.com'));
        self::assertTrue(TenantContext::isAllowedHost('any.deep.panicmic.com'));
    }

    public function testRejectsUnknownHost(): void
    {
        $_ENV['ALLOWED_HOSTS'] = 'app.test';
        putenv('ALLOWED_HOSTS=app.test');
        self::assertFalse(TenantContext::isAllowedHost('attacker.example'));
        self::assertFalse(TenantContext::isAllowedHost(''));
        // Wildcard must be a real subdomain — bare apex shouldn't match.
        $_ENV['ALLOWED_HOSTS'] = '*.panicmic.com';
        putenv('ALLOWED_HOSTS=*.panicmic.com');
        self::assertFalse(TenantContext::isAllowedHost('panicmic.com'));
    }
}
