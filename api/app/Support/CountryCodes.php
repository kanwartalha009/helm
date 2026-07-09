<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Country name → ISO-3166 alpha-2 normaliser.
 *
 * ShopifyQL's `billing_country` dimension returns country NAMES ("Spain"),
 * while Meta's country breakdown and config/country_regions.php both key on
 * ISO-2 codes ("ES"). Without normalisation the monthly report's ROAS-by-
 * country join (Meta spend vs commerce revenue) and market fold (country →
 * region) silently miss on every row. This bridges the two representations at
 * read time, so no stored dimension_key has to be re-keyed.
 *
 * The name → code table lives in config/country_codes.php (generated from
 * ISO 3166-1 + common Shopify variants).
 */
final class CountryCodes
{
    /** @var array<string, string>|null lower-cased name => ISO-2, lazily loaded */
    private static ?array $map = null;

    /**
     * Resolve a Shopify country name (or an already-ISO-2 value) to its upper-
     * case ISO-2 code. Returns null when it can't be resolved, so callers can
     * decide how to bucket the unmatched tail rather than guessing.
     */
    public static function toIso2(?string $value): ?string
    {
        $v = trim((string) $value);
        if ($v === '') {
            return null;
        }

        // Already a 2-letter code (Meta side, or a store that stored codes).
        if (preg_match('/^[A-Za-z]{2}$/', $v) === 1) {
            return strtoupper($v);
        }

        self::$map ??= array_change_key_case((array) config('country_codes', []), CASE_LOWER);

        return self::$map[mb_strtolower($v)] ?? null;
    }
}
