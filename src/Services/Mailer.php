<?php

declare(strict_types=1);

namespace NextUp\Services;

use NextUp\Support\Env;

/**
 * Minimal mailer abstraction.
 *
 * In dev the default `log` driver writes each message to
 * storage/mail.log so flows can be verified end-to-end without an SMTP
 * server. In production set MAIL_DRIVER=exim (or `sendmail`) to pipe
 * messages to the local MTA — no third-party HTTP API required. The
 * binary path is overridable via MAIL_SENDMAIL_PATH so the same driver
 * works against exim, Postfix, or any sendmail-compatible MTA.
 * MAIL_DRIVER=postmark is still supported for hosted setups.
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
            'exim', 'sendmail' => self::sendSendmail($to, $from, $fromName, $subject, $body, $headers),
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

    /**
     * Pipe an RFC822 message to a local sendmail-compatible MTA
     * (exim, postfix, sendmail). Recipients are read from the To header
     * thanks to the `-t` flag; `-i` keeps a lone "." from terminating
     * the message body.
     *
     * @param array<string,string|null> $headers
     */
    private static function sendSendmail(string $to, string $from, string $fromName, string $subject, string $body, array $headers): bool
    {
        $binary = trim((string)(Env::get('MAIL_SENDMAIL_PATH', '/usr/sbin/exim') ?? '/usr/sbin/exim'));
        if ($binary === '' || !is_executable(explode(' ', $binary, 2)[0])) {
            return self::sendLog($to, $from, $fromName, $subject, $body, $headers);
        }

        $message = self::buildRfc822Message($to, $from, $fromName, $subject, $body, $headers);
        $command = $binary . ' -t -i -f ' . escapeshellarg($from);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            return self::sendLog($to, $from, $fromName, $subject, $body, $headers);
        }

        fwrite($pipes[0], $message);
        fclose($pipes[0]);
        // Drain stdout/stderr so the child doesn't block on a full pipe.
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit = proc_close($process);
        if ($exit !== 0) {
            return self::sendLog($to, $from, $fromName, $subject, $body, $headers);
        }
        return true;
    }

    /** @param array<string,string|null> $headers */
    private static function buildRfc822Message(string $to, string $from, string $fromName, string $subject, string $body, array $headers): string
    {
        $required = [
            'From' => sprintf('%s <%s>', self::encodeHeader($fromName), $from),
            'To' => $to,
            'Subject' => self::encodeHeader($subject),
            'Date' => date(DATE_RFC2822),
            'Message-ID' => sprintf('<%s@%s>', bin2hex(random_bytes(12)), self::hostnameForMessageId($from)),
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Transfer-Encoding' => '8bit',
        ];
        // Caller-supplied headers win, but strip newlines defensively.
        foreach ($headers as $name => $value) {
            if ($value === null) {
                continue;
            }
            $clean = (string)preg_replace('/[\r\n]+/', ' ', (string)$value);
            $required[$name] = $clean;
        }

        $lines = [];
        foreach ($required as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $headerBlock = implode("\r\n", $lines);
        // Normalise body line endings to CRLF and strip any stray NULs.
        $normalisedBody = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $body);
        $normalisedBody = str_replace("\n", "\r\n", $normalisedBody);

        return $headerBlock . "\r\n\r\n" . $normalisedBody . "\r\n";
    }

    private static function encodeHeader(string $value): string
    {
        // Only encode when non-ASCII present; keeps Subject readable in
        // the common case.
        if (preg_match('/[^\x20-\x7E]/', $value) !== 1) {
            return $value;
        }
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private static function hostnameForMessageId(string $from): string
    {
        $at = strrpos($from, '@');
        if ($at !== false && $at < strlen($from) - 1) {
            return substr($from, $at + 1);
        }
        $host = gethostname();
        return is_string($host) && $host !== '' ? $host : 'localhost';
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
