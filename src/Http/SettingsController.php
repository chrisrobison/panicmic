<?php

declare(strict_types=1);

namespace PanicMic\Http;

use PanicMic\Auth\Auth;
use PanicMic\Services\EventBus;
use PanicMic\Services\SettingsService;
use PanicMic\Services\YouTubeService;
use PanicMic\Support\Request;
use PanicMic\Support\Response;
use PDO;

final class SettingsController
{
    public static function index(PDO $db): never
    {
        Auth::requireTenantRole('kj', 'tenant_admin');
        Response::json([
            'settings' => SettingsService::all($db),
            'defaults' => SettingsService::DEFAULTS,
            'youtube_enabled' => YouTubeService::isEnabled(),
        ]);
    }

    public static function update(PDO $db): never
    {
        Auth::requireTenantRole('tenant_admin');
        SettingsService::saveMany($db, Request::input());
        EventBus::publish($db, 'settings:updated', ['settings' => SettingsService::all($db)]);
        Response::json(['settings' => SettingsService::all($db)]);
    }
}
