<?php

declare(strict_types=1);

namespace App\Services\Aggregation;

use App\Models\AdProductDaily;
use App\Models\Brand;
use App\Models\CommerceDailyMetric;
use App\Models\ProductCatalog;
use App\Models\SessionTrafficDaily;
use App\Support\LandingPathMapper;
use Carbon\CarbonImmutable;

/**
 * Assembles the Inventory Intelligence report for one brand: per Shopify product,
 * joins current stock + variants (product_catalog), units + revenue
 * (commerce_daily_metrics, by product title) and Meta spend + ROAS + active ads
 * (ad_product_daily, by product handle) over a selectable window.
 *
 * The catalog is the bridge: ad spend keys by product HANDLE, commerce by product
 * TITLE, and only the catalog knows both. Revenue is Total sales + refunds (before
 * returns), Online Store only; units are gross ordered quantity; ROAS is blended
 * (revenue ÷ Meta spend) at the product level.
 *
 * Honesty rules (2026-07-10):
 *  - Only ACTIVE catalog products are reported. Non-active (draft/archived —
 *    stored lowercased by shopify:sync-catalog) rows are excluded from the rows
 *    AND every summary count; `excludedInactive` reports how many were dropped.
 *  - Missing data is NULL, never 0. When the brand has NO ad_product_daily rows
 *    in the window, every spend/ads/roas figure (rows + summary + unattributed)
 *    is null — the table was simply never filled for those days. Same for
 *    commerce: no product rows in the window → units/revenue/deltas are null.
 *    When the window IS covered, a product with genuinely no sales/spend stays
 *    0 — that covered-window distinction is the whole point of the flags.
 *  - `action` only says 'no_spend' when spend data exists for the window;
 *    otherwise it falls back to the stock-based action alone.
 *  - ROAS (row + summary) is computed from USD sums via the stored
 *    fx_rate_to_usd snapshots (COALESCE to 1), so a USD ad account against a
 *    EUR store doesn't mix currencies. Displayed spend/revenue stay native
 *    sums; `spendCurrencyMismatch` flags when the window's ad rows carry a
 *    currency other than the brand's base, so the FE can caption it.
 *  - Custom windows clamp `to` (and a last-resort future `from`) to yesterday
 *    in the brand's timezone — today is always partial.
 *  - `dataThrough` surfaces how far each source actually reaches: catalog
 *    snapshot time (ISO8601), MAX commerce product date and MAX ad-spend date
 *    (both Y-m-d, unbounded — not window-limited). The legacy top-level
 *    `syncedAt` is kept for FE compatibility.
 */
final class InventoryQuery
{
    /** Stock tiers → status. */
    private const ALERT_AT = 20;

    /** @param array<string, mixed> $params */
    public function run(Brand $brand, array $params): array
    {
        $tz     = $brand->timezone ?: 'UTC';
        $period = in_array($params['period'] ?? null, ['last7', 'last30', 'mtd', 'custom'], true)
            ? (string) $params['period'] : 'last7';

        [$from, $to, $pFrom, $pTo] = $this->window($period, $tz, $params['from'] ?? null, $params['to'] ?? null);

        $bid = $brand->id;
        $ccy = strtoupper((string) ($brand->base_currency ?: 'USD'));

        // Catalog: only ACTIVE products make the report. shopify:sync-catalog
        // stores Shopify's ACTIVE/DRAFT/ARCHIVED lowercased ('active'|'draft'|
        // 'archived'); null (no status captured) is treated as active so we
        // never hide stock on missing metadata. Non-active rows are excluded
        // from the rows AND all summary counts.
        $catalog  = ProductCatalog::query()->where('brand_id', $bid)->get();
        $products = $catalog
            ->filter(static fn ($p) => $p->status === null || strtolower((string) $p->status) === 'active')
            ->values();
        $excludedInactive = $catalog->count() - $products->count();

        // Coverage flags: does ANY data exist for this brand in this window?
        // (attributed or __collection/__other for spend; product rows for
        // commerce). When false, the corresponding figures are NULL — missing
        // data is never rendered as 0. When true, per-product absence stays 0:
        // a product with genuinely no sales/spend in a covered window IS 0,
        // and that distinction is exactly what these flags preserve.
        $hasSpendData = AdProductDaily::query()
            ->where('brand_id', $bid)
            ->whereBetween('date', [$from, $to])
            ->exists();
        $hasCommerceData = CommerceDailyMetric::query()
            ->where('brand_id', $bid)
            ->where('dimension_type', 'product')
            ->whereBetween('date', [$from, $to])
            ->exists();

        // Meta spend (native + USD) + peak active ads per product handle, this window.
        // USD sums power ROAS so the math stays currency-correct (fx snapshot,
        // COALESCE 1 for legacy rows without a rate).
        $adByHandle = AdProductDaily::query()
            ->where('brand_id', $bid)
            ->whereBetween('date', [$from, $to])
            ->selectRaw('product_key,
                COALESCE(SUM(spend), 0) AS spend,
                COALESCE(SUM(spend * COALESCE(fx_rate_to_usd, 1)), 0) AS spend_usd,
                COALESCE(MAX(ads_count), 0) AS ads')
            ->groupBy('product_key')
            ->get()
            ->keyBy('product_key');

        // Any window ad row carrying a currency other than the brand base →
        // the native spend column mixes currencies (FE shows a caption).
        $spendCurrencyMismatch = AdProductDaily::query()
            ->where('brand_id', $bid)
            ->whereBetween('date', [$from, $to])
            ->whereNotNull('currency')
            ->distinct()
            ->pluck('currency')
            ->contains(static fn ($c) => strtoupper((string) $c) !== $ccy);

        // Units (gross) + revenue-before-returns per product title, this window + prior.
        $commByTitle = $this->commerce($bid, $from, $to);
        $commPrev    = $this->commerce($bid, $pFrom, $pTo);

        // Sessions by traffic type (Bosco item B). Fails CLOSED: unless every day in the
        // window has a reconciled row, the whole block is null and the UI renders "—".
        $sessions = $this->sessions($bid, $from, $to);

        $rows = [];
        $sumSpend    = 0.0;
        $sumSpendUsd = 0.0;
        $sumRev      = 0.0;
        $sumRevUsd   = 0.0;
        $sumUnits = 0;
        $sumUnitsPrev = 0;
        $sumStock = 0;
        $cPause = $cAlert = $cOk = 0;

        foreach ($products as $p) {
            $ad   = $adByHandle->get($p->handle);
            $c    = $commByTitle->get($p->title);
            $cp   = $commPrev->get($p->title);

            $spend    = (float) ($ad->spend ?? 0);
            $spendUsd = (float) ($ad->spend_usd ?? 0);
            $adsN     = (int) ($ad->ads ?? 0);
            $units    = (int) ($c->units ?? 0);
            $unitsP   = (int) ($cp->units ?? 0);
            $revenue  = (float) ($c->total_sales ?? 0) + (float) ($c->refunds ?? 0);
            $revUsd   = (float) ($c->revenue_usd ?? 0);
            // ROAS from USD sums — needs both sides of the fraction covered.
            $roas  = ($hasSpendData && $hasCommerceData && $spendUsd > 0) ? round($revUsd / $spendUsd, 2) : null;
            $delta = ($hasCommerceData && $unitsP > 0) ? (int) round(($units - $unitsP) / $unitsP * 100) : null;

            $stock  = (int) $p->total_inventory;
            $status = $stock <= 0 ? 'pause' : ($stock <= self::ALERT_AT ? 'alert' : 'ok');
            // 'no_spend' is only meaningful when the window HAS spend data —
            // without it we can't tell "not advertised" from "not synced".
            $action = match (true) {
                $status === 'pause'          => 'out_of_stock',
                $status === 'alert'          => 'low_stock',
                $hasSpendData && $spend <= 0 => 'no_spend',
                default                      => 'ok',
            };

            // Sessions that LANDED on this product's page, split by traffic type. Null (not 0)
            // whenever the window isn't fully reconciled — see sessions(). A product with a
            // covered window and genuinely no landings stays 0, which is a real fact.
            $sess = $sessions['complete']
                ? ($sessions['byProduct'][$p->handle] ?? self::EMPTY_SPLIT)
                : null;

            $rows[] = [
                'handle'       => $p->handle,
                'title'        => $p->title,
                'variantCount' => (int) $p->variant_count,
                'variants'     => is_array($p->variants) ? $p->variants : [],
                'stock'        => $stock,
                'units'        => $hasCommerceData ? $units : null,
                'unitsPrev'    => $hasCommerceData ? $unitsP : null,
                'deltaPct'     => $delta,
                'spend'        => $hasSpendData ? round($spend, 2) : null,
                'revenue'      => $hasCommerceData ? round($revenue, 2) : null,
                'roas'         => $roas,
                'ads'          => $hasSpendData ? $adsN : null,
                'sessions'      => $sess === null ? null : array_sum($sess),
                'sessionsByType' => $sess,
                'status'       => $status,
                'action'       => $action,
            ];

            $sumSpend     += $spend;
            $sumSpendUsd  += $spendUsd;
            $sumRev       += $revenue;
            $sumRevUsd    += $revUsd;
            $sumUnits     += $units;
            $sumUnitsPrev += $unitsP;
            $sumStock     += $stock;
            $status === 'pause' ? $cPause++ : ($status === 'alert' ? $cAlert++ : $cOk++);
        }

        // Biggest spenders first (matches the mockup's default sort).
        usort($rows, static fn (array $a, array $b): int => ($b['spend'] ?? 0) <=> ($a['spend'] ?? 0)
            ?: strcasecmp((string) $a['title'], (string) $b['title']));

        // Meta spend NOT attributed to a single product — preserved, shown as a banner.
        $un = AdProductDaily::query()
            ->where('brand_id', $bid)
            ->whereIn('product_key', ['__collection', '__other'])
            ->whereBetween('date', [$from, $to])
            ->selectRaw("COALESCE(SUM(CASE WHEN product_key = '__collection' THEN spend ELSE 0 END), 0) AS coll,
                         COALESCE(SUM(CASE WHEN product_key = '__other' THEN spend ELSE 0 END), 0) AS other,
                         COALESCE(SUM(spend * COALESCE(fx_rate_to_usd, 1)), 0) AS un_usd")
            ->first();
        $coll  = (float) ($un->coll ?? 0);
        $other = (float) ($un->other ?? 0);
        $unUsd = (float) ($un->un_usd ?? 0);

        // Brand-level blended ROAS uses TOTAL Meta spend — spend attributed to a
        // product PLUS the unattributed (collection/dynamic) spend. Dropping the
        // unattributed €-figure from the denominator would flatter the headline
        // ROAS, so we don't. The product rows still sum only to attributed spend;
        // the gap is the unattributed banner, which is exactly why it exists.
        // The division itself runs on the USD sums (fx-correct).
        $totalSpend    = $sumSpend + $coll + $other;
        $totalSpendUsd = $sumSpendUsd + $unUsd;

        // When the catalog was last snapshotted (shopify:sync-catalog) — surfaced
        // on the page so the operator knows how fresh the stock figures are.
        // Uses the FULL catalog (inactive rows included): one snapshot run stamps
        // them all, and hiding a product must not hide the freshness signal.
        $syncedRaw = $catalog->max('captured_at');
        $syncedAt  = $syncedRaw ? CarbonImmutable::parse((string) $syncedRaw)->toIso8601String() : null;

        // How far each source actually reaches for this brand — UNBOUNDED maxima,
        // not window-limited, so the FE can say "spend synced through <date>".
        $commThrough = CommerceDailyMetric::query()
            ->where('brand_id', $bid)->where('dimension_type', 'product')->max('date');
        $adThrough = AdProductDaily::query()->where('brand_id', $bid)->max('date');

        return [
            'brand'    => ['id' => $brand->id, 'name' => $brand->name, 'slug' => $brand->slug, 'currency' => $ccy],
            'period'   => $period,
            'from'     => $from,
            'to'       => $to,
            'currency' => $ccy,
            'syncedAt' => $syncedAt,
            'dataThrough' => [
                'catalog'  => $syncedAt,
                'commerce' => $commThrough ? CarbonImmutable::parse((string) $commThrough)->toDateString() : null,
                'adSpend'  => $adThrough ? CarbonImmutable::parse((string) $adThrough)->toDateString() : null,
                'sessions' => $sessions['through'],
            ],
            'excludedInactive'      => $excludedInactive,
            'spendCurrencyMismatch' => $spendCurrencyMismatch,
            'summary'  => [
                'products'        => $products->count(),
                'pause'           => $cPause,
                'alert'           => $cAlert,
                'ok'              => $cOk,
                'netStock'        => $sumStock,
                'units'           => $hasCommerceData ? $sumUnits : null,
                'unitsPrev'       => $hasCommerceData ? $sumUnitsPrev : null,
                'metaSpend'       => $hasSpendData ? round($totalSpend, 2) : null,
                'attributedSpend' => $hasSpendData ? round($sumSpend, 2) : null,
                'revenue'         => $hasCommerceData ? round($sumRev, 2) : null,
                'roas'            => ($hasSpendData && $hasCommerceData && $totalSpendUsd > 0)
                    ? round($sumRevUsd / $totalSpendUsd, 2) : null,
            ],
            // No spend data in the window → we don't know the split; null, not €0.
            'unattributed' => $hasSpendData
                ? ['collection' => round($coll, 2), 'other' => round($other, 2), 'total' => round($coll + $other, 2)]
                : null,
            // Sessions by traffic type (Bosco item B). `complete: false` → every per-product
            // `sessions` is null and the UI must render "—" plus the coverage note.
            'sessions'     => [
                'complete'     => $sessions['complete'],
                'windowDays'   => $sessions['windowDays'],
                'completeDays' => $sessions['completeDays'],
                'through'      => $sessions['through'],
                // Header strip — the store-level split Bosco screenshotted.
                'byType'       => $sessions['complete'] ? $sessions['storeTotals'] : null,
                'total'        => $sessions['complete'] ? array_sum($sessions['storeTotals']) : null,
                // The honest "Store-wide / other pages" row: home, collections index, /pages,
                // search, checkout. Roughly half a store's sessions land here rather than on a
                // product, so it is shown, not swept under the totals.
                'storeWide'    => $sessions['complete'] ? $sessions['storeWide'] : null,
                'productTotal' => $sessions['complete'] ? $sessions['productTotal'] : null,
            ],
            'products'     => $rows,
        ];
    }

    /** A covered window with no landings for a product is a real zero, not missing data. */
    private const EMPTY_SPLIT = ['paid' => 0, 'direct' => 0, 'organic' => 0, 'unknown' => 0];

    /**
     * Sessions by traffic type over the window, resolved per landing entity at sync time.
     *
     * ══ WHY THIS FAILS CLOSED ══
     * Sessions are only reported when EVERY day in the window has a reconciled row
     * (`is_complete = true`). Summing whatever days happen to exist would produce a number
     * that looks precise and is simply wrong — a 30-day window holding 12 synced days would
     * under-report every product by ~60%, silently, and rank the table by that. So a window
     * with any gap reports `complete: false`, the per-product figures are null, and the UI
     * shows "—". Same "never show partial" gate the rest of the platform uses.
     *
     * @return array{complete: bool, windowDays: int, completeDays: int, through: string|null,
     *               byProduct: array<string, array<string, int>>, storeWide: array<string, int>,
     *               storeTotals: array<string, int>, productTotal: int}
     */
    private function sessions(int $bid, string $from, string $to): array
    {
        $windowDays = CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1;

        $completeDays = (int) SessionTrafficDaily::query()
            ->where('brand_id', $bid)
            ->where('is_complete', true)
            ->whereBetween('date', [$from, $to])
            ->distinct()
            ->count('date');

        // How far the source reaches at all — unbounded, like commerce/adSpend above, so the
        // FE can say "sessions synced through <date>" even when the window isn't covered.
        $throughRaw = SessionTrafficDaily::query()
            ->where('brand_id', $bid)->where('is_complete', true)->max('date');
        $through = $throughRaw ? CarbonImmutable::parse((string) $throughRaw)->toDateString() : null;

        $empty = [
            'complete'     => false,
            'windowDays'   => (int) $windowDays,
            'completeDays' => $completeDays,
            'through'      => $through,
            'byProduct'    => [],
            'storeWide'    => self::EMPTY_SPLIT,
            'storeTotals'  => self::EMPTY_SPLIT,
            'productTotal' => 0,
        ];

        if ($completeDays < $windowDays) {
            return $empty;   // a gap anywhere → no number anywhere. "—", never a short sum.
        }

        $rows = SessionTrafficDaily::query()
            ->where('brand_id', $bid)
            ->where('is_complete', true)
            ->whereBetween('date', [$from, $to])
            ->selectRaw('entity_type, entity_key, traffic_type, COALESCE(SUM(sessions), 0) AS s')
            ->groupBy('entity_type', 'entity_key', 'traffic_type')
            ->get();

        $byProduct    = [];
        $storeWide    = self::EMPTY_SPLIT;
        $storeTotals  = self::EMPTY_SPLIT;
        $productTotal = 0;

        foreach ($rows as $r) {
            $type = (string) $r->traffic_type;
            if (! array_key_exists($type, $storeTotals)) {
                continue;   // a traffic type Shopify has never returned — don't invent a column
            }

            $n = (int) $r->s;
            $storeTotals[$type] += $n;

            if ((string) $r->entity_type === LandingPathMapper::TYPE_PRODUCT) {
                $handle = (string) $r->entity_key;
                $byProduct[$handle] ??= self::EMPTY_SPLIT;
                $byProduct[$handle][$type] += $n;
                $productTotal += $n;
                continue;
            }

            // Collections and 'other' both roll into the store-wide row. A visitor landing on
            // /collections/new-in did not land on a product, and pretending otherwise is how a
            // product's sessions get inflated.
            $storeWide[$type] += $n;
        }

        return [
            'complete'     => true,
            'windowDays'   => (int) $windowDays,
            'completeDays' => $completeDays,
            'through'      => $through,
            'byProduct'    => $byProduct,
            'storeWide'    => $storeWide,
            'storeTotals'  => $storeTotals,
            'productTotal' => $productTotal,
        ];
    }

    /**
     * Per-product-title commerce sums (units gross, total_sales, refunds — native,
     * plus revenue-before-returns in USD via the stored fx snapshot) over a window.
     */
    private function commerce(int $brandId, string $from, string $to): \Illuminate\Support\Collection
    {
        return CommerceDailyMetric::query()
            ->where('brand_id', $brandId)
            ->where('dimension_type', 'product')
            ->whereBetween('date', [$from, $to])
            ->selectRaw('dimension_key,
                COALESCE(SUM(units), 0)          AS units,
                COALESCE(SUM(total_sales), 0)    AS total_sales,
                COALESCE(SUM(refunds_amount), 0) AS refunds,
                COALESCE(SUM((COALESCE(total_sales, 0) + COALESCE(refunds_amount, 0)) * COALESCE(fx_rate_to_usd, 1)), 0) AS revenue_usd')
            ->groupBy('dimension_key')
            ->get()
            ->keyBy('dimension_key');
    }

    /**
     * [from, to, priorFrom, priorTo] date strings (brand tz). The window ends
     * yesterday (today is partial), and the prior window is the equal-length span
     * immediately before it — for the units Δ%. A custom `to` past yesterday is
     * clamped back to yesterday (same rule as the presets), and a future `from`
     * collapses to the clamped `to` as a last resort.
     *
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private function window(string $period, string $tz, ?string $from, ?string $to): array
    {
        $now       = CarbonImmutable::now($tz);
        $yesterday = $now->subDay()->startOfDay();

        [$s, $e] = match ($period) {
            'last30' => [$yesterday->subDays(29), $yesterday],
            'mtd'    => [$now->startOfMonth(), $yesterday],
            'custom' => [
                CarbonImmutable::parse($from ?: $yesterday->subDays(6)->toDateString(), $tz)->startOfDay(),
                CarbonImmutable::parse($to ?: $yesterday->toDateString(), $tz)->startOfDay(),
            ],
            default  => [$yesterday->subDays(6), $yesterday], // last7
        };
        if ($e->greaterThan($yesterday)) {
            $e = $yesterday; // today is partial — never report past yesterday
        }
        if ($s->greaterThan($e)) {
            $s = $e; // covers from > to AND a last-resort future `from`
        }

        $len = (int) $s->diffInDays($e) + 1;
        $pe  = $s->subDay();
        $ps  = $pe->subDays($len - 1);

        return [$s->toDateString(), $e->toDateString(), $ps->toDateString(), $pe->toDateString()];
    }
}
