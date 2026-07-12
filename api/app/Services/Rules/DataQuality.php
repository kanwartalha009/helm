<?php

declare(strict_types=1);

namespace App\Services\Rules;

use App\Models\Brand;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Per-brand data-quality score, 0–100 (GO-1.3, master plan §4.3).
 *
 * Every component is MEASURED, never estimated: a connection row exists or it doesn't;
 * the newest complete day is a date; the earliest row is a date; a cost is null or it
 * isn't. Each component reports its gap in plain language plus the `fix` dataset key
 * that closes it, so the drawer can offer the one-click backfill.
 *
 * The score GATES recommendations (GO-3/GO-4): below config('quality.threshold') Helm
 * declines to advise and says what's missing instead. Advising confidently on holey
 * data is the generic-advice failure mode that cost every incumbent its credibility.
 *
 * Applicability: a component that cannot apply (ad grain on a brand with no ad
 * platform) is EXCLUDED from the denominator rather than scored zero — scoring an
 * impossible component as 0 would be a wrong number, and wrong numbers are the one
 * thing this product cannot ship.
 */
class DataQuality
{
    private const AD_PLATFORMS = ['meta', 'google', 'tiktok'];

    /**
     * @return array{
     *   score: int, threshold: int, meetsGate: bool, tier: string,
     *   components: array<int, array<string, mixed>>
     * }
     */
    public function forBrand(Brand $brand): array
    {
        $tz        = $brand->timezone ?: 'UTC';
        $today     = CarbonImmutable::now($tz)->startOfDay();
        $yesterday = $today->subDay();

        $connected   = $brand->connections()->where('status', 'active')->pluck('platform')->unique()->values()->all();
        $adConnected = array_values(array_intersect($connected, self::AD_PLATFORMS));

        $components = [
            $this->platforms($connected, $adConnected),
            $this->freshness($brand, $connected, $yesterday),
            $this->history($brand, $connected, $today),
            $this->grain($brand, $adConnected, $today),
            $this->costs($brand, $today),
        ];

        // Re-normalise over APPLICABLE weights only.
        $applicable = array_filter($components, static fn (array $c): bool => $c['applicable']);
        $totalW     = array_sum(array_column($applicable, 'weight'));
        $earned     = 0.0;
        foreach ($applicable as $c) {
            $earned += $c['weight'] * $c['ratio'];
        }
        $score = $totalW > 0 ? (int) round($earned / $totalW * 100) : 0;

        $threshold = (int) config('quality.threshold', 70);

        return [
            'score'      => $score,
            'threshold'  => $threshold,
            'meetsGate'  => $score >= $threshold,
            'tier'       => $score >= 85 ? 'good' : ($score >= $threshold ? 'ok' : 'poor'),
            'components' => $components,
        ];
    }

    /** True when this brand's data is good enough for Helm to make recommendations (GO-3/GO-4). */
    public function meetsGate(Brand $brand): bool
    {
        return $this->forBrand($brand)['meetsGate'];
    }

    /** The expected connections exist: Shopify (the revenue spine) + at least one ad platform. */
    private function platforms(array $connected, array $adConnected): array
    {
        $hasShopify = in_array('shopify', $connected, true);
        $hasAds     = $adConnected !== [];

        // Shopify carries more weight than "some ad platform": without it there is no
        // revenue truth at all, and every other number is unanchored.
        $ratio = ($hasShopify ? 0.6 : 0.0) + ($hasAds ? 0.4 : 0.0);

        $missing = [];
        if (! $hasShopify) {
            $missing[] = 'Shopify';
        }
        if (! $hasAds) {
            $missing[] = 'an ad platform';
        }

        return $this->component(
            'platforms', 'Connections', $ratio, true,
            $missing === [] ? 'Shopify + ' . count($adConnected) . ' ad platform(s) connected.'
                : 'Not connected: ' . implode(' and ', $missing) . '.',
            null,
        );
    }

    /** Each connected source has a recent COMPLETE day. Stale data is the #1 source of wrong advice. */
    private function freshness(Brand $brand, array $connected, CarbonImmutable $yesterday): array
    {
        if ($connected === []) {
            return $this->component('freshness', 'Sync freshness', 0.0, false, 'No connections to sync.', null);
        }

        $grace = (int) config('quality.freshness_grace_days', 1);
        $zero  = max($grace + 1, (int) config('quality.freshness_zero_days', 7));

        $latest = DB::table('daily_metrics')
            ->where('brand_id', $brand->id)
            ->where('is_complete', true)
            ->groupBy('platform')
            ->selectRaw('platform, MAX(date) AS latest')
            ->pluck('latest', 'platform');

        $ratios = [];
        $stale  = [];
        foreach ($connected as $p) {
            $last = $latest[$p] ?? null;
            if ($last === null) {
                $ratios[] = 0.0;
                $stale[]  = "{$p}: never synced";
                continue;
            }
            $days = (int) CarbonImmutable::parse((string) $last)->startOfDay()->diffInDays($yesterday, false);
            $days = max(0, $days);
            if ($days <= $grace) {
                $ratios[] = 1.0;
                continue;
            }
            // Linear decay from full at `grace` to zero at `zero`.
            $ratios[] = max(0.0, 1.0 - (($days - $grace) / ($zero - $grace)));
            $stale[]  = "{$p}: {$days}d behind";
        }

        $ratio = count($ratios) > 0 ? array_sum($ratios) / count($ratios) : 0.0;

        return $this->component(
            'freshness', 'Sync freshness', $ratio, true,
            $stale === [] ? 'Every connected source is up to date.' : 'Behind: ' . implode(', ', $stale) . '.',
            $stale === [] ? null : 'history',
        );
    }

    /** Backfill depth vs the 12-month target — the history recommendations reason over. */
    private function history(Brand $brand, array $connected, CarbonImmutable $today): array
    {
        if ($connected === []) {
            return $this->component('history', 'History depth', 0.0, false, 'No connections yet.', null);
        }

        $target   = max(1, (int) config('quality.history_target_months', 12));
        $earliest = DB::table('daily_metrics')->where('brand_id', $brand->id)->min('date');

        if ($earliest === null) {
            return $this->component('history', 'History depth', 0.0, true, 'No daily history on file yet.', 'history');
        }

        $months = CarbonImmutable::parse((string) $earliest)->startOfDay()->diffInMonths($today);
        $ratio  = min(1.0, $months / $target);

        return $this->component(
            'history', 'History depth', $ratio, true,
            $ratio >= 1.0
                ? "Full {$target}-month history on file."
                : round($months) . " of {$target} months on file (from " . CarbonImmutable::parse((string) $earliest)->toDateString() . ').',
            $ratio >= 1.0 ? null : 'history',
        );
    }

    /**
     * The grain BEHIND the ad spend: campaign, ad-set and creative rows. Without them
     * every ad recommendation is a guess about an account it cannot see inside.
     * Not applicable to a brand with no ad platform.
     */
    private function grain(Brand $brand, array $adConnected, CarbonImmutable $today): array
    {
        if ($adConnected === []) {
            return $this->component('grain', 'Ad detail (campaign / ad set / creative)', 0.0, false, 'No ad platform connected.', null);
        }

        $since = $today->subDays(30)->toDateString();
        $has   = static fn (string $table): bool => DB::table($table)
            ->where('brand_id', $brand->id)
            ->where('date', '>=', $since)
            ->exists();

        $checks = [
            'campaigns' => $has('ad_campaign_daily_metrics'),
            'ad sets'   => $has('ad_set_daily_metrics'),
            'creatives' => $has('ad_creative_daily'),
        ];

        $ratio   = count(array_filter($checks)) / count($checks);
        $missing = array_keys(array_filter($checks, static fn (bool $v): bool => ! $v));

        return $this->component(
            'grain', 'Ad detail (campaign / ad set / creative)', $ratio, true,
            $missing === [] ? 'Campaign, ad-set and creative rows all present.'
                : 'Missing in the last 30 days: ' . implode(', ', $missing) . '.',
            $missing === [] ? null : (in_array('creatives', $missing, true) && count($missing) === 1 ? 'creatives' : 'campaigns'),
        );
    }

    /**
     * A cost basis, so contribution margin is real (GO-1.2). Measured against the
     * products that actually EARNED revenue in the window — costing a dead SKU proves
     * nothing. A brand-level gross_margin_pct is a real but coarse basis and is
     * therefore capped (config brand_margin_credit).
     */
    private function costs(Brand $brand, CarbonImmutable $today): array
    {
        $days  = max(1, (int) config('quality.costs_window_days', 90));
        $since = $today->subDays($days)->toDateString();

        // Products with revenue in the window (commerce rows key by product TITLE).
        $titles = DB::table('commerce_daily_metrics')
            ->where('brand_id', $brand->id)
            ->where('dimension_type', 'product')
            ->where('date', '>=', $since)
            ->distinct()
            ->pluck('dimension_key')
            ->map(static fn ($t) => mb_strtolower(trim((string) $t)))
            ->filter()
            ->values();

        $hasMargin = $brand->gross_margin_pct !== null;

        if ($titles->isEmpty()) {
            // No product revenue to cost. The brand margin alone is the only basis
            // that can exist here — score it honestly rather than failing the brand.
            return $this->component(
                'costs', 'Product costs', $hasMargin ? 1.0 : 0.0, true,
                $hasMargin ? 'Brand gross-margin % set (no product revenue in the window to cost).'
                    : 'No cost basis: set a gross-margin % or product costs.',
                null,
            );
        }

        // Handles that have a cost (Shopify unit_cost or a manual product_costs row).
        $costed = DB::table('product_catalog')
            ->where('brand_id', $brand->id)
            ->whereNotNull('unit_cost')
            ->pluck('title', 'handle')
            ->map(static fn ($t) => mb_strtolower(trim((string) $t)))
            ->values()
            ->all();

        $manualHandles = DB::table('product_costs')->where('brand_id', $brand->id)->distinct()->pluck('product_key')->all();
        $manualTitles  = DB::table('product_catalog')
            ->where('brand_id', $brand->id)
            ->whereIn('handle', $manualHandles)
            ->pluck('title')
            ->map(static fn ($t) => mb_strtolower(trim((string) $t)))
            ->all();

        $costedTitles = array_unique(array_merge($costed, $manualTitles));
        $covered      = $titles->intersect($costedTitles)->count();
        $ratio        = $titles->count() > 0 ? $covered / $titles->count() : 0.0;

        // A brand-wide rate floors the score — it IS a basis, just a coarse one.
        if ($hasMargin) {
            $ratio = max($ratio, (float) config('quality.brand_margin_credit', 0.5));
        }
        $ratio = min(1.0, $ratio);

        return $this->component(
            'costs', 'Product costs', $ratio, true,
            $covered . ' of ' . $titles->count() . ' earning products have a unit cost'
                . ($hasMargin ? '; brand gross-margin % is set as a fallback.' : '; no brand gross-margin % set.'),
            null,
        );
    }

    /**
     * @param float $ratio 0..1 of this component's own weight
     * @param ?string $fix data-coverage dataset key that closes the gap (one-click backfill)
     * @return array<string, mixed>
     */
    private function component(string $key, string $label, float $ratio, bool $applicable, string $detail, ?string $fix): array
    {
        $weight = (int) (config('quality.weights.' . $key) ?? 0);
        $ratio  = max(0.0, min(1.0, $ratio));

        return [
            'key'        => $key,
            'label'      => $label,
            'weight'     => $weight,
            'applicable' => $applicable,
            'ratio'      => $ratio,
            'points'     => round($weight * $ratio, 1),
            'detail'     => $detail,
            'fix'        => $fix,   // 'history' | 'campaigns' | 'creatives' | null
        ];
    }
}
