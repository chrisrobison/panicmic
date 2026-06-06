<?php

declare(strict_types=1);

namespace PanicMic\Tests\Services;

use PanicMic\Services\DisplayService;
use PanicMic\Services\QueueService;
use PanicMic\Services\SongService;
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

    public function testClearNowRequestResetsDisplayingScreensOnly(): void
    {
        $song = SongService::create($this->tenantDb, ['title' => 'Closing Time', 'artist' => 'Semisonic']);
        $requestId = QueueService::submit($this->tenantDb, $this->sessionId, ['song_id' => $song, 'display_name' => 'Sam'], 'tok-clear', false);

        // "main" is showing the act; "lobby" is on an unrelated mode.
        DisplayService::update($this->tenantDb, $this->sessionId, ['mode' => 'now_singing', 'now_request_id' => $requestId], null, 'main');
        DisplayService::update($this->tenantDb, $this->sessionId, ['mode' => 'announcement'], null, 'lobby');

        $cleared = DisplayService::clearNowRequest($this->tenantDb, $this->sessionId, $requestId);

        self::assertSame(['main'], $cleared);
        $main = DisplayService::state($this->tenantDb, $this->sessionId, 'main');
        self::assertSame('idle', $main['mode']);
        self::assertNull($main['now_request_id']);
        // A screen that wasn't showing the act is left untouched.
        self::assertSame('announcement', DisplayService::state($this->tenantDb, $this->sessionId, 'lobby')['mode']);
    }

    public function testClearNowRequestIsNoopWhenNothingDisplaysIt(): void
    {
        $song = SongService::create($this->tenantDb, ['title' => 'Untouched', 'artist' => 'Nobody']);
        $requestId = QueueService::submit($this->tenantDb, $this->sessionId, ['song_id' => $song, 'display_name' => 'Pat'], 'tok-noop', false);

        self::assertSame([], DisplayService::clearNowRequest($this->tenantDb, $this->sessionId, $requestId));
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
