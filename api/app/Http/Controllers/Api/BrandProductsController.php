<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\CommerceDailyMetric;
use App\Models\InventorySnapshot;
use App\Services\Rules\ProductFlags;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Product performance (feature spec §2 "Product" report row / slice 2.1
 * commerce data). Reads commerce_daily_metrics dimension_type='product' —
 * the granular table sync:daily keeps fresh — aggregated over the selected
 * window, with an equal-length prior window for deltas. Native currency
 * (each row also stores fx_rate_to_usd, but this page mirrors the brand
 * pages' native display).
 */
class BrandProductsController extends Controller
{
    private const MAX_ROWS = 100;

    public function __construct(private readonly ProductFlags $flags) {}

    public function index(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('view', $brand);

        $data = $request->validate([
            'period' => ['nullable', 'in:last7,last30,last90,mtd'],
            'search' => ['nullable', 'string', 'max:120'],
            'sort'   => ['nullable', 'in:revenue,units,delta,refunds,cover'],
        ]);

        $tz        = $brand->timezone ?: 'UTC';
        $yesterday = CarbonImmutable::now($tz)->subDay()->startOfDay();
        [$start, $end] = match ($data['period'] ?? 'last30') {
            'last7'  => [$yesterday->subDays(6), $yesterday],
            'last90' => [$yesterday->subDays(89), $yesterday],
            'mtd'    => [CarbonImmutable::now($tz)->startOfMonth(), $yesterday],
            default  => [$yesterday->subDays(29), $yesterday],
        };
        $len        = $start->diffInDays($end) + 1;
        $priorEnd   = $start->subDay();
        $priorStart = $priorEnd->subDays($len - 1);

        $window = fn (CarbonImmutable $s, CarbonImmutable $e) => CommerceDailyMetric::query()
            ->where('brand_id', $brand->id)
            ->where('dimension_type', 'product')
            ->whereBetween('date', [$s->toDateString(), $e->toDateString()])
            ->groupBy('dimension_key')
            ->selectRaw(
                'dimension_key,'
                . 'MAX(dimension_label) as label,'
                . 'COALESCE(SUM(COALESCE(total_sales, 0) + COALESCE(refunds_amount, 0)), 0) as revenue,'
                . 'COALESCE(SUM(COALESCE(orders, 0)), 0) as orders,'
                . 'COALESCE(SUM(COALESCE(units, 0)), 0)  as units,'
                . 'COALESCE(SUM(COALESCE(refunds_amount, 0)), 0) as refunds'
            );

        $current = $window($start, $end)
            ->when($data['search'] ?? null, fn ($q, $v) => $q->where('dimension_key', 'like', "%{$v}%"))
            ->orderByDesc('revenue')
            ->limit(self::MAX_ROWS)
            ->get();

        // Prior revenue for just the products on screen — one query.
        $prior = $current->isEmpty() ? collect() : $window($priorStart, $priorEnd)
            ->whereIn('dimension_key', $current->pluck('dimension_key')->all())
            ->get()
            ->keyBy('dimension_key');

        $totalRevenue = (float) $current->sum('revenue');

        // One rules engine drives these flags AND the audit cards + reports —
        // computed over the whole window so ABC grading sees the full catalog.
        $flagMap = $this->flags->forBrand($brand->id, $start, $end);

        $rows = $current->map(function ($r) use ($prior, $totalRevenue, $flagMap): array {
            $key     = (string) $r->dimension_key;
            $revenue = round((float) $r->revenue, 2);
            $refunds = round((float) $r->refunds, 2);
            $orders  = (int) $r->orders;
            $prevRev = $prior->has($key) ? round((float) $prior[$key]->revenue, 2) : null;
            $f       = $flagMap[$key] ?? null;

            return [
                'key'        => $key,
                'title'      => (string) ($r->label ?: $key),
                'revenue'    => $revenue,
                'orders'     => $orders,
                'units'      => (int) $r->units,
                'refunds'    => $refunds,
                // Money-based refund rate: refunded amount as % of revenue.
                'refundRatePct' => $revenue > 0 ? round($refunds / $revenue * 100, 1) : null,
                'sharePct'   => $totalRevenue > 0 ? round($revenue / $totalRevenue * 100, 1) : null,
                'prevRevenue' => $prevRev,
                'deltaPct'   => ($prevRev !== null && $prevRev > 0) ? round(($revenue - $prevRev) / $prevRev * 100, 1) : null,
                // Phase 1 additions (additive) — merged from ProductFlags by key.
                'aov'        => $orders > 0 ? round($revenue / $orders, 2) : null,
                'abc'        => $f['abc'] ?? null,
                'coverDays'  => $f['coverDays'] ?? null,
                'sellThroughPct' => $f['sellThroughPct'] ?? null,
                'flags'      => $f['flags'] ?? [],
            ];
        })->all();

        // Sort the displayed rows (the top-100 selection stays by revenue). Cover
        // and delta sort ascending (lowest cover / steepest drop first, nulls last).
        $sort = $data['sort'] ?? 'revenue';
        usort($rows, static fn (array $a, array $b): int => match ($sort) {
            'units'   => $b['units'] <=> $a['units'],
            'refunds' => ($b['refundRatePct'] ?? -1) <=> ($a['refundRatePct'] ?? -1),
            'delta'   => ($a['deltaPct'] ?? 0) <=> ($b['deltaPct'] ?? 0),
            'cover'   => ($a['coverDays'] ?? PHP_INT_MAX) <=> ($b['coverDays'] ?? PHP_INT_MAX),
            default   => $b['revenue'] <=> $a['revenue'],
        });

        // Freshness: when was the product dimension last pulled + the stock snapshot.
        $lastPulled = CommerceDailyMetric::query()
            ->where('brand_id', $brand->id)
            ->where('dimension_type', 'product')
            ->max('pulled_at');

        $snapshotOn = InventorySnapshot::query()
            ->where('brand_id', $brand->id)
            ->where('dimension_type', 'product')
            ->max('captured_on');

        return response()->json([
            'currency'    => $brand->base_currency,
            'periodStart' => $start->toDateString(),
            'periodEnd'   => $end->toDateString(),
            'rows'        => $rows,
            'totalRevenue' => round($totalRevenue, 2),
            'hasData'     => $rows !== [],
            'lastPulledAt' => $lastPulled ? CarbonImmutable::parse((string) $lastPulled)->toIso8601String() : null,
            'inventorySnapshotAt' => $snapshotOn ? CarbonImmutable::parse((string) $snapshotOn)->toDateString() : null,
        ]);
    }
}
