<?php

declare(strict_types=1);

namespace NextUp\Services;

use NextUp\Support\Env;

/**
 * Minimal mailer abstraction.
 *
 * In dev the default `log` driver writes each message to
 * storage/mail.log so flows can be verified end-to-end without an SMTP
 * server. In production set MAIL_DRIVER=postmark + POSTMARK_TOKEN to
 * deliver via Postmark's HTTP API. Other providers can be added by
 * registering another driver below.
 */
final class Mailer
{
    /**
     * @param array<string,string|null> $headers
     */
    public static function send(string $to, string $subject, string $body, array $headers = []): bool
    {
        $driver = strtolower((string)(Env::get('MAIL_DRIVER', 'log') ?? 'log'));
        $from = (string)(Env::get('MAIL_FROM', 'no-reply@example.com') ?? 'no-reply@example.com');
        $fromName = (string)(Env::get('MAIL_FROM_NAME', 'NextUp') ?? 'NextUp');

        return match ($driver) {
            'postmark' => self::sendPostmark($to, $from, $fromName, $subject, $body, $headers),
            default => self::sendLog($to, $from, $fromName, $subject, $body, $headers),
        };
    }

    /** @param array<string,string|null> $headers */
    private static function sendLog(string $to, string $from, string $fromName, string $subject, string $body, array $headers): bool
    {
        $dir = dirname(__DIR__, 2) . '/storage';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $line = sprintf(
            "[%s] To: %s\nFrom: %s <%s>\nSubject: %s\n%s\n----\n%s\n========\n",
            date(DATE_ATOM),
            $to,
            $fromName,
            $from,
            $subject,
            implode("\n", array_map(static fn ($k, $v) => "{$k}: {$v}", array_keys($headers), array_values($headers))),
            $body,
        );
        return @file_put_contents($dir . '/mail.log', $line, FILE_APPEND | LOCK_EX) !== false;
    }

    /** @param array<string,string|null> $headers */
    private static function sendPostmark(string $to, string $from, string $fromName, string $subject, string $body, array $headers): bool
    {
        $token = (string)(Env::get('POSTMARK_TOKEN', '') ?? '');
        if ($token === '') {
            // Postmark requested but unconfigured — fall back to log so
            // signup doesn't silently fail in misconfigured environments.
            return self::sendLog($to, $from, $fromName, $subject, $body, $headers);
        }
        $payload = json_encode([
            'From' => sprintf('%s <%s>', $fromName, $from),
            'To' => $to,
            'Subject' => $subject,
            'TextBody' => $body,
            'MessageStream' => 'outbound',
        ], JSON_THROW_ON_ERROR);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Accept: application/json\r\n"
                    . "Content-Type: application/json\r\n"
                    . "X-Postmark-Server-Token: {$token}\r\n",
                'content' => $payload,
                'timeout' => 8,
                'ignore_errors' => true,
            ],
        ]);
        $response = @file_get_contents('https://api.postmarkapp.com/email', false, $context);
        return $response !== false;
    }
}
