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
        $stmt = $db->prepare('INSERT INTO songs (title, artist, genre, decade, popularity, external_id) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            trim((string)$data['title']),
            trim((string)$data['artist']),
            trim((string)($data['genre'] ?? '')) ?: null,
            $data['decade'] ?: null,
            (int)($data['popularity'] ?? 0),
            trim((string)($data['external_id'] ?? '')) ?: null,
        ]);
        return (int)$db->lastInsertId();
    }
}
