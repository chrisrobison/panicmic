<?php

declare(strict_types=1);

namespace PanicMic\Services;

use PDO;

final class SongService
{
    /** @param array<string,mixed> $filters @return array{songs:list<array<string,mixed>>,total:int,page:int,size:int} */
    public static function search(PDO $db, array $filters): array
    {
        $size = min(200, max(10, (int)($filters['size'] ?? 50)));
        $page = max(1, (int)($filters['page'] ?? 1));
        $offset = ($page - 1) * $size;

        [$whereSql, $params] = self::buildFilter($filters);
        $order = ($filters['sort'] ?? '') === 'popularity'
            ? 'popularity DESC, title ASC'
            : 'artist ASC, title ASC';

        $countStmt = $db->prepare("SELECT COUNT(*) FROM songs WHERE {$whereSql}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $db->prepare("SELECT * FROM songs WHERE {$whereSql} ORDER BY {$order} LIMIT {$size} OFFSET {$offset}");
        $stmt->execute($params);
        $rows = array_map(static function (array $row): array {
            $row['id'] = (int)$row['id'];
            $row['source'] = 'local';
            return $row;
        }, $stmt->fetchAll());

        return ['songs' => $rows, 'total' => $total, 'page' => $page, 'size' => $size];
    }

    /**
     * Blends tenant catalog + shared catalog for the public/singer-facing search.
     *
     * @param array<string,mixed> $filters
     * @return array{songs:list<array<string,mixed>>,total:int,page:int,size:int,local_total:int,shared_total:int}
     */
    public static function blendedSearch(PDO $tenantDb, PDO $superDb, array $filters): array
    {
        $size = min(100, max(10, (int)($filters['size'] ?? 50)));
        $page = max(1, (int)($filters['page'] ?? 1));

        // Pull capped pages from each source then merge.
        $bucketFilters = $filters + ['page' => 1, 'size' => $size * $page];
        $local = self::search($tenantDb, $bucketFilters);
        $shared = SharedCatalogService::search($superDb, $bucketFilters);

        $merged = array_merge($local['songs'], $shared['songs']);
        usort($merged, static function (array $a, array $b): int {
            return strcmp((string)($a['artist'] ?? ''), (string)($b['artist'] ?? ''))
                ?: strcmp((string)($a['title'] ?? ''), (string)($b['title'] ?? ''));
        });
        $slice = array_slice($merged, ($page - 1) * $size, $size);

        return [
            'songs' => $slice,
            'total' => $local['total'] + $shared['total'],
            'page' => $page,
            'size' => $size,
            'local_total' => $local['total'],
            'shared_total' => $shared['total'],
        ];
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
            ($data['decade'] ?? null) ?: null,
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
            ($data['decade'] ?? null) ?: null,
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

    public static function delete(PDO $db, int $songId): void
    {
        $db->prepare('UPDATE songs SET is_active = 0 WHERE id = ?')->execute([$songId]);
    }

    /** @return array<string,mixed>|null */
    public static function find(PDO $db, int $id): ?array
    {
        $stmt = $db->prepare('SELECT * FROM songs WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @param list<int> $ids @return array<int,array<string,mixed>> */
    public static function findMany(PDO $db, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("SELECT * FROM songs WHERE id IN ({$placeholders})");
        $stmt->execute($ids);
        $by = [];
        foreach ($stmt->fetchAll() as $row) {
            $by[(int)$row['id']] = $row;
        }
        return $by;
    }

    /** @param iterable<array<string,mixed>> $rows @return array{imported:int,skipped:int} */
    public static function bulkImport(PDO $db, iterable $rows): array
    {
        $stmt = $db->prepare(
            'INSERT INTO songs
             (title, artist, genre, decade, popularity, external_id, video_url, video_provider, provider_track_id, provider_url, lyrics_url, provider_metadata)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               genre = COALESCE(VALUES(genre), genre),
               decade = COALESCE(VALUES(decade), decade),
               popularity = GREATEST(popularity, VALUES(popularity)),
               external_id = COALESCE(VALUES(external_id), external_id),
               video_url = COALESCE(VALUES(video_url), video_url),
               video_provider = COALESCE(VALUES(video_provider), video_provider),
               provider_track_id = COALESCE(VALUES(provider_track_id), provider_track_id),
               provider_url = COALESCE(VALUES(provider_url), provider_url),
               lyrics_url = COALESCE(VALUES(lyrics_url), lyrics_url),
               provider_metadata = COALESCE(VALUES(provider_metadata), provider_metadata)'
        );
        $imported = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            $title = trim((string)($row['title'] ?? ''));
            $artist = trim((string)($row['artist'] ?? ''));
            if ($title === '' || $artist === '') {
                $skipped++;
                continue;
            }
            try {
                $stmt->execute([
                    substr($title, 0, 255),
                    substr($artist, 0, 255),
                    self::nullableText($row['genre'] ?? null, 120),
                    isset($row['decade']) && $row['decade'] !== '' ? (int)$row['decade'] : null,
                    isset($row['popularity']) ? (int)$row['popularity'] : 0,
                    self::nullableText($row['external_id'] ?? null, 120),
                    self::nullableUrl($row['video_url'] ?? null),
                    self::nullableText($row['video_provider'] ?? null, 80),
                    self::nullableText($row['provider_track_id'] ?? null, 160),
                    self::nullableUrl($row['provider_url'] ?? null),
                    self::nullableUrl($row['lyrics_url'] ?? null),
                    self::metadataJson($row['provider_metadata'] ?? null),
                ]);
                $imported++;
            } catch (\Throwable) {
                $skipped++;
            }
        }
        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /** @return array{0:string,1:list<mixed>} */
    private static function buildFilter(array $filters): array
    {
        $where = ['is_active = 1'];
        $params = [];
        $query = trim((string)($filters['query'] ?? ''));
        if ($query !== '') {
            if (self::shouldUseFulltext($query)) {
                $where[] = 'MATCH(title, artist) AGAINST (? IN BOOLEAN MODE)';
                $params[] = self::fulltextExpression($query);
            } else {
                $where[] = '(title LIKE ? OR artist LIKE ?)';
                $term = '%' . $query . '%';
                array_push($params, $term, $term);
            }
        }
        if (($filters['artist'] ?? '') !== '') {
            $where[] = 'artist = ?';
            $params[] = $filters['artist'];
        }
        if (($filters['genre'] ?? '') !== '') {
            $where[] = 'genre = ?';
            $params[] = $filters['genre'];
        }
        if (($filters['decade'] ?? '') !== '') {
            $where[] = 'decade = ?';
            $params[] = (int)$filters['decade'];
        }
        if (($filters['video_provider'] ?? '') !== '') {
            $where[] = 'video_provider = ?';
            $params[] = $filters['video_provider'];
        }
        return [implode(' AND ', $where), $params];
    }

    /**
     * Decide between FULLTEXT and LIKE for a given query.
     *
     * FULLTEXT in MySQL's default InnoDB tokenizer drops tokens shorter
     * than `innodb_ft_min_token_size` (default 3) and stop-words, so
     * very short queries (<3 chars) and queries that are entirely
     * non-word characters fall back to LIKE. This keeps "a", "ed",
     * "U2", and pure punctuation queries from silently returning zero.
     */
    public static function shouldUseFulltext(string $query): bool
    {
        $tokens = preg_split('/\s+/', trim($query)) ?: [];
        foreach ($tokens as $token) {
            if (preg_match('/[\p{L}\p{N}]{3,}/u', $token) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Build a BOOLEAN MODE expression: each ≥3-char token becomes a
     * required prefix match (+word*). Shorter tokens are dropped (LIKE
     * fallback already handled them). Strips characters that would
     * break the MATCH parser.
     */
    public static function fulltextExpression(string $query): string
    {
        $tokens = preg_split('/\s+/', trim($query)) ?: [];
        $parts = [];
        foreach ($tokens as $token) {
            $clean = preg_replace('/[^\p{L}\p{N}]+/u', '', $token) ?? '';
            if (strlen($clean) >= 3) {
                $parts[] = '+' . $clean . '*';
            }
        }
        return implode(' ', $parts);
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
        // Allow tenant-local /files/ paths in addition to absolute URLs.
        // These resolve through Url::path() at render time so they work
        // under subdirectory installs.
        $isLocal = str_starts_with($url, '/files/');
        if (!$isLocal && !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Catalog URLs must be a full URL or a /files/… path');
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
