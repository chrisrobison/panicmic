<?php

declare(strict_types=1);

namespace PanicMic\Tests\Services;

use PanicMic\Services\ContentService;
use PHPUnit\Framework\TestCase;

final class ContentServiceTest extends TestCase
{
    public function testVerifyMagicBytesAcceptsRealPng(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'panicmic_test_png_');
        // 1x1 transparent PNG.
        file_put_contents($tmp, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
        ));
        try {
            ContentService::verifyMagicBytes($tmp, 'png');
            $this->addToAssertionCount(1);
        } finally {
            @unlink($tmp);
        }
    }

    public function testVerifyMagicBytesRejectsRenamedExecutable(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'panicmic_test_exe_');
        // ELF header — definitely not a PNG.
        file_put_contents($tmp, "\x7fELF" . str_repeat("\0", 60));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/does not match its \.png extension/');
        try {
            ContentService::verifyMagicBytes($tmp, 'png');
        } finally {
            @unlink($tmp);
        }
    }

    public function testVerifyMagicBytesRejectsUnknownExtension(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'panicmic_test_x_');
        file_put_contents($tmp, "hello");
        $this->expectException(\InvalidArgumentException::class);
        try {
            ContentService::verifyMagicBytes($tmp, 'sh');
        } finally {
            @unlink($tmp);
        }
    }

    public function testSafeAccountNameNormalizes(): void
    {
        self::assertSame('bluebird-bar', ContentService::safeAccountName('Bluebird   Bar!!'));
        self::assertSame('tenant', ContentService::safeAccountName(''));
    }
}
