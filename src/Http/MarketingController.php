<?php

declare(strict_types=1);

namespace PanicMic\Http;

use PanicMic\Support\Env;
use PanicMic\Support\Request;
use PanicMic\Support\Security;

final class MarketingController
{
    public static function render(string $page): never
    {
        $pages = [
            'home' => [
                'title' => 'PanicMic — Karaoke show control for working KJs',
                'description' => 'Run singer requests, rotation, venues, catalog, and synchronized displays from one KJ-owned karaoke command center.',
                'path' => '/',
            ],
            'privacy' => [
                'title' => 'Privacy — PanicMic',
                'description' => 'How PanicMic collects, uses, and protects account, show, and singer-request data.',
                'path' => '/privacy',
            ],
            'terms' => [
                'title' => 'Terms of Service — PanicMic',
                'description' => 'Terms for using the PanicMic karaoke show management service.',
                'path' => '/terms',
            ],
        ];
        if (!isset($pages[$page])) {
            http_response_code(404);
            exit;
        }
        $meta = $pages[$page];
        $marketingHost = (string)(Env::get('MARKETING_HOST', 'panicmic.com') ?? 'panicmic.com');
        $signupHost = (string)(Env::get('SIGNUP_HOST', '') ?? '');
        $signupUrl = $signupHost !== ''
            ? 'https://' . $signupHost . '/'
            : '/signup';
        $canonical = 'https://' . $marketingHost . $meta['path'];
        $csrf = Security::csrfToken();
        $requestedPath = Request::path();
        require dirname(__DIR__, 2) . '/views/marketing-layout.php';
        exit;
    }
}
