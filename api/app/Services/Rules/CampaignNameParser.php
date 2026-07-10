<?php

declare(strict_types=1);

namespace App\Services\Rules;

use App\Models\WorkspaceSetting;

/**
 * Naming-convention fallback for product attribution (spec §4 Phase 5, item 4) —
 * a per-workspace regex list mapping campaign-name tokens → a Shopify product
 * handle, for spend that has NO landing-URL match (the URL path is always
 * preferred). Seeded EMPTY, so it is OFF by default: with no rules configured,
 * handleFor() always returns null and nothing changes.
 *
 * Rule text lives in WorkspaceSetting under SETTING_KEY, one rule per line:
 *
 *     PATTERN => handle
 *     BLACK[-_ ]?FRIDAY => bf-hoodie
 *     ^NP_.*_SUMMER => summer-tee
 *
 * PATTERN is a case-insensitive regex (matched against the campaign name), handle
 * is the target product handle. Invalid regexes are skipped, never fatal.
 *
 * This ships as a SEAM, not a promise: it is intentionally NOT yet wired into the
 * ad-product fetchers. Activation (a later, deliberate step) is: add a `source`
 * column to ad_product_daily ('url' vs 'name') so the UI can mark confidence,
 * apply this parser only to spend not already URL-mapped, and expose the rule
 * textarea in Settings. Until then this service + its tests lock the parsing
 * contract in place.
 */
class CampaignNameParser
{
    public const SETTING_KEY = 'ad_product_name_rules';

    /**
     * Parsed, valid rules in file order.
     *
     * @return list<array{pattern: string, handle: string}>
     */
    public function rules(): array
    {
        $raw = WorkspaceSetting::getValue(self::SETTING_KEY, '');
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $rules = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || ! str_contains($line, '=>')) {
                continue;
            }
            [$pattern, $handle] = array_map('trim', explode('=>', $line, 2));
            $handle = mb_strtolower($handle);
            if ($pattern === '' || $handle === '' || ! $this->validRegex($pattern)) {
                continue;
            }
            $rules[] = ['pattern' => $pattern, 'handle' => $handle];
        }

        return $rules;
    }

    /** True when at least one valid rule is configured (the feature is "on"). */
    public function enabled(): bool
    {
        return $this->rules() !== [];
    }

    /**
     * First rule whose pattern matches the campaign name → its handle, else null.
     * Case-insensitive. Never throws on a bad pattern (those are filtered out).
     */
    public function handleFor(string $campaignName): ?string
    {
        if ($campaignName === '') {
            return null;
        }
        foreach ($this->rules() as $rule) {
            if (preg_match('~' . str_replace('~', '\~', $rule['pattern']) . '~i', $campaignName) === 1) {
                return $rule['handle'];
            }
        }

        return null;
    }

    private function validRegex(string $pattern): bool
    {
        set_error_handler(static fn (): bool => true);
        $ok = preg_match('~' . str_replace('~', '\~', $pattern) . '~i', '') !== false;
        restore_error_handler();

        return $ok;
    }
}
