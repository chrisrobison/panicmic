<?php

declare(strict_types=1);

namespace NextUp\Support;

/**
 * Minimal pure-PHP QR Code SVG generator.
 *
 * Scope:
 *   - Byte (8-bit) mode encoding — works for any UTF-8 string.
 *   - Error correction level L (Low, ~7% recovery).
 *   - Versions 1 through 10 (matrix sizes 21×21 through 57×57).
 *     This covers up to 271 bytes of payload, which is enough for any
 *     reasonable URL.
 *
 * Output:
 *   - Inline <svg>, no external resources, no inline styles, no scripts.
 *     Safe to embed under a strict CSP.
 *
 * References:
 *   - ISO/IEC 18004 (the QR Code spec).
 *   - https://www.thonky.com/qr-code-tutorial/ — readable companion guide.
 */
final class QrCode
{
    /** Byte-mode capacity at ECC-L per version (data codewords usable for text). */
    private const BYTE_CAPACITY = [
        1 => 17, 2 => 32, 3 => 53, 4 => 78, 5 => 106,
        6 => 134, 7 => 154, 8 => 192, 9 => 230, 10 => 271,
    ];

    /** Total data codewords per version at ECC-L (data, before RS expansion). */
    private const DATA_CODEWORDS = [
        1 => 19, 2 => 34, 3 => 55, 4 => 80, 5 => 108,
        6 => 136, 7 => 156, 8 => 194, 9 => 232, 10 => 274,
    ];

    /**
     * Error-correction layout at ECC-L per version.
     * Each entry is [ecc_per_block, group1_blocks, group1_data, group2_blocks, group2_data].
     * For single-group versions (1–9 at ECC-L) group2_blocks = 0.
     */
    private const ECC_LAYOUT = [
        1  => [7,  1, 19, 0, 0],
        2  => [10, 1, 34, 0, 0],
        3  => [15, 1, 55, 0, 0],
        4  => [20, 1, 80, 0, 0],
        5  => [26, 1, 108, 0, 0],
        6  => [18, 2, 68, 0, 0],
        7  => [20, 2, 78, 0, 0],
        8  => [24, 2, 97, 0, 0],
        9  => [30, 2, 116, 0, 0],
        10 => [18, 2, 68, 2, 69],
    ];

    /** Alignment pattern center coordinates per version. */
    private const ALIGN_POSITIONS = [
        1  => [],
        2  => [6, 18],
        3  => [6, 22],
        4  => [6, 26],
        5  => [6, 30],
        6  => [6, 34],
        7  => [6, 22, 38],
        8  => [6, 24, 42],
        9  => [6, 26, 46],
        10 => [6, 28, 50],
    ];

    /** GF(256) exponentiation table (precomputed lazily). */
    private static ?array $gfExp = null;
    /** GF(256) logarithm table (precomputed lazily). */
    private static ?array $gfLog = null;

    /**
     * Render the input text as an inline SVG QR code.
     *
     * @param string $text       The data to encode (URL, etc.). Max ~271 bytes.
     * @param int    $pixelSize  Approximate target size in CSS pixels (used to pick an integer module size).
     * @param int    $quietZone  Number of blank modules around the QR (spec recommends 4).
     */
    public static function svg(string $text, int $pixelSize = 256, int $quietZone = 4): string
    {
        $matrix = self::encode($text);
        $size = count($matrix);
        $totalModules = $size + 2 * $quietZone;
        $unit = max(1, (int)floor($pixelSize / $totalModules));
        $svgSize = $unit * $totalModules;

        $rects = '';
        // Collapse contiguous dark modules per row into single <rect> elements
        // for ~5x smaller SVG output without changing how it scans.
        for ($y = 0; $y < $size; $y++) {
            $runStart = null;
            for ($x = 0; $x <= $size; $x++) {
                $dark = $x < $size && $matrix[$y][$x];
                if ($dark && $runStart === null) {
                    $runStart = $x;
                } elseif (!$dark && $runStart !== null) {
                    $rx = ($runStart + $quietZone) * $unit;
                    $ry = ($y + $quietZone) * $unit;
                    $rw = ($x - $runStart) * $unit;
                    $rects .= '<rect x="' . $rx . '" y="' . $ry . '" width="' . $rw . '" height="' . $unit . '"/>';
                    $runStart = null;
                }
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $svgSize . ' ' . $svgSize
            . '" width="' . $svgSize . '" height="' . $svgSize
            . '" role="img" aria-label="QR code" shape-rendering="crispEdges">'
            . '<rect width="100%" height="100%" fill="#fff"/>'
            . '<g fill="#000">' . $rects . '</g>'
            . '</svg>';
    }

    /**
     * Encode the text into a 2-D matrix of booleans (true = dark module).
     *
     * @return array<int, array<int, bool>>
     */
    private static function encode(string $text): array
    {
        $len = strlen($text);
        $version = null;
        foreach (self::BYTE_CAPACITY as $v => $cap) {
            if ($len <= $cap) {
                $version = $v;
                break;
            }
        }
        if ($version === null) {
            throw new \InvalidArgumentException('QR text too long (>271 bytes)');
        }

        // ---- 1. Build the data bit-stream ------------------------------------
        $charCountBits = $version <= 9 ? 8 : 16;
        $bits = '0100' . str_pad(decbin($len), $charCountBits, '0', STR_PAD_LEFT);
        for ($i = 0; $i < $len; $i++) {
            $bits .= str_pad(decbin(ord($text[$i])), 8, '0', STR_PAD_LEFT);
        }
        $totalDataBits = self::DATA_CODEWORDS[$version] * 8;
        // Terminator (up to 4 zeros).
        $bits .= str_repeat('0', min(4, max(0, $totalDataBits - strlen($bits))));
        // Pad to byte boundary.
        if (strlen($bits) % 8 !== 0) {
            $bits .= str_repeat('0', 8 - (strlen($bits) % 8));
        }
        // Pad bytes to fill capacity, alternating 0xEC and 0x11.
        $padBytes = ['11101100', '00010001'];
        $i = 0;
        while (strlen($bits) < $totalDataBits) {
            $bits .= $padBytes[$i % 2];
            $i++;
        }

        // ---- 2. Split into blocks, apply Reed-Solomon ECC --------------------
        [$eccLen, $g1Blocks, $g1Data, $g2Blocks, $g2Data] = self::ECC_LAYOUT[$version];
        $dataBlocks = [];
        $eccBlocks = [];
        $byteOffset = 0;
        $allBytes = [];
        for ($b = 0, $end = strlen($bits); $b < $end; $b += 8) {
            $allBytes[] = bindec(substr($bits, $b, 8));
        }
        for ($b = 0; $b < $g1Blocks; $b++) {
            $block = array_slice($allBytes, $byteOffset, $g1Data);
            $byteOffset += $g1Data;
            $dataBlocks[] = $block;
            $eccBlocks[] = self::reedSolomon($block, $eccLen);
        }
        for ($b = 0; $b < $g2Blocks; $b++) {
            $block = array_slice($allBytes, $byteOffset, $g2Data);
            $byteOffset += $g2Data;
            $dataBlocks[] = $block;
            $eccBlocks[] = self::reedSolomon($block, $eccLen);
        }

        // ---- 3. Interleave data + ecc codewords ------------------------------
        $maxDataLen = max(array_map('count', $dataBlocks));
        $interleaved = [];
        for ($i = 0; $i < $maxDataLen; $i++) {
            foreach ($dataBlocks as $block) {
                if (isset($block[$i])) {
                    $interleaved[] = $block[$i];
                }
            }
        }
        for ($i = 0; $i < $eccLen; $i++) {
            foreach ($eccBlocks as $block) {
                $interleaved[] = $block[$i];
            }
        }

        // Final bit stream from interleaved codewords.
        $finalBits = '';
        foreach ($interleaved as $cw) {
            $finalBits .= str_pad(decbin($cw), 8, '0', STR_PAD_LEFT);
        }

        // ---- 4. Build the matrix --------------------------------------------
        $size = 17 + 4 * $version;
        // -1 means "not yet written" so we can tell function modules apart later.
        $matrix = array_fill(0, $size, array_fill(0, $size, -1));
        $reserved = array_fill(0, $size, array_fill(0, $size, false));

        self::placeFinder($matrix, $reserved, 0, 0);
        self::placeFinder($matrix, $reserved, $size - 7, 0);
        self::placeFinder($matrix, $reserved, 0, $size - 7);
        self::placeSeparators($matrix, $reserved, $size);
        self::placeTiming($matrix, $reserved, $size);
        self::placeAlignment($matrix, $reserved, $size, self::ALIGN_POSITIONS[$version]);
        self::reserveFormatArea($reserved, $size);
        // Dark module (always on, at (8, size-8)).
        $matrix[$size - 8][8] = 1;
        $reserved[$size - 8][8] = true;
        // Version info area for versions 7+ (not used here; we cap at 10).
        if ($version >= 7) {
            self::placeVersionInfo($matrix, $reserved, $size, $version);
        }
        self::placeData($matrix, $reserved, $size, $finalBits);

        // ---- 5. Pick best mask + write format info ---------------------------
        $bestMask = 0;
        $bestPenalty = PHP_INT_MAX;
        $bestMatrix = null;
        for ($m = 0; $m < 8; $m++) {
            $candidate = self::applyMask($matrix, $reserved, $size, $m);
            self::writeFormatInfo($candidate, $size, $m);
            $penalty = self::maskPenalty($candidate, $size);
            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $bestMask = $m;
                $bestMatrix = $candidate;
            }
        }

        // Convert -1/0/1 to bool.
        $result = [];
        foreach ($bestMatrix as $row) {
            $result[] = array_map(fn ($c) => $c === 1, $row);
        }
        return $result;
    }

    /* -------------------------------------------------------------------------
     * Functional patterns
     * ---------------------------------------------------------------------- */

    private static function placeFinder(array &$m, array &$r, int $row, int $col): void
    {
        for ($dr = -1; $dr <= 7; $dr++) {
            for ($dc = -1; $dc <= 7; $dc++) {
                $rr = $row + $dr;
                $cc = $col + $dc;
                if ($rr < 0 || $cc < 0 || $rr >= count($m) || $cc >= count($m)) {
                    continue;
                }
                $inside = ($dr >= 0 && $dr <= 6 && $dc >= 0 && $dc <= 6);
                if (!$inside) {
                    continue;
                }
                $edge = ($dr === 0 || $dr === 6 || $dc === 0 || $dc === 6);
                $inner = ($dr >= 2 && $dr <= 4 && $dc >= 2 && $dc <= 4);
                $m[$rr][$cc] = ($edge || $inner) ? 1 : 0;
                $r[$rr][$cc] = true;
            }
        }
    }

    private static function placeSeparators(array &$m, array &$r, int $size): void
    {
        for ($i = 0; $i < 8; $i++) {
            // Top-left
            if ($i < 8 && $m[7][$i] === -1) { $m[7][$i] = 0; $r[7][$i] = true; }
            if ($m[$i][7] === -1) { $m[$i][7] = 0; $r[$i][7] = true; }
            // Top-right
            if ($m[7][$size - 1 - $i] === -1) { $m[7][$size - 1 - $i] = 0; $r[7][$size - 1 - $i] = true; }
            if ($m[$i][$size - 8] === -1) { $m[$i][$size - 8] = 0; $r[$i][$size - 8] = true; }
            // Bottom-left
            if ($m[$size - 8][$i] === -1) { $m[$size - 8][$i] = 0; $r[$size - 8][$i] = true; }
            if ($m[$size - 1 - $i][7] === -1) { $m[$size - 1 - $i][7] = 0; $r[$size - 1 - $i][7] = true; }
        }
    }

    private static function placeTiming(array &$m, array &$r, int $size): void
    {
        for ($i = 8; $i < $size - 8; $i++) {
            $bit = ($i % 2 === 0) ? 1 : 0;
            if ($m[6][$i] === -1) { $m[6][$i] = $bit; $r[6][$i] = true; }
            if ($m[$i][6] === -1) { $m[$i][6] = $bit; $r[$i][6] = true; }
        }
    }

    private static function placeAlignment(array &$m, array &$r, int $size, array $positions): void
    {
        foreach ($positions as $row) {
            foreach ($positions as $col) {
                // Skip only the three centers that fall inside a finder
                // pattern (top-left, top-right, bottom-left). Alignment
                // CAN overlap the timing pattern at V7+ — and is expected
                // to, since the position-adjustment grid is regular.
                $inTopLeft     = $row < 7 && $col < 7;
                $inTopRight    = $row < 7 && $col >= $size - 7;
                $inBottomLeft  = $row >= $size - 7 && $col < 7;
                if ($inTopLeft || $inTopRight || $inBottomLeft) {
                    continue;
                }
                for ($dr = -2; $dr <= 2; $dr++) {
                    for ($dc = -2; $dc <= 2; $dc++) {
                        $rr = $row + $dr;
                        $cc = $col + $dc;
                        $edge = (abs($dr) === 2 || abs($dc) === 2);
                        $center = ($dr === 0 && $dc === 0);
                        $m[$rr][$cc] = ($edge || $center) ? 1 : 0;
                        $r[$rr][$cc] = true;
                    }
                }
            }
        }
    }

    private static function reserveFormatArea(array &$r, int $size): void
    {
        // Top-left format strip
        for ($i = 0; $i <= 8; $i++) {
            if ($i !== 6) {
                $r[8][$i] = true;
                $r[$i][8] = true;
            }
        }
        // Top-right + bottom-left strips
        for ($i = 0; $i < 8; $i++) {
            $r[8][$size - 1 - $i] = true;
            $r[$size - 1 - $i][8] = true;
        }
    }

    private static function placeVersionInfo(array &$m, array &$r, int $size, int $version): void
    {
        $bits = self::versionInformationBits($version);
        for ($i = 0; $i < 18; $i++) {
            $bit = ($bits >> $i) & 1;
            $row = (int)floor($i / 3);
            $col = $size - 11 + ($i % 3);
            $m[$row][$col] = $bit;
            $r[$row][$col] = true;
            $m[$col][$row] = $bit;
            $r[$col][$row] = true;
        }
    }

    private static function versionInformationBits(int $version): int
    {
        $data = $version;
        $g = 0x1F25;
        $bits = $data << 12;
        for ($i = 17; $i >= 12; $i--) {
            if (($bits >> $i) & 1) {
                $bits ^= $g << ($i - 12);
            }
        }
        return ($data << 12) | $bits;
    }

    /* -------------------------------------------------------------------------
     * Data placement (zigzag from bottom-right)
     * ---------------------------------------------------------------------- */

    private static function placeData(array &$m, array &$r, int $size, string $bits): void
    {
        $bitIdx = 0;
        $len = strlen($bits);
        $upward = true;
        for ($col = $size - 1; $col > 0; $col -= 2) {
            // Skip the vertical timing column.
            if ($col === 6) {
                $col--;
            }
            for ($i = 0; $i < $size; $i++) {
                $row = $upward ? $size - 1 - $i : $i;
                for ($dx = 0; $dx < 2; $dx++) {
                    $c = $col - $dx;
                    if (!$r[$row][$c] && $m[$row][$c] === -1) {
                        $m[$row][$c] = $bitIdx < $len ? (int)$bits[$bitIdx] : 0;
                        $bitIdx++;
                    }
                }
            }
            $upward = !$upward;
        }
    }

    /* -------------------------------------------------------------------------
     * Masking
     * ---------------------------------------------------------------------- */

    private static function maskBit(int $row, int $col, int $pattern): bool
    {
        return match ($pattern) {
            0 => ($row + $col) % 2 === 0,
            1 => $row % 2 === 0,
            2 => $col % 3 === 0,
            3 => ($row + $col) % 3 === 0,
            4 => ((int)floor($row / 2) + (int)floor($col / 3)) % 2 === 0,
            5 => (($row * $col) % 2) + (($row * $col) % 3) === 0,
            6 => ((($row * $col) % 2) + (($row * $col) % 3)) % 2 === 0,
            7 => ((($row + $col) % 2) + (($row * $col) % 3)) % 2 === 0,
            default => false,
        };
    }

    /**
     * Apply a mask to a copy of $matrix. Reserved (function-pattern) modules
     * are left untouched.
     */
    private static function applyMask(array $matrix, array $reserved, int $size, int $pattern): array
    {
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if (!$reserved[$r][$c] && $matrix[$r][$c] !== -1 && self::maskBit($r, $c, $pattern)) {
                    $matrix[$r][$c] ^= 1;
                }
            }
        }
        return $matrix;
    }

    /** Compute the standard 4-criteria mask penalty per ISO/IEC 18004 §7.8.3.2. */
    private static function maskPenalty(array $m, int $size): int
    {
        $penalty = 0;

        // 1. Runs of 5+ same-colour modules in a row or column.
        for ($r = 0; $r < $size; $r++) {
            $runColor = -1;
            $runLen = 0;
            for ($c = 0; $c < $size; $c++) {
                $v = $m[$r][$c];
                if ($v === $runColor) {
                    $runLen++;
                } else {
                    if ($runLen >= 5) {
                        $penalty += 3 + ($runLen - 5);
                    }
                    $runColor = $v;
                    $runLen = 1;
                }
            }
            if ($runLen >= 5) {
                $penalty += 3 + ($runLen - 5);
            }
        }
        for ($c = 0; $c < $size; $c++) {
            $runColor = -1;
            $runLen = 0;
            for ($r = 0; $r < $size; $r++) {
                $v = $m[$r][$c];
                if ($v === $runColor) {
                    $runLen++;
                } else {
                    if ($runLen >= 5) {
                        $penalty += 3 + ($runLen - 5);
                    }
                    $runColor = $v;
                    $runLen = 1;
                }
            }
            if ($runLen >= 5) {
                $penalty += 3 + ($runLen - 5);
            }
        }

        // 2. 2x2 same-colour blocks.
        for ($r = 0; $r < $size - 1; $r++) {
            for ($c = 0; $c < $size - 1; $c++) {
                $v = $m[$r][$c];
                if ($v === $m[$r][$c + 1] && $v === $m[$r + 1][$c] && $v === $m[$r + 1][$c + 1]) {
                    $penalty += 3;
                }
            }
        }

        // 3. Finder-like patterns (1011101 surrounded by 4-module quiet zone).
        $pat1 = [1, 0, 1, 1, 1, 0, 1, 0, 0, 0, 0];
        $pat2 = [0, 0, 0, 0, 1, 0, 1, 1, 1, 0, 1];
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c <= $size - 11; $c++) {
                $row = array_slice($m[$r], $c, 11);
                if ($row === $pat1 || $row === $pat2) {
                    $penalty += 40;
                }
            }
        }
        for ($c = 0; $c < $size; $c++) {
            $col = [];
            for ($r = 0; $r < $size; $r++) {
                $col[] = $m[$r][$c];
            }
            for ($r = 0; $r <= $size - 11; $r++) {
                $slice = array_slice($col, $r, 11);
                if ($slice === $pat1 || $slice === $pat2) {
                    $penalty += 40;
                }
            }
        }

        // 4. Dark/light balance penalty.
        $dark = 0;
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if ($m[$r][$c] === 1) {
                    $dark++;
                }
            }
        }
        $ratio = ($dark * 100) / ($size * $size);
        $penalty += 10 * (int)floor(abs($ratio - 50) / 5);

        return $penalty;
    }

    /* -------------------------------------------------------------------------
     * Format information (mask + ECC-level, BCH(15,5)-encoded)
     * ---------------------------------------------------------------------- */

    private static function writeFormatInfo(array &$m, int $size, int $mask): void
    {
        // ECC-L = 0b01.
        $data = (0b01 << 3) | $mask;
        $bits = self::formatInformationBits($data);

        // Format info is written twice for redundancy. Bit numbering per
        // ISO 18004 §7.9.1: bit 0 is the LSB of the BCH-encoded value.
        //
        // Vertical copy: column 8, top to bottom. Bit 0 sits at (0,8).
        // The timing row (6) and the dark module at (size-8, 8) are skipped.
        //
        // Horizontal copy: row 8, right to left. Bit 0 sits at (8, size-1).
        // The timing column (6) is skipped.
        for ($i = 0; $i < 15; $i++) {
            $bit = ($bits >> $i) & 1;

            // Vertical strip on column 8.
            if ($i < 6) {
                $m[$i][8] = $bit;                  // rows 0..5
            } elseif ($i < 8) {
                $m[$i + 1][8] = $bit;              // rows 7, 8 (skip timing row 6)
            } else {
                $m[$size - 15 + $i][8] = $bit;     // rows size-7..size-1 (skip dark module at size-8)
            }

            // Horizontal strip on row 8.
            if ($i < 8) {
                $m[8][$size - 1 - $i] = $bit;      // cols size-1..size-8
            } elseif ($i === 8) {
                $m[8][7] = $bit;                   // col 7 (skip timing col 6)
            } else {
                $m[8][14 - $i] = $bit;             // cols 5..0
            }
        }
    }

    private static function formatInformationBits(int $data): int
    {
        $g = 0x537;
        $bits = $data << 10;
        for ($i = 14; $i >= 10; $i--) {
            if (($bits >> $i) & 1) {
                $bits ^= $g << ($i - 10);
            }
        }
        return (($data << 10) | $bits) ^ 0x5412;
    }

    /* -------------------------------------------------------------------------
     * Reed-Solomon error correction over GF(256)
     * ---------------------------------------------------------------------- */

    /** @param int[] $data @return int[] */
    private static function reedSolomon(array $data, int $eccLen): array
    {
        self::initGalois();
        $generator = self::generatorPoly($eccLen);
        $result = array_merge($data, array_fill(0, $eccLen, 0));
        for ($i = 0, $len = count($data); $i < $len; $i++) {
            $factor = $result[$i];
            if ($factor === 0) {
                continue;
            }
            $logFactor = self::$gfLog[$factor];
            for ($j = 0; $j <= $eccLen; $j++) {
                if ($generator[$j] !== null) {
                    $result[$i + $j] ^= self::$gfExp[($generator[$j] + $logFactor) % 255];
                }
            }
        }
        return array_slice($result, count($data));
    }

    private static function generatorPoly(int $degree): array
    {
        // Returns the log-domain coefficients of (x - a^0)(x - a^1)...(x - a^(degree-1)).
        $poly = [0]; // [a^0]
        for ($d = 0; $d < $degree; $d++) {
            $new = array_fill(0, count($poly) + 1, null);
            for ($i = 0; $i < count($poly); $i++) {
                if ($poly[$i] === null) {
                    continue;
                }
                $expPoly = self::$gfExp[$poly[$i]];
                $multAlpha = self::$gfExp[($poly[$i] + $d) % 255];
                $new[$i] = $new[$i] === null
                    ? self::$gfLog[$expPoly]
                    : self::$gfLog[self::$gfExp[$new[$i]] ^ $expPoly];
                $new[$i + 1] = $new[$i + 1] === null
                    ? self::$gfLog[$multAlpha]
                    : self::$gfLog[self::$gfExp[$new[$i + 1]] ^ $multAlpha];
            }
            $poly = $new;
        }
        return $poly;
    }

    private static function initGalois(): void
    {
        if (self::$gfExp !== null) {
            return;
        }
        self::$gfExp = array_fill(0, 256, 0);
        self::$gfLog = array_fill(0, 256, 0);
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$gfExp[$i] = $x;
            self::$gfLog[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D; // Primitive polynomial used by QR.
            }
        }
        // exp[255] = exp[0] for safety on wrap-around lookups.
        self::$gfExp[255] = self::$gfExp[0];
    }
}
