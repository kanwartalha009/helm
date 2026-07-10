<?php

declare(strict_types=1);

namespace App\Services\Rules;

use App\Models\CommerceDailyMetric;
use App\Models\InventorySnapshot;
use App\Reports\Support\DeadInventory;
use Carbon\CarbonImmutable;

/**
 * Deterministic product underperformer engine — spec §4 Phase 1
 * (docs/feature-specs/product-audit-adset-underperformers.md).
 *
 * Pure DB reads, NO HTTP, NO LLM. This is the ONE source of truth for a product's
 * ABC grade + flags; the products page, the store-audit cards (Phase 2) and reports
 * all read it, so a product is never "fine" on one page and flagged on another.
 *
 * Key alignment: everything joins on product_title — commerce_daily_metrics(product),
 * inventory_snapshots(product) and ShopifySyncInventoryCommand all key on it.
 * Every threshold comes ONLY from config/rules.php (never hardcoded twice).
 * Missing ≠ zero: no snapshot ⇒ coverDays/sellThroughPct are null ("—"), never 0.
 */
final class ProductFlags
{
    public function __construct(private readonly DeadInventory $deadInventory) {}

    /**
     * @return array<string, array{abc: ?string, coverDays: ?int, sellThroughPct: ?float, flags: list<array{key:string,severity:string,label:string,detail:string}>}>
     *         keyed by product_title
     */
    public function forBrand(int $brandId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $cfg   = (array) config('rules.product');
        $floor = (float) ($cfg['decline_floor_usd'] ?? 100);
        $len   = $start->diffInDays($end) + 1;

        $priorEnd   = $start->subDay();
        $priorStart = $priorEnd->subDays($len - 1);

        $cur   = $this->aggregate($brandId, $start, $end);
        if ($cur === []) {
            return [];
        }
        $prior = $this->aggregate($brandId, $priorStart, $priorEnd);
        $snap  = $this->latestSnapshot($brandId);
        $dead  = $this->deadStatuses($brandId);
        $abc   = $this->abcGrades($cur, (array) ($cfg['abc'] ?? ['a' => 80, 'b' => 95]));

        // Concentration is a brand-level signal emitted on the single top product.
        $totalRev = 0.0;
        $topKey   = null;
        $topRev   = -1.0;
        foreach ($cur as $key => $c) {
            $totalRev += $c['revenue'];
            if ($c['revenue'] > $topRev) {
                $topRev = $c['revenue'];
                $topKey = $key;
            }
        }

        $out = [];
        foreach ($cur as $key => $c) {
            $flags = [];

            // declining — [HELM DEFAULT]; both windows above the USD floor.
            $priorUsd = $prior[$key]['revenueUsd'] ?? 0.0;
            if ($c['revenueUsd'] >= $floor && $priorUsd >= $floor) {
                $drop = ($priorUsd - $c['revenueUsd']) / $priorUsd * 100;
                if ($drop >= (float) ($cfg['decline_pct'] ?? 30)) {
                    $flags[] = $this->flag('declining', 'warn', 'Declining',
                        'Revenue −' . round($drop, 1) . '% vs the previous ' . $len . ' days.');
                }
            }

            // high_refunds — money-based (Shopify gives refund amounts, not units).
            $refundRate = $c['revenue'] > 0 ? $c['refunds'] / $c['revenue'] * 100 : 0.0;
            if ($c['revenueUsd'] >= $floor && $refundRate >= (float) ($cfg['refund_warn_pct'] ?? 15)) {
                $crit = $refundRate >= (float) ($cfg['refund_crit_pct'] ?? 25);
                $flags[] = $this->flag('high_refunds', $crit ? 'critical' : 'warn', 'High refunds',
                    'Refund rate (by value) ' . round($refundRate, 1) . '% of revenue. Money-based, so comparable in spirit to the 20–40% apparel benchmark, not identical.');
            }

            // stockout_risk — selling AND under the cover-low threshold.
            $s     = $snap[$key] ?? null;
            $cover = null;
            $sell  = null;
            if ($s !== null) {
                $ending = (int) ($s['ending_units'] ?? 0);
                $sold   = (int) ($s['units_sold'] ?? 0);
                $win    = (int) ($s['window_days'] ?: 90);
                $cover  = $sold > 0 ? (int) round($ending * $win / $sold) : null;
                $sell   = $s['sell_through_rate'] !== null ? round((float) $s['sell_through_rate'], 1) : null;
                if ($c['units'] > 0 && $cover !== null && $cover < (int) ($cfg['cover_low_days'] ?? 28)) {
                    $flags[] = $this->flag('stockout_risk', 'warn', 'Stockout risk',
                        'About ' . $cover . ' days of stock left at the current sell rate.');
                }
            }

            // dead / slow — delegated VERBATIM to DeadInventory (one source of truth).
            $status = $dead[$key] ?? null;
            if ($status === 'dead') {
                $flags[] = $this->flag('dead_stock', 'warn', 'Dead stock',
                    'In stock but nothing sold in the last 90 days.');
            } elseif ($status === 'slow') {
                $flags[] = $this->flag('slow_mover', 'info', 'Slow mover',
                    'Over ' . (int) ($cfg['cover_high_days'] ?? 180) . ' days of stock at the current sell rate — overstocked.');
            }

            // concentration — top product only, info-level.
            if ($key === $topKey && $totalRev > 0) {
                $share = $c['revenue'] / $totalRev * 100;
                if ($share >= (float) ($cfg['concentration_pct'] ?? 15)) {
                    $flags[] = $this->flag('concentration', 'info', 'Concentration',
                        'This one product is ' . round($share, 1) . '% of revenue this window — a supply or ad hiccup on it moves the whole brand.');
                }
            }

            $out[$key] = [
                'abc'            => $abc[$key] ?? null,
                'coverDays'      => $cover,
                'sellThroughPct' => $sell,
                'flags'          => $flags,
            ];
        }

        return $out;
    }

    /**
     * Per-product window aggregate keyed by product_title. Revenue is the D-005
     * basis (total_sales + refunds); revenue_usd applies the stored fx snapshot so
     * the USD floors compare correctly across currencies.
     *
     * @return array<string, array{revenue: float, revenueUsd: float, refunds: float, units: int, orders: int}>
     */
    private function aggregate(int $brandId, CarbonImmutable $s, CarbonImmutable $e): array
    {
        $rows = CommerceDailyMetric::query()
            ->where('brand_id', $brandId)
            ->where('dimension_type', 'product')
            ->whereBetween('date', [$s->toDateString(), $e->toDateString()])
            ->groupBy('dimension_key')
            ->selectRaw(
                'dimension_key,'
                . 'COALESCE(SUM(COALESCE(total_sales,0) + COALESCE(refunds_amount,0)), 0) as revenue,'
                . 'COALESCE(SUM((COALESCE(total_sales,0) + COALESCE(refunds_amount,0)) * COALESCE(fx_rate_to_usd,1)), 0) as revenue_usd,'
                . 'COALESCE(SUM(COALESCE(refunds_amount,0)), 0) as refunds,'
                . 'COALESCE(SUM(COALESCE(units,0)), 0) as units,'
                . 'COALESCE(SUM(COALESCE(orders,0)), 0) as orders'
            )
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $key = (string) $r->dimension_key;
            if ($key === '') {
                continue;
            }
            $out[$key] = [
                'revenue'    => round((float) $r->revenue, 2),
                'revenueUsd' => round((float) $r->revenue_usd, 2),
                'refunds'    => round((float) $r->refunds, 2),
                'units'      => (int) $r->units,
                'orders'     => (int) $r->orders,
            ];
        }

        return $out;
    }

    /**
     * Latest inventory snapshot per product_title (cover + sell-through inputs).
     *
     * @return array<string, array{ending_units: ?int, units_sold: ?int, sell_through_rate: ?string, window_days: int}>
     */
    private function latestSnapshot(int $brandId): array
    {
        $capturedOn = InventorySnapshot::query()
            ->where('brand_id', $brandId)
            ->where('dimension_type', 'product')
            ->max('captured_on');
        if ($capturedOn === null) {
            return [];
        }
        $date = CarbonImmutable::parse((string) $capturedOn)->toDateString();

        $rows = InventorySnapshot::query()
            ->where('brand_id', $brandId)
            ->where('dimension_type', 'product')
            ->whereDate('captured_on', $date)
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->dimension_key] = [
                'ending_units'      => $r->ending_units,
                'units_sold'        => $r->units_sold,
                'sell_through_rate' => $r->sell_through_rate,
                'window_days'       => (int) ($r->window_days ?: 90),
            ];
        }

        return $out;
    }

    /**
     * product_title => 'dead' | 'slow', delegated to DeadInventory so the rule
     * lives in exactly one place. Large limit so every flagged product is covered.
     *
     * @return array<string, string>
     */
    private function deadStatuses(int $brandId): array
    {
        $res = $this->deadInventory->forDimension($brandId, 'product', 100000);
        if ($res === null) {
            return [];
        }

        $out = [];
        foreach ($res['rows'] as $row) {
            $out[(string) $row['key']] = (string) $row['status'];
        }

        return $out;
    }

    /**
     * ABC grade per product (Shopify's 80/95 cumulative-revenue method). A product
     * is A while the revenue ranked ABOVE it is under the A cutoff — so the top
     * product is always A even under high concentration. Only graded when the brand
     * has ≥10 products with revenue this window (below that it's noise → "—").
     *
     * @param array<string, array{revenue: float, revenueUsd: float, refunds: float, units: int, orders: int}> $cur
     * @param array{a?: int|float, b?: int|float} $abcCfg
     * @return array<string, string>
     */
    private function abcGrades(array $cur, array $abcCfg): array
    {
        $withRev = array_filter($cur, static fn (array $c): bool => $c['revenue'] > 0);
        if (count($withRev) < 10) {
            return [];
        }
        $total = 0.0;
        foreach ($withRev as $c) {
            $total += $c['revenue'];
        }
        if ($total <= 0.0) {
            return [];
        }

        uasort($withRev, static fn (array $a, array $b): int => $b['revenue'] <=> $a['revenue']);

        $aCut = (float) ($abcCfg['a'] ?? 80);
        $bCut = (float) ($abcCfg['b'] ?? 95);
        $cum  = 0.0;
        $out  = [];
        foreach ($withRev as $key => $c) {
            $before = $cum; // cumulative share of everything ranked above this product
            $cum   += $c['revenue'] / $total * 100;
            $out[(string) $key] = $before < $aCut ? 'A' : ($before < $bCut ? 'B' : 'C');
        }

        return $out;
    }

    /** @return array{key: string, severity: string, label: string, detail: string} */
    private function flag(string $key, string $severity, string $label, string $detail): array
    {
        return ['key' => $key, 'severity' => $severity, 'label' => $label, 'detail' => $detail];
    }
}
