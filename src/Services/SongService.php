<?php

declare(strict_types=1);

namespace NextUp\Services;

use PDO;

final class SongService
{
    /** @param array<string,mixed> $filters @return list<array<string,mixed>> */
    public static function search(PDO $db, array $filters): array
    {
        $where = ['is_active = 1'];
        $params = [];
        if (($filters['query'] ?? '') !== '') {
            $where[] = '(title LIKE ? OR artist LIKE ?)';
            $term = '%' . $filters['query'] . '%';
            array_push($params, $term, $term);
        }
        foreach (['artist', 'genre', 'decade'] as $field) {
            if (($filters[$field] ?? '') !== '') {
                $where[] = "{$field} = ?";
                $params[] = $filters[$field];
            }
        }
        $order = ($filters['sort'] ?? '') === 'popularity' ? 'popularity DESC, title ASC' : 'artist ASC, title ASC';
        $stmt = $db->prepare('SELECT * FROM songs WHERE ' . implode(' AND ', $where) . " ORDER BY {$order} LIMIT 100");
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @param array<string,mixed> $data */
    public static function create(PDO $db, array $data): int
    {
        $stmt = $db->prepare(
            'INSERT INTO songs
             (title, artist, genre, decade, popularity, external_id, video_url, video_provider, provider_track_id, provider_url, lyrics_url, provider_metadata)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            trim((string)$data['title']),
            trim((string)$data['artist']),
            trim((string)($data['genre'] ?? '')) ?: null,
            $data['decade'] ?: null,
            (int)($data['popularity'] ?? 0),
            trim((string)($data['external_id'] ?? '')) ?: null,
            self::nullableUrl($data['video_url'] ?? null),
            self::nullableText($data['video_provider'] ?? null, 80),
            self::nullableText($data['provider_track_id'] ?? null, 160),
            self::nullableUrl($data['provider_url'] ?? null),
            self::nullableUrl($data['lyrics_url'] ?? null),
            self::metadataJson($data['provider_metadata'] ?? null),
        ]);
        return (int)$db->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(PDO $db, int $songId, array $data): void
    {
        $stmt = $db->prepare(
            'UPDATE songs
             SET title = ?, artist = ?, genre = ?, decade = ?, popularity = ?, external_id = ?,
                 video_url = ?, video_provider = ?, provider_track_id = ?, provider_url = ?, lyrics_url = ?, provider_metadata = ?
             WHERE id = ?'
        );
        $stmt->execute([
            trim((string)$data['title']),
            trim((string)$data['artist']),
            trim((string)($data['genre'] ?? '')) ?: null,
            $data['decade'] ?: null,
            (int)($data['popularity'] ?? 0),
            self::nullableText($data['external_id'] ?? null, 120),
            self::nullableUrl($data['video_url'] ?? null),
            self::nullableText($data['video_provider'] ?? null, 80),
            self::nullableText($data['provider_track_id'] ?? null, 160),
            self::nullableUrl($data['provider_url'] ?? null),
            self::nullableUrl($data['lyrics_url'] ?? null),
            self::metadataJson($data['provider_metadata'] ?? null),
            $songId,
        ]);
    }

    private static function nullableText(mixed $value, int $max): ?string
    {
        $text = trim((string)($value ?? ''));
        return $text === '' ? null : substr($text, 0, $max);
    }

    private static function nullableUrl(mixed $value): ?string
    {
        $url = trim((string)($value ?? ''));
        if ($url === '') {
            return null;
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Catalog URLs must be valid URLs');
        }
        return substr($url, 0, 512);
    }

    private static function metadataJson(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)) {
            json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \InvalidArgumentException('Provider metadata must be valid JSON');
            }
            return $value;
        }
        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
