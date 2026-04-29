<?php

declare(strict_types=1);

namespace NextUp\Services;

use PDO;

final class TenantBrandingService
{
    /** @return array<string,mixed> */
    public static function get(PDO $superDb, int $tenantId): array
    {
        $stmt = $superDb->prepare(
            'SELECT venue_name, night_name, logo_url, profile_image_url, background_image_url,
                    background_color, surface_color, text_color, primary_color, accent_color
             FROM tenants WHERE id = ?'
        );
        $stmt->execute([$tenantId]);
        return $stmt->fetch() ?: [];
    }

    /** @param array<string,mixed> $data */
    public static function update(PDO $superDb, int $tenantId, array $data): void
    {
        $stmt = $superDb->prepare(
            'UPDATE tenants
             SET venue_name = ?, night_name = ?, logo_url = ?, profile_image_url = ?, background_image_url = ?,
                 background_color = ?, surface_color = ?, text_color = ?, primary_color = ?, accent_color = ?
             WHERE id = ?'
        );
        $stmt->execute([
            self::requiredText($data['venue_name'] ?? '', 160),
            self::requiredText($data['night_name'] ?? '', 160),
            self::nullableUrl($data['logo_url'] ?? null),
            self::nullableUrl($data['profile_image_url'] ?? null),
            self::nullableUrl($data['background_image_url'] ?? null),
            self::color($data['background_color'] ?? '#101216'),
            self::color($data['surface_color'] ?? '#191d24'),
            self::color($data['text_color'] ?? '#f5f7fb'),
            self::color($data['primary_color'] ?? '#21d4a5'),
            self::color($data['accent_color'] ?? '#ffca3a'),
            $tenantId,
        ]);
    }

    private static function requiredText(mixed $value, int $max): string
    {
        $text = trim((string)$value);
        if ($text === '') {
            throw new \InvalidArgumentException('Venue and night names are required');
        }
        return substr($text, 0, $max);
    }

    private static function nullableUrl(mixed $value): ?string
    {
        $url = trim((string)($value ?? ''));
        if ($url === '') {
            return null;
        }
        if (str_starts_with($url, '/files/')) {
            return substr($url, 0, 512);
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Image URLs must be absolute URLs or /files paths');
        }
        return substr($url, 0, 512);
    }

    private static function color(mixed $value): string
    {
        $color = trim((string)$value);
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            throw new \InvalidArgumentException('Colors must use #RRGGBB format');
        }
        return strtolower($color);
    }
}
