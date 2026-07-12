<?php

declare(strict_types=1);

namespace App\Services\Rules;

use App\Models\Brand;
use App\Models\ProductCatalog;
use App\Models\ProductCost;
use Carbon\CarbonImmutable;

/**
 * The single source of truth for "what does this product cost us, and what did we
 * actually keep?" (GO-1.2, master plan §4.2). Every margin surface reads THIS —
 * products page, audit, reports — so no two screens can disagree.
 *
 * Cost precedence (config/costs.php documents it too):
 *   1. manual cost   — product_costs, EFFECTIVE-DATED (the row in force on the
 *                      window's date; a March price rise never rewrites January)
 *   2. Shopify cost  — product_catalog.unit_cost (nullable by design)
 *   3. brand margin  — gross_margin_pct, applied to revenue (a RATE, not a unit cost)
 *   4. nothing       — null. Rendered "—" with a "set costs" hint. NEVER 0.
 *
 * A zero COGS is never inferred: "we don't know this cost" and "this product costs
 * nothing" are different facts, and conflating them manufactures fake profit.
 */
class CostResolver
{
    /**
     * Per-product unit costs for a brand, as of a date (defaults today).
     * Manual rows win over Shopify rows.
     *
     * @return array<string, array{cost: float, currency: ?string, source: string}> keyed by product handle
     */
    public function unitCosts(int $brandId, ?CarbonImmutable $asOf = null): array
    {
        $asOfDate = ($asOf ?? CarbonImmutable::now())->toDateString();

        $out = [];

        // Step 2 — Shopify-synced costs first (lower precedence).
        $catalog = ProductCatalog::query()
            ->where('brand_id', $brandId)
            ->whereNotNull('unit_cost')
            ->get(['handle', 'unit_cost', 'unit_cost_currency']);
        foreach ($catalog as $c) {
            $out[(string) $c->handle] = [
                'cost'     => (float) $c->unit_cost,
                'currency' => $c->unit_cost_currency !== null ? (string) $c->unit_cost_currency : null,
                'source'   => 'shopify',
            ];
        }

        // Step 1 — manual costs override: the row in force ON $asOf (latest effective_from
        // that is not in the future). Ordering ascending and overwriting leaves the
        // newest applicable row last, which is the one that wins.
        $manual = ProductCost::query()
            ->where('brand_id', $brandId)
            ->whereDate('effective_from', '<=', $asOfDate)
            ->orderBy('effective_from')
            ->get(['product_key', 'unit_cost', 'currency', 'effective_from']);
        foreach ($manual as $m) {
            $out[(string) $m->product_key] = [
                'cost'     => (float) $m->unit_cost,
                'currency' => (string) $m->currency,
                'source'   => 'manual',
            ];
        }

        return $out;
    }

    /**
     * COGS for one product over a window.
     *
     * With a unit cost: units × cost — the real thing.
     * Without one, but with a brand gross_margin_pct: revenue × (1 − margin) — an
     * estimate from a brand-wide RATE, flagged source 'brand_margin' so the UI can
     * say so. With neither: null (never 0).
     *
     * @param array{cost: float, currency: ?string, source: string}|null $unitCost
     * @return array{cogs: float, source: string}|null
     */
    public function cogs(?array $unitCost, int $units, float $revenue, ?float $grossMarginPct): ?array
    {
        if ($unitCost !== null && $units > 0) {
            return ['cogs' => round($unitCost['cost'] * $units, 2), 'source' => $unitCost['source']];
        }

        if ($grossMarginPct !== null && $grossMarginPct > 0 && $revenue > 0.0) {
            return ['cogs' => round($revenue * (1 - $grossMarginPct / 100), 2), 'source' => 'brand_margin'];
        }

        return null; // unknown — the caller renders "—", never 0
    }

    /**
     * Contribution margin = revenue − COGS − mapped ad spend.
     *
     * Only MAPPED ad spend is deducted (the products page footer already states what
     * share of spend maps to a product) — so this reads slightly optimistic on brands
     * with lots of unmapped Advantage+/PMax spend, and the UI caption says exactly that.
     * Shipping and payment fees are deliberately NOT modelled while config/costs.php
     * leaves them null (§4.2: never invent them).
     *
     * @return array{value: float, pct: ?float, source: string}|null null when COGS is unknown
     */
    public function contribution(?array $cogs, float $revenue, ?float $mappedAdSpend): ?array
    {
        if ($cogs === null) {
            return null;
        }

        $shipping = config('costs.shipping_per_order');
        $feePct   = config('costs.payment_fee_pct');

        $value = $revenue - $cogs['cogs'] - (float) ($mappedAdSpend ?? 0.0);
        if (is_numeric($feePct)) {
            $value -= $revenue * ((float) $feePct / 100);
        }
        // $shipping stays unmodelled until it is per-order data, not a guess.
        unset($shipping);

        return [
            'value'  => round($value, 2),
            'pct'    => $revenue > 0.0 ? round($value / $revenue * 100, 1) : null,
            'source' => $cogs['source'],
        ];
    }

    /** Convenience: does this brand have ANY cost basis at all? Drives the "set costs" hint. */
    public function hasAnyCostBasis(Brand $brand): bool
    {
        if ($brand->gross_margin_pct !== null) {
            return true;
        }

        return ProductCatalog::query()->where('brand_id', $brand->id)->whereNotNull('unit_cost')->exists()
            || ProductCost::query()->where('brand_id', $brand->id)->exists();
    }
}
