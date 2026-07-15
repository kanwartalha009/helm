<?php

declare(strict_types=1);

namespace App\Services;

/**
 * GO-4.4 — pure-PHP dominant-colour extraction (master plan §7.4: "pure-PHP
 * dominant-color binning — no new deps; GD is in Laravel"). Takes raw image
 * BYTES (a JPG/PNG/WebP a caller has already fetched) and returns the most
 * prominent colours, quantised into a small palette.
 *
 * Deliberately takes bytes, not a URL — the fetch (bounded, best-effort) is the
 * caller's job (BrandStyleService), so this stays a pure, deterministic,
 * unit-testable transform with no I/O.
 *
 * Honesty: unreadable bytes or a missing GD extension return [] (an empty
 * palette the UI shows as "no palette yet"), NEVER a fabricated colour.
 */
final class PaletteExtractor
{
    /** Colours are binned to this many levels per channel (4 → 64 buckets). */
    private const LEVELS = 4;

    /** Downscale to at most this many px per side before sampling (speed). */
    private const SAMPLE_MAX = 80;

    /**
     * @param  array<int, string> $imagesBytes  raw bytes of one or more images
     * @param  int $max  how many swatches to return
     * @return array<int, array{hex: string, weight: float}>  most-weighted first
     */
    public function fromImages(array $imagesBytes, int $max = 6): array
    {
        if (! \function_exists('imagecreatefromstring')) {
            return []; // GD absent → honest empty, never a guessed colour
        }

        /** @var array<string, int> $bins  packed-rgb bucket → pixel count */
        $bins = [];
        $total = 0;

        foreach ($imagesBytes as $bytes) {
            if (! is_string($bytes) || $bytes === '') {
                continue;
            }
            $total += $this->accumulate($bytes, $bins);
        }

        if ($total === 0 || $bins === []) {
            return [];
        }

        arsort($bins);
        $out = [];
        foreach (array_slice($bins, 0, $max, true) as $packed => $count) {
            $out[] = [
                'hex'    => $this->packedToHex((int) $packed),
                'weight' => round($count / $total, 4),
            ];
        }

        return $out;
    }

    /**
     * Bin one image's pixels into $bins (by reference). Returns the pixel count
     * it contributed (0 when the bytes weren't a decodable image).
     *
     * @param array<string, int> $bins
     */
    private function accumulate(string $bytes, array &$bins): int
    {
        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            return 0;
        }

        $w = imagesx($img);
        $h = imagesy($img);
        if ($w < 1 || $h < 1) {
            imagedestroy($img);

            return 0;
        }

        // Sample on a bounded grid rather than every pixel — a palette doesn't
        // need per-pixel accuracy and this keeps a large image cheap.
        $stepX = (int) max(1, ceil($w / self::SAMPLE_MAX));
        $stepY = (int) max(1, ceil($h / self::SAMPLE_MAX));
        $bucket = (int) (256 / self::LEVELS);

        $counted = 0;
        for ($y = 0; $y < $h; $y += $stepY) {
            for ($x = 0; $x < $w; $x += $stepX) {
                $rgb = imagecolorat($img, $x, $y);
                $a = ($rgb >> 24) & 0x7F;
                if ($a > 100) {
                    continue; // near-transparent pixel — not part of the palette
                }
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;

                // Quantise each channel to the bucket midpoint so near-identical
                // shades collapse to one swatch.
                $qr = min(255, intdiv($r, $bucket) * $bucket + intdiv($bucket, 2));
                $qg = min(255, intdiv($g, $bucket) * $bucket + intdiv($bucket, 2));
                $qb = min(255, intdiv($b, $bucket) * $bucket + intdiv($bucket, 2));

                $key = (string) (($qr << 16) | ($qg << 8) | $qb);
                $bins[$key] = ($bins[$key] ?? 0) + 1;
                $counted++;
            }
        }

        imagedestroy($img);

        return $counted;
    }

    private function packedToHex(int $packed): string
    {
        return sprintf('#%02X%02X%02X', ($packed >> 16) & 0xFF, ($packed >> 8) & 0xFF, $packed & 0xFF);
    }
}
