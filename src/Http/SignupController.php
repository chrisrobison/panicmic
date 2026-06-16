<?php

declare(strict_types=1);

namespace PanicMic\Http;

use PanicMic\Database\Connection;
use PanicMic\Services\SignupService;
use PanicMic\Support\Env;
use PanicMic\Support\Request;
use PanicMic\Support\Response;
use PanicMic\Support\Security;

final class SignupController
{
    /**
     * Public signup landing — rendered on the marketing / super-admin
     * hostname (e.g. panicmic.com). Posts to {@see register}.
     */
    public static function page(): never
    {
        PageRenderer::render(
            'signup',
            [
                'venue_name' => 'PanicMic',
                'night_name' => 'Get started',
                'primary_color' => '#22c55e',
                'accent_color' => '#facc15',
            ],
            ['id' => 0, 'name' => 'Signup'],
        );
    }

    public static function register(): never
    {
        $superDb = Connection::super();
        // Throttle per-IP: signup auto-provisions a database and the
        // taken/available responses otherwise allow subdomain enumeration.
        // DB-backed so it survives session resets (shares login_attempts).
        Security::rateLimitDb($superDb, Security::signupBucket(), 10, 3600);
        $input = Request::input();
        try {
            $result = SignupService::register($superDb, [
                'venue_name' => (string)($input['venue_name'] ?? ''),
                'email' => (string)($input['email'] ?? ''),
                'subdomain' => (string)($input['subdomain'] ?? ''),
                'night_name' => (string)($input['night_name'] ?? 'Karaoke Night'),
            ]);
        } catch (\InvalidArgumentException $e) {
            Response::json(['error' => $e->getMessage()], 400);
        }

        // In development (MAIL_DRIVER=log) the user can't click an email
        // link, so surface the invite URL directly. In production we
        // omit it; the email is the only path.
        $payload = [
            'ok' => true,
            'tenant_id' => $result['tenant_id'],
            'slug' => $result['slug'],
            'next' => 'Check your email for the activation link.',
        ];
        if ((string)Env::get('MAIL_DRIVER', 'log') === 'log') {
            $payload['invite_url'] = $result['invite_url'];
        }
        Response::json($payload, 201);
    }

    /**
     * Invite acceptance page. The user clicks the email link, sets a
     * password, and gets dropped into their tenant.
     */
    public static function acceptPage(): never
    {
        PageRenderer::render(
            'signup-accept',
            [
                'venue_name' => 'PanicMic',
                'night_name' => 'Activate your account',
                'primary_color' => '#22c55e',
                'accent_color' => '#facc15',
            ],
            ['id' => 0, 'name' => 'Activate'],
        );
    }

    public static function accept(): never
    {
        $superDb = Connection::super();
        $input = Request::input();
        try {
            $result = SignupService::acceptInvite(
                $superDb,
                (string)($input['token'] ?? ''),
                (string)($input['display_name'] ?? ''),
                (string)($input['password'] ?? ''),
            );
        } catch (\InvalidArgumentException $e) {
            Response::json(['error' => $e->getMessage()], 400);
        }
        Response::json(['ok' => true, 'tenant' => $result], 200);
    }
}
