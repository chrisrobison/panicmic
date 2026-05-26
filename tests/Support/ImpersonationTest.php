<?php

declare(strict_types=1);

namespace NextUp\Tests\Support;

use NextUp\Support\Impersonation;
use PHPUnit\Framework\TestCase;

final class ImpersonationTest extends TestCase
{
    public function testSignedTokenRoundTrips(): void
    {
        $token = Impersonation::sign(42, 7, 60);
        $decoded = Impersonation::verify($token);
        self::assertNotNull($decoded);
        self::assertSame(42, $decoded['super_id']);
        self::assertSame(7, $decoded['tenant_id']);
    }

    public function testTamperedSignatureIsRejected(): void
    {
        $token = Impersonation::sign(1, 2, 60);
        // Flip a character in the signature.
        $tampered = substr($token, 0, -1) . (substr($token, -1) === 'a' ? 'b' : 'a');
        self::assertNull(Impersonation::verify($tampered));
    }

    public function testTamperedBodyIsRejected(): void
    {
        $token = Impersonation::sign(1, 2, 60);
        [$body, $sig] = explode('.', $token, 2);
        // Replace body with one claiming super_id=999 but keep original sig.
        $forgedBody = rtrim(strtr(base64_encode(json_encode(['s' => 999, 't' => 2, 'e' => time() + 60])), '+/', '-_'), '=');
        self::assertNull(Impersonation::verify($forgedBody . '.' . $sig));
    }

    public function testExpiredTokenIsRejected(): void
    {
        $token = Impersonation::sign(1, 2, -1);
        self::assertNull(Impersonation::verify($token));
    }

    public function testMalformedTokenIsRejected(): void
    {
        self::assertNull(Impersonation::verify('not-a-token'));
        self::assertNull(Impersonation::verify(''));
        self::assertNull(Impersonation::verify('only.one.dot.too.many'));
    }
}
