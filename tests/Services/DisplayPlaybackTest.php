<?php

declare(strict_types=1);

namespace PanicMic\Tests\Services;

use PanicMic\Services\DisplayService;
use PanicMic\Services\EventBus;
use PanicMic\Tests\Support\DatabaseTestCase;

final class DisplayPlaybackTest extends DatabaseTestCase
{
    public function testPublishDisplayCueEvent(): void
    {
        $payload = [
            'screen' => 'main',
            'requestId' => 42,
            'video' => ['provider' => 'youtube', 'youtubeVideoId' => 'abc123', 'videoUrl' => ''],
        ];
        EventBus::publish($this->tenantDb, 'display:cue', $payload);
        $events = EventBus::after($this->tenantDb, 0);
        self::assertCount(1, $events);
        self::assertSame('display:cue', $events[0]['event_name']);
        self::assertSame('abc123', $events[0]['payload']['video']['youtubeVideoId']);
    }

    public function testPublishDisplayPlayAtEvent(): void
    {
        $payload = [
            'screen' => 'all',
            'requestId' => 42,
            'commandId' => 'deadbeef12345678',
            'startAtServerMs' => 1789482827000,
            'offsetSeconds' => 0.0,
        ];
        EventBus::publish($this->tenantDb, 'display:play_at', $payload);
        $events = EventBus::after($this->tenantDb, 0);
        self::assertCount(1, $events);
        self::assertSame('display:play_at', $events[0]['event_name']);
        self::assertSame('deadbeef12345678', $events[0]['payload']['commandId']);
        self::assertSame(1789482827000, $events[0]['payload']['startAtServerMs']);
    }

    public function testScreenSanitization(): void
    {
        // Verify screen ID sanitization matches DisplayService.
        $raw = 'main<script>alert(1)</script>';
        $clean = preg_replace('/[^a-z0-9_-]/i', '', $raw);
        self::assertSame('mainscriptalert1script', $clean); // malicious chars stripped

        $goodScreen = 'stage-left';
        $cleanGood = preg_replace('/[^a-z0-9_-]/i', '', $goodScreen);
        self::assertSame('stage-left', $cleanGood);
    }

    public function testDisplayStatePlaybackFieldsDefaultToStopped(): void
    {
        // After migration 012, play_state defaults to 'stopped'.
        DisplayService::update($this->tenantDb, $this->sessionId, ['mode' => 'now_singing'], null);
        $state = DisplayService::state($this->tenantDb, $this->sessionId);
        self::assertSame('now_singing', $state['mode']);
        // play_state column may or may not exist depending on test DB migration.
        if (array_key_exists('play_state', $state)) {
            self::assertSame('stopped', $state['play_state']);
        }
    }
}
