<?php

declare(strict_types=1);

namespace PanicMic\Services\CatalogImport;

use PanicMic\Services\CatalogNormalizeService;

/**
 * Adapter for manual CSV files.
 *
 * Supports both the legacy semicolon-delimited format and richer CSVs
 * with headers like:
 *   Title;Artist;Year;Genre;Rank;Source;Source URL;List Title;Tags;Notes;Duo;Explicit;Styles;Languages
 *
 * Header names are lowercased and underscored before matching.
 */
final class ManualCsvAdapter implements SourceAdapter
{
    /** @param list<array<string,mixed>> $lists Source list configs */
    public function __construct(
        private readonly array $lists,
        private readonly string $basePath,
    ) {}

    public function sourceSlug(): string
    {
        return 'manual-csv';
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
            throw new \RuntimeException("CSV file not readable: {$file}");
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

        $delimiter = (string)($list['parser']['delimiter'] ?? ';');

        // Write raw to a temp stream for fgetcsv
        $tmp = tmpfile();
        if (!$tmp) {
            throw new \RuntimeException('Could not create temp file for CSV parse');
        }
        fwrite($tmp, $raw);
        rewind($tmp);

        $rawHeader = fgetcsv($tmp, 0, $delimiter, '"', '\\');
        if (!$rawHeader) {
            fclose($tmp);
            return [];
        }

        // Normalize header names
        $header = array_map(
            static fn (mixed $h) => strtolower(trim(str_replace([' ', '-'], '_', (string)$h))),
            $rawHeader,
        );

        $rows = [];
        $rank = 0;
        while (($row = fgetcsv($tmp, 0, $delimiter, '"', '\\')) !== false) {
            if (count($row) !== count($header)) {
                continue;
            }
            $rank++;
            $assoc = array_combine($header, $row);
            $rows[] = $this->normalizeRow($assoc, $list, $rank);
        }
        fclose($tmp);

        return array_values(array_filter($rows, static fn (array $r): bool => $r['artist'] !== '' && $r['title'] !== ''));
    }

    /**
     * @param array<string,mixed> $assoc
     * @param array<string,mixed> $list
     * @return array<string,mixed>
     */
    private function normalizeRow(array $assoc, array $list, int $rank): array
    {
        $title  = trim((string)($assoc['title']  ?? $assoc['song'] ?? ''));
        $artist = trim((string)($assoc['artist'] ?? ''));
        if ($title === '' || $artist === '') {
            return ['artist' => '', 'title' => ''];
        }

        $year = $this->parseYear($assoc['year'] ?? $assoc['yr'] ?? null);
        $decade = $year ? ($year - ($year % 10)) : null;

        $duo      = $this->truthy($assoc['duo'] ?? false);
        $explicit = $this->truthy($assoc['explicit'] ?? false);

        $styles    = $this->parseList($assoc['styles'] ?? null);
        $languages = $this->parseList($assoc['languages'] ?? null);
        $tags      = $this->parseList($assoc['tags'] ?? $assoc['discovery_tags'] ?? null);

        $genreHint = trim((string)($assoc['genre'] ?? $list['genre_hint'] ?? '')) ?: null;
        if ($genreHint === null && $styles) {
            $genreHint = $styles[0];
        }

        $overrideRank = $assoc['rank'] ?? null;
        $finalRank = $overrideRank !== null && $overrideRank !== '' ? (int)$overrideRank : $rank;

        return [
            'source_name' => (string)($list['source_name'] ?? 'Manual'),
            'source_slug' => (string)($list['source_slug'] ?? 'manual-kj'),
            'source_type' => (string)($list['source_type'] ?? 'manual'),
            'station'     => ($list['station'] ?? null) ?: null,
            'market'      => ($list['market']  ?? null) ?: null,
            'list_title'  => (string)($list['list_title'] ?? $list['slug'] ?? 'Manual List'),
            'list_slug'   => (string)($list['list_slug']  ?? $list['slug'] ?? 'manual'),
            'list_type'   => (string)($list['list_type']  ?? 'manual'),
            'year'        => $year ?? (int)($list['year'] ?? 0) ?: null,
            'decade'      => $decade ?? ($list['decade'] ?? null),
            'genre_hint'  => $genreHint,
            'url'         => ($assoc['source_url'] ?? $assoc['url'] ?? $list['url'] ?? null) ?: null,
            'rank'        => $finalRank,
            'artist'      => $artist,
            'title'       => $title,
            // shared_songs fields
            'duo'         => $duo,
            'explicit'    => $explicit,
            'styles'      => $styles,
            'languages'   => $languages,
            // extra metadata
            'import_tags' => $tags,
            'notes'       => trim((string)($assoc['notes'] ?? $assoc['curator_notes'] ?? '')) ?: null,
            'raw'         => $assoc,
        ];
    }

    private function resolvePath(string $path): string
    {
        if ($path === '') {
            return '';
        }
        if (str_starts_with($path, '/')) {
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

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    /** @return list<string>|null */
    private function parseList(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        $parts = preg_split('/[,;|]+/', (string)$value) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts)));
        return $parts ?: null;
    }
}
