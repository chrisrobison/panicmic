<?php

declare(strict_types=1);

namespace PanicMic\Services\CatalogImport;

/**
 * Interface for all curated catalog source adapters.
 *
 * Each adapter knows how to fetch and parse one kind of source
 * (CSV file, JSON file, HTML page, Last.fm API, etc.) and returns
 * normalized candidate rows that CatalogImportService can process.
 *
 * Candidate row shape (all fields optional except artist + title):
 * [
 *   'source_name'  => string,
 *   'source_slug'  => string,
 *   'source_type'  => string,
 *   'station'      => string|null,
 *   'market'       => string|null,
 *   'list_title'   => string,
 *   'list_slug'    => string,
 *   'list_type'    => string,
 *   'year'         => int|null,
 *   'decade'       => int|null,
 *   'genre_hint'   => string|null,
 *   'url'          => string|null,
 *   'rank'         => int|null,
 *   'artist'       => string,
 *   'title'        => string,
 *   'raw'          => array<string,mixed>,
 * ]
 */
interface SourceAdapter
{
    /**
     * The unique slug identifying this adapter/source combination.
     */
    public function sourceSlug(): string;

    /**
     * Returns the configured lists this adapter knows about.
     *
     * @return list<array<string,mixed>>
     */
    public function lists(): array;

    /**
     * Fetches raw source content for one list configuration.
     *
     * For file-based adapters, returns the file contents.
     * For HTTP adapters, returns the HTTP response body.
     * May return a cached copy if available and --force-fetch not set.
     *
     * @param array<string,mixed> $list One entry from lists()
     */
    public function fetch(array $list): string;

    /**
     * Parses raw source content into normalized candidate rows.
     *
     * @param array<string,mixed> $list One entry from lists()
     * @return list<array<string,mixed>>
     */
    public function parse(string $raw, array $list): array;
}
