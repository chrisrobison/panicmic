<?php

declare(strict_types=1);

namespace PanicMic\Tests\Support;

use PanicMic\Support\Security;

final class RateLimitTest extends DatabaseTestCase
{
    public function testBucketCountsIncrementWithEachAttempt(): void
    {
        $bucket = 'test:127.0.0.1:user@example.com';
        $a = Security::rateLimitDb($this->superDb, $bucket, 5, 60);
        $b = Security::rateLimitDb($this->superDb, $bucket, 5, 60);
        $c = Security::rateLimitDb($this->superDb, $bucket, 5, 60);
        self::assertSame(1, $a);
        self::assertSame(2, $b);
        self::assertSame(3, $c);
    }

    public function testBucketsAreIsolated(): void
    {
        Security::rateLimitDb($this->superDb, 'bucket:a', 5, 60);
        Security::rateLimitDb($this->superDb, 'bucket:a', 5, 60);
        $first = Security::rateLimitDb($this->superDb, 'bucket:b', 5, 60);
        self::assertSame(1, $first);
    }

    public function testClearResetsBucket(): void
    {
        Security::rateLimitDb($this->superDb, 'bucket:clear', 5, 60);
        Security::rateLimitDb($this->superDb, 'bucket:clear', 5, 60);
        Security::rateLimitDbClear($this->superDb, 'bucket:clear');
        $afterClear = Security::rateLimitDb($this->superDb, 'bucket:clear', 5, 60);
        self::assertSame(1, $afterClear);
    }

    public function testPruneDropsAttemptsOutsideWindow(): void
    {
        // Backdate one attempt to 10 minutes ago.
        $this->superDb->exec(
            "INSERT INTO login_attempts (bucket, attempted_at) " .
            "VALUES ('bucket:old', NOW() - INTERVAL 600 SECOND)"
        );
        // window=60s so the old row should be pruned by the next attempt
        // before counting kicks in.
        $count = Security::rateLimitDb($this->superDb, 'bucket:old', 5, 60);
        self::assertSame(1, $count);
    }

    public function testLoginBucketIncludesIpAndEmail(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $bucket = Security::loginBucket('  USER@Example.com  ');
        self::assertSame('login:203.0.113.7:user@example.com', $bucket);
    }
}
