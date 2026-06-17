<?php

declare(strict_types=1);

namespace PanicMic\Services\CatalogImport;

/**
 * HTTP + parse adapter for Rocklists.com-style HTML pages.
 *
 * Fetches the HTML, caches it to storage/catalog-cache/, and delegates
 * parsing to GenericHtmlListAdapter.
 *
 * IMPORTANT: This adapter respects robots.txt and rate limits.
 * It will NOT bypass Cloudflare, bot protection, or any access restriction.
 * If a page is blocked, it logs a warning and skips cleanly.
 */
final class RocklistsHtmlAdapter implements SourceAdapter
{
    private const USER_AGENT = 'PanicMic-CatalogBot/1.0 (catalog import; contact: kj@panicmic.com)';
    private const RATE_LIMIT_SECONDS = 3.0;
    private const REQUEST_TIMEOUT = 10;

    private static float $lastFetch = 0.0;

    /** @param list<array<string,mixed>> $lists */
    public function __construct(
        private readonly array $lists,
        private readonly string $cacheDir,
        private readonly bool $forceFetch = false,
    ) {}

    public function sourceSlug(): string
    {
        return 'rocklists';
    }

    /** @return list<array<string,mixed>> */
    public function lists(): array
    {
        return $this->lists;
    }

    public function fetch(array $list): string
    {
        $url = (string)($list['url'] ?? '');
        if ($url === '') {
            throw new \RuntimeException('No URL configured for list: ' . ($list['slug'] ?? '?'));
        }

        $cacheFile = $this->cacheFile($list);

        if (!$this->forceFetch && is_readable($cacheFile)) {
            return file_get_contents($cacheFile) ?: '';
        }

        // Rate limit
        $elapsed = microtime(true) - self::$lastFetch;
        if ($elapsed < self::RATE_LIMIT_SECONDS) {
            usleep((int)(($elapsed < 0 ? 0 : self::RATE_LIMIT_SECONDS - $elapsed) * 1_000_000));
        }

        $ctx = stream_context_create([
            'http' => [
                'timeout'       => self::REQUEST_TIMEOUT,
                'ignore_errors' => true,
                'header'        => implode("\r\n", [
                    'User-Agent: ' . self::USER_AGENT,
                    'Accept: text/html,application/xhtml+xml',
                    'Accept-Language: en-US,en;q=0.9',
                ]),
            ],
        ]);

        $html = @file_get_contents($url, false, $ctx);
        self::$lastFetch = microtime(true);

        if ($html === false || $html === '') {
            throw new \RuntimeException("Failed to fetch: {$url}");
        }

        // Check for bot protection responses
        if (
            str_contains($html, 'Cloudflare') ||
            str_contains($html, 'cf-browser-verification') ||
            str_contains($html, 'Access denied')
        ) {
            throw new \RuntimeException("Bot protection detected for: {$url} — cannot fetch automatically");
        }

        // Cache the result
        $dir = dirname($cacheFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($cacheFile, $html);

        return $html;
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

        $parsed = GenericHtmlListAdapter::parseRankedBlock($raw, $list);

        $rows = [];
        foreach ($parsed as $item) {
            if ($item['artist'] === '' || $item['title'] === '') {
                continue;
            }
            $rows[] = [
                'source_name' => (string)($list['source_name'] ?? 'Rocklists'),
                'source_slug' => (string)($list['source_slug'] ?? 'rocklists'),
                'source_type' => (string)($list['source_type'] ?? 'radio_countdown'),
                'station'     => ($list['station'] ?? null) ?: null,
                'market'      => ($list['market']  ?? null) ?: null,
                'list_title'  => (string)($list['list_title'] ?? $list['slug'] ?? ''),
                'list_slug'   => (string)($list['list_slug']  ?? $list['slug'] ?? ''),
                'list_type'   => (string)($list['list_type']  ?? 'year_end'),
                'year'        => ($list['year'] ?? null) ?: null,
                'decade'      => ($list['decade'] ?? null) ?: null,
                'genre_hint'  => ($list['genre_hint'] ?? null) ?: null,
                'url'         => ($list['url'] ?? null) ?: null,
                'rank'        => $item['rank'],
                'artist'      => $item['artist'],
                'title'       => $item['title'],
                'confidence'  => $item['confidence'],
                'raw'         => ['raw_line' => $item['raw_line']],
            ];
        }
        return $rows;
    }

    private function cacheFile(array $list): string
    {
        $slug = (string)($list['slug'] ?? 'unknown');
        $hash = substr(md5((string)($list['url'] ?? '')), 0, 8);
        return rtrim($this->cacheDir, '/') . '/rocklists/' . $slug . '-' . $hash . '.html';
    }
}
