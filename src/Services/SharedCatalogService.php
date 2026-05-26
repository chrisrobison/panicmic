<?php

declare(strict_types=1);

namespace NextUp\Services;

use PDO;

final class SharedCatalogService
{
    /** @param array<string,mixed> $filters @return array{songs:list<array<string,mixed>>,total:int,page:int,size:int} */
    public static function search(PDO $superDb, array $filters): array
    {
        $size = min(200, max(10, (int)($filters['size'] ?? 50)));
        $page = max(1, (int)($filters['page'] ?? 1));
        $offset = ($page - 1) * $size;

        [$whereSql, $params] = self::buildFilter($filters);
        $countStmt = $superDb->prepare("SELECT COUNT(*) FROM shared_songs WHERE {$whereSql}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $superDb->prepare("SELECT * FROM shared_songs WHERE {$whereSql} ORDER BY artist ASC, title ASC LIMIT {$size} OFFSET {$offset}");
        $stmt->execute($params);
        $rows = array_map(self::decode(...), $stmt->fetchAll());

        return ['songs' => $rows, 'total' => $total, 'page' => $page, 'size' => $size];
    }

    /** @return array<string,mixed>|null */
    public static function find(PDO $superDb, int $id): ?array
    {
        $stmt = $superDb->prepare('SELECT * FROM shared_songs WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? self::decode($row) : null;
    }

    /** @param list<int> $ids @return array<int,array<string,mixed>> */
    public static function findMany(PDO $superDb, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $superDb->prepare("SELECT * FROM shared_songs WHERE id IN ({$placeholders})");
        $stmt->execute($ids);
        $by = [];
        foreach ($stmt->fetchAll() as $row) {
            $by[(int)$row['id']] = self::decode($row);
        }
        return $by;
    }

    /** @param iterable<array<string,mixed>> $rows @return array{imported:int,skipped:int} */
    public static function bulkImport(PDO $superDb, iterable $rows): array
    {
        $stmt = $superDb->prepare(
            'INSERT INTO shared_songs (external_id, title, artist, genre, year, decade, duo, explicit, styles, languages)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, CAST(? AS JSON), CAST(? AS JSON))
             ON DUPLICATE KEY UPDATE
               external_id = COALESCE(VALUES(external_id), external_id),
               genre = COALESCE(VALUES(genre), genre),
               year = COALESCE(VALUES(year), year),
               decade = COALESCE(VALUES(decade), decade),
               duo = VALUES(duo),
               explicit = VALUES(explicit),
               styles = VALUES(styles),
               languages = VALUES(languages)'
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
            $year = isset($row['year']) ? (int)$row['year'] : null;
            $decade = $year && $year >= 1900 && $year <= 2100 ? $year - ($year % 10) : null;
            $styles = self::normalizeList($row['styles'] ?? null);
            $languages = self::normalizeList($row['languages'] ?? null);
            $genre = $styles[0] ?? null;
            try {
                $stmt->execute([
                    !empty($row['id']) ? substr((string)$row['id'], 0, 120) : null,
                    substr($title, 0, 255),
                    substr($artist, 0, 255),
                    $genre ? substr($genre, 0, 120) : null,
                    $year && $year >= 1900 && $year <= 2100 ? $year : null,
                    $decade,
                    self::truthy($row['duo'] ?? false) ? 1 : 0,
                    self::truthy($row['explicit'] ?? false) ? 1 : 0,
                    $styles ? json_encode($styles) : null,
                    $languages ? json_encode($languages) : null,
                ]);
                $imported++;
            } catch (\Throwable) {
                $skipped++;
            }
        }
        return ['imported' => $imported, 'skipped' => $skipped];
    }

    public static function count(PDO $superDb): int
    {
        return (int)$superDb->query('SELECT COUNT(*) FROM shared_songs')->fetchColumn();
    }

    public static function exists(PDO $superDb, int $id): bool
    {
        $stmt = $superDb->prepare('SELECT 1 FROM shared_songs WHERE id = ? AND is_active = 1');
        $stmt->execute([$id]);
        return (bool)$stmt->fetchColumn();
    }

    public static function delete(PDO $superDb, int $id): void
    {
        $stmt = $superDb->prepare('UPDATE shared_songs SET is_active = 0 WHERE id = ?');
        $stmt->execute([$id]);
    }

    /** @return array{0:string,1:list<mixed>} */
    private static function buildFilter(array $filters): array
    {
        $where = ['is_active = 1'];
        $params = [];
        $query = trim((string)($filters['query'] ?? ''));
        if ($query !== '') {
            $where[] = '(title LIKE ? OR artist LIKE ?)';
            $term = '%' . $query . '%';
            array_push($params, $term, $term);
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
        return [implode(' AND ', $where), $params];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function decode(array $row): array
    {
        $row['source'] = 'shared';
        $row['id'] = (int)$row['id'];
        $row['styles'] = isset($row['styles']) && $row['styles'] !== null
            ? (is_string($row['styles']) ? json_decode($row['styles'], true) : $row['styles'])
            : null;
        $row['languages'] = isset($row['languages']) && $row['languages'] !== null
            ? (is_string($row['languages']) ? json_decode($row['languages'], true) : $row['languages'])
            : null;
        return $row;
    }

    /** @return list<string>|null */
    private static function normalizeList(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            $parts = array_map(static fn ($v) => trim((string)$v), $value);
        } else {
            $parts = preg_split('/[,;]+/', (string)$value) ?: [];
            $parts = array_map(static fn (string $v) => trim($v), $parts);
        }
        $parts = array_values(array_filter($parts, static fn (string $v) => $v !== ''));
        return $parts ?: null;
    }

    private static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        $str = strtolower(trim((string)$value));
        return $str !== '' && !in_array($str, ['0', 'false', 'no', 'off'], true);
    }
}
