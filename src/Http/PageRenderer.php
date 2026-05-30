<?php

declare(strict_types=1);

namespace PanicMic\Http;

use PanicMic\Support\Security;
use PanicMic\Support\Url;

final class PageRenderer
{
    /** @param array<string,mixed> $tenant @param array<string,mixed> $session */
    public static function render(string $page, array $tenant, array $session): never
    {
        $csrf = Security::csrfToken();
        $basePath = Url::basePath();
        require dirname(__DIR__, 2) . '/views/layout.php';
        exit;
    }

    /**
     * Public-facing tenant payload exposed to JS via /api/config.
     *
     * @param array<string,mixed> $tenant
     * @return array<string,mixed>
     */
    public static function publicTenant(array $tenant): array
    {
        return [
            'id' => (int)$tenant['id'],
            'slug' => $tenant['slug'],
            'venueName' => $tenant['venue_name'],
            'nightName' => $tenant['night_name'],
            'logoUrl' => $tenant['logo_url'],
            'profileImageUrl' => $tenant['profile_image_url'] ?? null,
            'backgroundImageUrl' => $tenant['background_image_url'] ?? null,
            'backgroundColor' => $tenant['background_color'] ?? '#101216',
            'surfaceColor' => $tenant['surface_color'] ?? '#191d24',
            'textColor' => $tenant['text_color'] ?? '#f5f7fb',
            'primaryColor' => $tenant['primary_color'],
            'accentColor' => $tenant['accent_color'],
            'timezone' => $tenant['timezone'],
            'signupMode' => $tenant['signup_mode'],
            'publicRequestUrl' => $tenant['public_request_url'],
            'projectionUrl' => $tenant['projection_url'],
        ];
    }
}
