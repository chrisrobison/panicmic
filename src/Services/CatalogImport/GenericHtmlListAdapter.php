<?php

declare(strict_types=1);

namespace PanicMic\Services\CatalogImport;

/**
 * Generic parser helpers for ranked HTML/text lists.
 *
 * Handles common formats:
 *   1. Artist - Song
 *   1 Artist - Song
 *   1) Artist - Song
 *   1) Artist "Song"
 *   Artist - Song    (unranked)
 *
 * Also attempts to parse HTML <ol>/<li> and table row formats.
 *
 * All public parse* methods are pure functions — no DB, no HTTP.
 * They can be unit-tested in isolation.
 */
final class GenericHtmlListAdapter
{
    /** Minimum confidence to include a parsed row (0-100). */
    private const MIN_CONFIDENCE = 30;

    /**
     * Parse a ranked text/HTML block into candidate rows.
     *
     * @param array<string,mixed> $list  Source list config
     * @return list<array{rank:int|null,artist:string,title:string,confidence:int,raw_line:string}>
     */
    public static function parseRankedBlock(string $content, array $list): array
    {
        // Try HTML parse first if it looks like HTML
        if (str_contains($content, '<') && str_contains($content, '>')) {
            $rows = self::parseHtml($content, $list);
            if ($rows !== []) {
                return $rows;
            }
        }
        return self::parseText($content, $list);
    }

    /**
     * Parse HTML for ranked lists.
     *
     * Strategy: strip HTML tags to get text lines, then try ranked text parser.
     * Also attempts <ol><li> and <table><tr> extraction.
     *
     * @param array<string,mixed> $list
     * @return list<array{rank:int|null,artist:string,title:string,confidence:int,raw_line:string}>
     */
    public static function parseHtml(string $html, array $list): array
    {
        // Try ordered list items first
        $rows = self::parseOlItems($html, $list);
        if ($rows !== []) {
            return $rows;
        }

        // Try table rows
        $rows = self::parseTableRows($html, $list);
        if ($rows !== []) {
            return $rows;
        }

        // Fall back to stripping all tags and parsing as text
        $text = self::htmlToText($html);
        return self::parseText($text, $list);
    }

    /**
     * Extract <li> items from an <ol> and parse as artist - title.
     *
     * @param array<string,mixed> $list
     * @return list<array{rank:int|null,artist:string,title:string,confidence:int,raw_line:string}>
     */
    public static function parseOlItems(string $html, array $list): array
    {
        $format = (string)($list['parser']['format'] ?? 'artist-title');

        // Find <ol> blocks
        if (!preg_match_all('/<li[^>]*>(.*?)<\/li>/si', $html, $matches)) {
            return [];
        }

        $rows = [];
        $rank = 0;
        foreach ($matches[1] as $li) {
            $text = trim(self::htmlToText($li));
            if ($text === '') {
                continue;
            }
            $rank++;

            // Strip leading rank numbers that may appear inside <li>
            $clean = preg_replace('/^\d+[\.\)\s]+/', '', $text);
            $clean = trim((string)$clean);

            $parsed = self::parseArtistTitle($clean, $format);
            if ($parsed !== null && $parsed['confidence'] >= self::MIN_CONFIDENCE) {
                $parsed['rank'] = $rank;
                $parsed['raw_line'] = $text;
                $rows[] = $parsed;
            }
        }
        return $rows;
    }

    /**
     * Extract rows from HTML tables.
     *
     * @param array<string,mixed> $list
     * @return list<array{rank:int|null,artist:string,title:string,confidence:int,raw_line:string}>
     */
    public static function parseTableRows(string $html, array $list): array
    {
        $format = (string)($list['parser']['format'] ?? 'artist-title');
        if (!preg_match_all('/<tr[^>]*>(.*?)<\/tr>/si', $html, $trMatches)) {
            return [];
        }

        $rows = [];
        $rank = 0;
        foreach ($trMatches[1] as $tr) {
            // Extract all cells
            preg_match_all('/<t[dh][^>]*>(.*?)<\/t[dh]>/si', $tr, $tdMatches);
            $cells = array_map(
                static fn (string $c) => trim(self::htmlToText($c)),
                $tdMatches[1],
            );
            $cells = array_values(array_filter($cells, static fn (string $c) => $c !== ''));
            if (count($cells) < 2) {
                continue;
            }

            // Try to detect rank column
            $rankVal = null;
            $firstIsRank = preg_match('/^\d+$/', $cells[0]);
            if ($firstIsRank) {
                $rankVal = (int)$cells[0];
                $cells = array_slice($cells, 1);
            }

            if (count($cells) < 2) {
                continue;
            }

            // Two-column: [artist, title] or [title, artist]
            $joined = implode(' - ', array_slice($cells, 0, 2));
            $parsed = self::parseArtistTitle($joined, $format);
            if ($parsed !== null && $parsed['confidence'] >= self::MIN_CONFIDENCE) {
                $rank++;
                $parsed['rank'] = $rankVal ?? $rank;
                $parsed['raw_line'] = implode(' | ', $cells);
                $rows[] = $parsed;
            }
        }
        return $rows;
    }

    /**
     * Parse plain-text ranked list.
     *
     * @param array<string,mixed> $list
     * @return list<array{rank:int|null,artist:string,title:string,confidence:int,raw_line:string}>
     */
    public static function parseText(string $text, array $list): array
    {
        $format = (string)($list['parser']['format'] ?? 'artist-title');
        $lines = preg_split('/\r?\n/', $text) ?: [];
        $rows = [];
        $rank = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strlen($line) < 4) {
                continue;
            }

            // Extract rank prefix
            $rankVal = null;
            if (preg_match('/^(\d+)[\.\)\s:]+(.*)/', $line, $m)) {
                $rankVal = (int)$m[1];
                $line = trim($m[2]);
            }

            if ($line === '') {
                continue;
            }

            $parsed = self::parseArtistTitle($line, $format);
            if ($parsed !== null && $parsed['confidence'] >= self::MIN_CONFIDENCE) {
                $rank++;
                $parsed['rank'] = $rankVal ?? $rank;
                $parsed['raw_line'] = $line;
                $rows[] = $parsed;
            }
        }
        return $rows;
    }

    /**
     * Split "Artist - Title" or "Title - Artist" into parts.
     *
     * @return array{rank:int|null,artist:string,title:string,confidence:int,raw_line:string}|null
     */
    public static function parseArtistTitle(string $line, string $format = 'artist-title'): ?array
    {
        $line = trim($line);
        if ($line === '') {
            return null;
        }

        // Try separator patterns: ` - `, ` – `, ` — `, `: `, ` / `
        $separators = [' - ', ' – ', ' — ', ': ', ' / ', ' | '];
        $parts = null;
        foreach ($separators as $sep) {
            if (str_contains($line, $sep)) {
                [$a, $b] = explode($sep, $line, 2);
                $parts = [trim($a), trim($b)];
                break;
            }
        }

        // Try quoted title: Artist "Title"
        if ($parts === null && preg_match('/^(.+?)\s+["""](.+?)[""]$/', $line, $m)) {
            $parts = [trim($m[1]), trim($m[2])];
        }

        if ($parts === null || $parts[0] === '' || $parts[1] === '') {
            // Can't split — return the whole line as title with unknown artist
            return null;
        }

        [$left, $right] = $parts;
        $confidence = 70;

        if ($format === 'title-artist') {
            [$artist, $title] = [$right, $left];
        } else {
            [$artist, $title] = [$left, $right];
        }

        // Boost confidence if both parts are non-empty (already guaranteed here)
        $confidence = 80;

        return [
            'rank'       => null,
            'artist'     => $artist,
            'title'      => $title,
            'confidence' => $confidence,
            'raw_line'   => $line,
        ];
    }

    /**
     * Strip HTML tags and decode entities.
     */
    public static function htmlToText(string $html): string
    {
        // Replace block-level tags with newlines
        $html = preg_replace('/<\/(p|div|br|li|h[1-6]|tr)\s*>/i', "\n", $html) ?? $html;
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
        $html = strip_tags($html);
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = preg_replace('/\s+/', ' ', $html) ?? $html;
        return trim($html);
    }
}
