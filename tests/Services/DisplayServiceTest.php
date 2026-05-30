<?php

declare(strict_types=1);

namespace PanicMic\Tests\Services;

use PanicMic\Services\DisplayService;
use PanicMic\Tests\Support\DatabaseTestCase;

final class DisplayServiceTest extends DatabaseTestCase
{
    public function testStateDefaultsToMainScreen(): void
    {
        $state = DisplayService::state($this->tenantDb, $this->sessionId);
        self::assertSame('main', $state['screen']);
        self::assertSame('idle', $state['mode']);
    }

    public function testStateIsScopedPerScreen(): void
    {
        DisplayService::update($this->tenantDb, $this->sessionId, ['mode' => 'queue'], null, 'main');
        DisplayService::update($this->tenantDb, $this->sessionId, ['mode' => 'announcement'], null, 'lobby');

        $main = DisplayService::state($this->tenantDb, $this->sessionId, 'main');
        $lobby = DisplayService::state($this->tenantDb, $this->sessionId, 'lobby');

        self::assertSame('queue', $main['mode']);
        self::assertSame('announcement', $lobby['mode']);
    }

    public function testUpdateRejectsInvalidMode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DisplayService::update($this->tenantDb, $this->sessionId, ['mode' => 'bogus'], null);
    }

    public function testNowSingingModeIsAccepted(): void
    {
        DisplayService::update($this->tenantDb, $this->sessionId, ['mode' => 'now_singing'], null);
        $state = DisplayService::state($this->tenantDb, $this->sessionId);
        self::assertSame('now_singing', $state['mode']);
    }

    public function testListScreensReturnsDefaultWhenNoneConfigured(): void
    {
        $screens = DisplayService::listScreens($this->tenantDb, $this->sessionId);
        self::assertCount(1, $screens);
        self::assertSame('main', $screens[0]['screen']);
    }

    public function testUpsertAndRemoveScreen(): void
    {
        DisplayService::upsertScreen($this->tenantDb, $this->sessionId, [
            'screen' => 'lobby',
            'label' => 'Lobby TV',
            'layout' => 'lobby',
            'default_volume' => 50,
            'show_qr' => 1,
            'show_queue' => 1,
        ]);

        $screens = DisplayService::listScreens($this->tenantDb, $this->sessionId);
        self::assertCount(1, $screens);
        self::assertSame('lobby', $screens[0]['screen']);
        self::assertSame('Lobby TV', $screens[0]['label']);

        DisplayService::removeScreen($this->tenantDb, $this->sessionId, 'lobby');
        $screens = DisplayService::listScreens($this->tenantDb, $this->sessionId);
        self::assertSame('main', $screens[0]['screen']);
    }
}
