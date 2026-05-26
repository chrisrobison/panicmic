<?php

declare(strict_types=1);

namespace NextUp\Tests\Tenant;

use NextUp\Support\Env;
use NextUp\Tenant\TenantContext;
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
        $_SERVER['HTTP_HOST'] = 'bluebird.test:8000';
        self::assertSame('bluebird.test', TenantContext::host());
    }

    public function testLowercasesAndTrims(): void
    {
        $_SERVER['HTTP_HOST'] = '  Bluebird.TEST  ';
        self::assertSame('bluebird.test', TenantContext::host());
    }

    public function testHandlesIPv6BracketedHost(): void
    {
        $_SERVER['HTTP_HOST'] = '[::1]:8000';
        self::assertSame('::1', TenantContext::host());
    }

    public function testIgnoresForwardedHeaderWhenTrustProxyOff(): void
    {
        $_SERVER['HTTP_HOST'] = 'direct.test';
        $_SERVER['HTTP_X_FORWARDED_HOST'] = 'evil.test';
        self::assertSame('direct.test', TenantContext::host());
    }

    public function testTrustsForwardedHeaderWhenTrustProxyOn(): void
    {
        $_ENV['TRUST_PROXY'] = 'true';
        putenv('TRUST_PROXY=true');
        $_SERVER['HTTP_HOST'] = 'proxy-internal';
        $_SERVER['HTTP_X_FORWARDED_HOST'] = 'public.test';
        self::assertSame('public.test', TenantContext::host());
    }

    public function testHandlesCommaSeparatedForwardedList(): void
    {
        $_ENV['TRUST_PROXY'] = 'true';
        putenv('TRUST_PROXY=true');
        $_SERVER['HTTP_X_FORWARDED_HOST'] = 'first.test, hop2.example.com';
        self::assertSame('first.test', TenantContext::host());
    }
}
