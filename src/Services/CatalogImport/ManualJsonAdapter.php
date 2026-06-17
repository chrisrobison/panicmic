<?php

declare(strict_types=1);

namespace PanicMic\Services\CatalogImport;

/**
 * Adapter for manual JSON files.
 *
 * Accepts a JSON array of song objects. Keys are flexible —
 * see normalizeRow() for the accepted key aliases.
 *
 * Example:
 * [
 *   {"title": "Don't Stop Believin'", "artist": "Journey", "year": 1981},
 *   ...
 * ]
 */
final class ManualJsonAdapter implements SourceAdapter
{
    /** @param list<array<string,mixed>> $lists */
    public function __construct(
        private readonly array $lists,
        private readonly string $basePath,
    ) {}

    public function sourceSlug(): string
    {
        return 'manual-json';
    }

    /** @return list<array<string,mixed>> */
    public function lists(): array
    {
        return $this->lists;
    }

    public function fetch(array $list): string
    {
        $file = $this->resolvePath((string)($list['file'] ?? ''));
        if (!is_readable($file)) {
            throw new \RuntimeException("JSON file not readable: {$file}");
        }
        return file_get_contents($file) ?: '';
    }

    /**
     * @param array<string,mixed> $list
     * @return list<array<string,mixed>>
     */
    public function parse(string $raw, array $list): array
    {
        if (trim($raw) === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid JSON in manual list: ' . json_last_error_msg());
        }
        // Support both a bare array and {"songs": [...]}
        if (isset($data['songs']) && is_array($data['songs'])) {
            $data = $data['songs'];
        }
        $rows = [];
        foreach ($data as $rank => $item) {
            if (!is_array($item)) {
                continue;
            }
            $row = $this->normalizeRow($item, $list, $rank + 1);
            if ($row['artist'] !== '' && $row['title'] !== '') {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,mixed> $list
     * @return array<string,mixed>
     */
    private function normalizeRow(array $item, array $list, int $rank): array
    {
        $title  = trim((string)($item['title']  ?? $item['song'] ?? $item['track'] ?? ''));
        $artist = trim((string)($item['artist'] ?? $item['artist_name'] ?? ''));
        if ($title === '' || $artist === '') {
            return ['artist' => '', 'title' => ''];
        }
        $year = $this->parseYear($item['year'] ?? null);
        $decade = $year ? ($year - ($year % 10)) : null;

        return [
            'source_name' => (string)($list['source_name'] ?? 'Manual'),
            'source_slug' => (string)($list['source_slug'] ?? 'manual-kj'),
            'source_type' => (string)($list['source_type'] ?? 'manual'),
            'station'     => ($list['station'] ?? null) ?: null,
            'market'      => ($list['market']  ?? null) ?: null,
            'list_title'  => (string)($list['list_title'] ?? $list['slug'] ?? 'Manual List'),
            'list_slug'   => (string)($list['list_slug']  ?? $list['slug'] ?? 'manual'),
            'list_type'   => (string)($list['list_type']  ?? 'manual'),
            'year'        => $year ?? ($list['year'] ?? null),
            'decade'      => $decade ?? ($list['decade'] ?? null),
            'genre_hint'  => ($item['genre'] ?? $list['genre_hint'] ?? null) ?: null,
            'url'         => ($item['url'] ?? $list['url'] ?? null) ?: null,
            'rank'        => (int)($item['rank'] ?? $rank),
            'artist'      => $artist,
            'title'       => $title,
            'duo'         => (bool)($item['duo'] ?? false),
            'explicit'    => (bool)($item['explicit'] ?? false),
            'import_tags' => isset($item['tags']) && is_array($item['tags']) ? $item['tags'] : null,
            'notes'       => ($item['notes'] ?? $item['curator_notes'] ?? null) ?: null,
            'raw'         => $item,
        ];
    }

    private function resolvePath(string $path): string
    {
        if ($path === '' || str_starts_with($path, '/')) {
            return $path;
        }
        return rtrim($this->basePath, '/') . '/' . $path;
    }

    private function parseYear(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $y = (int)$value;
        return $y >= 1900 && $y <= 2100 ? $y : null;
    }
}
