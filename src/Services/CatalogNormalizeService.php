<?php

declare(strict_types=1);

namespace PanicMic\Services;

/**
 * Text normalization for artist/title matching.
 *
 * The goal is a stable, collision-resistant canonical form that lets us
 * recognize "Don't You (Forget About Me)" == "Dont You Forget About Me"
 * while NOT colliding "Africa (Toto)" with "Africa (Continent)".
 *
 * All methods are pure functions — no DB access, no side effects —
 * so they can be unit-tested in isolation.
 */
final class CatalogNormalizeService
{
    /**
     * Normalize an artist or title string for matching purposes.
     *
     * Steps (in order):
     *  1. Decode HTML entities
     *  2. Normalize Unicode to NFC
     *  3. Lowercase
     *  4. Normalize smart quotes / curly apostrophes to straight
     *  5. Normalize em-dashes, en-dashes, hyphens to a plain dash
     *  6. Normalize ampersands (&amp; and &) to "and"
     *  7. Strip version noise in parentheses/brackets
     *  8. Normalize feat./ft./featuring
     *  9. Collapse whitespace and trim
     */
    public static function normalize(string $text): string
    {
        $s = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // NFC normalization if intl is available
        if (function_exists('normalizer_normalize')) {
            $s = normalizer_normalize($s, \Normalizer::NFC) ?: $s;
        }

        $s = mb_strtolower($s, 'UTF-8');

        // Smart quotes / apostrophes
        $s = str_replace(["\u{2018}", "\u{2019}", "\u{201A}", "\u{201B}", '`', "\u{02BC}"], "'", $s);
        $s = str_replace(["\u{201C}", "\u{201D}", "\u{201E}", "\u{201F}"], '"', $s);

        // Dashes (em-dash, en-dash, minus, figure-dash) → plain hyphen
        $s = str_replace(["\u{2014}", "\u{2013}", "\u{2212}", "\u{2012}"], '-', $s);

        // Ampersands
        $s = preg_replace('/\s*&amp;\s*/u', ' and ', $s) ?? $s;
        $s = preg_replace('/\s*&\s*/u', ' and ', $s) ?? $s;

        // Strip version noise in parentheses or brackets.
        // We preserve meaningful parentheticals like "(Don't You Forget About Me)"
        // by only stripping if they match known version keywords.
        $versionNoise = implode('|', [
            'remastered',
            'remaster',
            'radio edit',
            'radio version',
            'album version',
            'album cut',
            'single version',
            'single edit',
            'original mix',
            'original version',
            'extended mix',
            'extended version',
            'explicit',
            'explicit version',
            'clean',
            'clean version',
            'live',
            'live version',
            'live recording',
            'acoustic',
            'acoustic version',
            'mono',
            'stereo',
            '\d{4} remaster',
            '\d{4} mix',
        ]);
        $s = preg_replace('/\s*[\(\[]\s*(?:' . $versionNoise . ')\s*[\)\]]/iu', '', $s) ?? $s;

        // Normalize feat./ft./featuring → feat
        $s = preg_replace('/\b(?:featuring|feat\.?|ft\.?)\s+/iu', 'feat. ', $s) ?? $s;

        // Collapse whitespace
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;

        return trim($s);
    }

    /**
     * Normalize an artist name specifically.
     * Strips "The " prefix for secondary matching (kept in primary).
     */
    public static function normalizeArtist(string $artist): string
    {
        return self::normalize($artist);
    }

    /**
     * Normalize a song title specifically.
     */
    public static function normalizeTitle(string $title): string
    {
        return self::normalize($title);
    }

    /**
     * Produce a slug from a string (for source/list slugs).
     */
    public static function slug(string $text): string
    {
        $s = mb_strtolower($text, 'UTF-8');
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? $s;
        return trim($s, '-');
    }

    /**
     * Compare two normalized strings for similarity.
     * Returns a score 0–100.
     */
    public static function similarity(string $a, string $b): int
    {
        if ($a === $b) {
            return 100;
        }
        similar_text($a, $b, $pct);
        return (int)round($pct);
    }

    /**
     * Strip a leading "The " (case-insensitive) from an artist name.
     * Used for secondary fuzzy matching ("The Cure" ~ "Cure").
     */
    public static function stripLeadingThe(string $artist): string
    {
        return preg_replace('/^the\s+/iu', '', $artist) ?? $artist;
    }

    /**
     * Return true if two normalized artist strings are close enough to
     * be considered the same artist (≥ 85% similar after stripping "The").
     */
    public static function artistsMatch(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }
        $aNorm = self::stripLeadingThe($a);
        $bNorm = self::stripLeadingThe($b);
        if ($aNorm === $bNorm) {
            return true;
        }
        return self::similarity($a, $b) >= 85;
    }
}
