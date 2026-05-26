<?php

declare(strict_types=1);

namespace NextUp\Support;

final class Impersonation
{
    public static function sign(int $superId, int $tenantId, int $ttlSeconds = 300): string
    {
        $payload = ['s' => $superId, 't' => $tenantId, 'e' => time() + $ttlSeconds];
        $body = self::base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = hash_hmac('sha256', $body, self::secret());
        return $body . '.' . $signature;
    }

    /** @return array{super_id:int,tenant_id:int}|null */
    public static function verify(string $token): ?array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return null;
        }
        [$body, $signature] = $parts;
        $expected = hash_hmac('sha256', $body, self::secret());
        if (!hash_equals($expected, $signature)) {
            return null;
        }
        $json = self::base64UrlDecode($body);
        if ($json === null) {
            return null;
        }
        $payload = json_decode($json, true);
        if (!is_array($payload) || !isset($payload['s'], $payload['t'], $payload['e'])) {
            return null;
        }
        if ((int)$payload['e'] < time()) {
            return null;
        }
        return ['super_id' => (int)$payload['s'], 'tenant_id' => (int)$payload['t']];
    }

    private static function secret(): string
    {
        $secret = (string)(Env::get('CSRF_SECRET', '') ?? '');
        return $secret !== '' ? $secret : 'nextup-default-do-not-use-in-production';
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $padded = $value . str_repeat('=', (4 - strlen($value) % 4) % 4);
        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);
        return $decoded === false ? null : $decoded;
    }
}
