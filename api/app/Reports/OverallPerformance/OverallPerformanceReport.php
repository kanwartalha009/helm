<?php

declare(strict_types=1);

namespace App\Reports\OverallPerformance;

use App\Models\Brand;
use App\Models\DailyMetric;
use App\Models\PlatformConnection;
use App\Reports\Contracts\ReportFilters;
use App\Reports\Contracts\ReportType;
use App\Reports\Support\AdAudit;
use App\Reports\Support\CommerceBreakdown;
use App\Reports\Support\DeadInventory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The brand's sendable monthly report. Headline runs on data Helm already syncs:
 * brand-total revenue (Shopify total_sales), ad spend per platform, orders —
 * revenue vs ad spend vs blended ROAS for the period and the comparison window.
 *
 * Slice 2.1 adds the by-region / by-product / by-category sections from
 * commerce_daily_metrics. They're folded in here (not separate report types)
 * because Bosco sends ONE report per brand; each section is present only when
 * the commerce backfill has landed rows for that brand and window, so the report
 * degrades cleanly before 2.1 data exists and lights up after.
 *
 * Currency + FX follow the dashboard: native by default, or USD (× the stored
 * fx_rate snapshot) when requested. ROAS is computed in USD so the ratio is
 * correct in either mode. Missing ≠ zero — an unconnected ad platform reports
 * `connected: false`, never spend 0 (spec rule 9).
 */
final class OverallPerformanceReport implements ReportType
{
    private const AD_PLATFORMS = ['meta', 'google', 'tiktok'];

    public function __construct(
        private readonly CommerceBreakdown $commerce,
        private readonly AdAudit $ads,
        private readonly DeadInventory $inventory,
    ) {}

    public function key(): string
    {
        return 'overall-performance';
    }

    public function label(): string
    {
        return 'Overall performance';
    }

    public function build(Brand $brand, ReportFilters $filters): array
    {
        $tz             = $brand->timezone ?: 'UTC';
        [$start, $end]  = $filters->window($tz);
        [$cStart, $cEnd] = $filters->comparisonWindow($tz);

        /** @var array<int, string> $connected */
        $connected = PlatformConnection::query()
            ->where('brand_id', $brand->id)
            ->where('status', 'active')
            ->pluck('platform')
            ->unique()
            ->values()
            ->all();

        $cur  = $this->metrics($brand->id, $start, $end, $filters->usd);
        $prev = $cStart !== null ? $this->metrics($brand->id, $cStart, $cEnd, $filters->usd) : null;

        $currency = $filters->usd ? 'USD' : ($brand->base_currency ?: 'USD');

        return [
            'reportType' => $this->key(),
            'brand' => [
                'name'         => $brand->name,
                'slug'         => $brand->slug,
                'baseCurrency' => $brand->base_currency,
                'timezone'     => $brand->timezone,
            ],
            'currency'   => $currency,
            'period'     => ['label' => $filters->periodLabel(), 'start' => $start, 'end' => $end],
            'comparison' => $cStart !== null
                ? ['label' => $filters->comparisonLabel(), 'start' => $cStart, 'end' => $cEnd]
                : null,
            'kpis'           => $this->kpis($cur, $prev),
            'revenueVsSpend' => $this->rows($cur, $prev),
            'byPlatform'     => $this->byPlatform($cur, $connected),
            // True only when every ad platform is connected; the SPA uses this to
            // caption blended ROAS honestly ("Meta only" etc.).
            'spendComplete'  => count(array_intersect(self::AD_PLATFORMS, $connected)) === count(self::AD_PLATFORMS),
            // Granular commerce (slice 2.1). null until shopify:backfill-commerce
            // has landed rows for this brand/window — the SPA omits the section.
            // Each enrichment is fault-isolated: a failure in one new section logs
            // and drops to a safe default rather than 500-ing the whole report.
            'byRegion'   => $this->safely('byRegion', fn () => $this->commerce->forDimension($brand->id, 'country',  $start, $end, $cStart, $cEnd, $filters->usd), null),
            'byProduct'  => $this->safely('byProduct', fn () => $this->commerce->forDimension($brand->id, 'product',  $start, $end, $cStart, $cEnd, $filters->usd), null),
            'byCategory' => $this->safely('byCategory', fn () => $this->commerce->forDimension($brand->id, 'category', $start, $end, $cStart, $cEnd, $filters->usd), null),
            // Campaign-level Meta + Google audit (slice 2.2 / 2.4). One entry per
            // connected ad platform that has campaign rows; null/absent until
            // ads:backfill-campaigns has run — the SPA omits the section.
            'adsAudit'   => $this->safely('adsAudit', fn () => $this->adsAudit($brand->id, $connected, $start, $end, $cStart, $cEnd, $filters->usd), []),
            // Dead / overstocked stock from the latest inventory snapshot
            // (slice 2.1). Null until shopify:sync-inventory has run.
            'deadInventory' => $this->safely('deadInventory', fn () => $this->deadInventory($brand->id), null),
            // Is the data current for this window? The SPA prompts a fresh sync
            // before trusting the numbers when this says we're behind. On error
            // the gate FAILS CLOSED (upToDate: false) — a freshness bug must
            // never un-gate a stale report a client could receive.
            'freshness'  => $this->safely('freshness', fn () => $this->freshness($brand->id, $end), [
                'upToDate' => false, 'lastSynced' => null, 'staleDays' => 0, 'windowEnd' => $end,
                'note'     => 'Freshness could not be verified — the report is held back until a sync confirms the data is current.',
            ]),
        ];
    }

    /**
     * Run an optional report section in isolation: on any failure, log it with
     * the section name + location and fall back to $default, so a single broken
     * enrichment degrades just that section instead of 500-ing the whole report.
     *
     * @param \Closure(): mixed $fn
     */
    private function safely(string $section, \Closure $fn, mixed $default): mixed
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            Log::warning('report.section_failed', [
                'section' => $section,
                'error'   => $e->getMessage(),
                'at'      => $e->getFile() . ':' . $e->getLine(),
            ]);

            return $default;
        }
    }

    /**
     * Campaign-level audit per connected ad platform — Meta, Google, TikTok.
     * Each platform with campaign rows in the window returns an audit block;
     * platforms without data are simply absent (missing ≠ zero).
     *
     * @param array<int, string> $connected
     * @return array<int, array<string, mixed>>
     */
    private function adsAudit(int $brandId, array $connected, string $start, string $end, ?string $cStart, ?string $cEnd, bool $usd): array
    {
        $out = [];
        foreach (self::AD_PLATFORMS as $platform) {
            if (! in_array($platform, $connected, true)) {
                continue;
            }
            $audit = $this->ads->forPlatform($brandId, $platform, $start, $end, $cStart, $cEnd, $usd);
            if ($audit !== null) {
                $out[] = $audit;
            }
        }

        return $out;
    }

    /**
     * Is the report's data current? Compares the latest COMPLETE Shopify day on
     * file against the window end (yesterday). When behind, the SPA blocks the
     * report behind a "sync fresh data first" gate so a client never receives
     * stale numbers.
     *
     * @return array<string, mixed>
     */
    private function freshness(int $brandId, string $windowEnd): array
    {
        $lastComplete = DailyMetric::query()
            ->where('brand_id', $brandId)
            ->where('platform', 'shopify')
            ->where('is_complete', true)
            ->max('date');

        $end  = CarbonImmutable::parse($windowEnd)->startOfDay();
        $last = $lastComplete !== null ? CarbonImmutable::parse((string) $lastComplete)->startOfDay() : null;

        return [
            'upToDate'   => $last !== null && $last->greaterThanOrEqualTo($end),
            'lastSynced' => $last?->toDateString(),
            'staleDays'  => ($last !== null && $last->lessThan($end)) ? (int) $last->diffInDays($end) : 0,
            'windowEnd'  => $end->toDateString(),
        ];
    }

    /**
     * Latest dead-stock snapshot, PRODUCT ONLY — null when no inventory
     * snapshot exists yet (shopify:sync-inventory hasn't run). Decision
     * ratified: a collection mixes dead and healthy products, so
     * collection-level "dead" is misleading; the product list is the
     * actionable one.
     *
     * @return array<string, mixed>|null
     */
    private function deadInventory(int $brandId): ?array
    {
        $byProduct = $this->inventory->forDimension($brandId, 'product');
        if ($byProduct === null) {
            return null;
        }

        return ['byProduct' => $byProduct];
    }

    /** @return array<string, mixed> */
    private function metrics(int $brandId, string $start, string $end, bool $usd): array
    {
        $disp = static fn (string $col): string => $usd ? "{$col} * COALESCE(fx_rate_to_usd, 1)" : $col;
        $usdc = static fn (string $col): string => "{$col} * COALESCE(fx_rate_to_usd, 1)";

        // Total revenue = Shopify total_sales WITH refunds added back (Bosco
        // 2026-06-25) — total_sales already nets returns out, so add them back.
        // AOV and blended ROAS derive from this, so they follow automatically.
        $revCol = '(COALESCE(total_sales, 0) + COALESCE(refunds_amount, 0))';

        $c = DailyMetric::query()
            ->where('brand_id', $brandId)
            ->where('platform', 'shopify')
            ->whereBetween('date', [$start, $end])
            ->selectRaw("
                COALESCE(SUM({$disp($revCol)}), 0) AS revenue,
                COALESCE(SUM({$usdc($revCol)}), 0) AS revenue_usd,
                COALESCE(SUM({$disp('net_sales')}), 0)   AS net_sales,
                COALESCE(SUM(orders), 0)                 AS orders
            ")
            ->first();

        $revenue    = (float) ($c->revenue ?? 0);
        $revenueUsd = (float) ($c->revenue_usd ?? 0);
        $orders     = (int) ($c->orders ?? 0);

        $spendByPlatform = [];
        $totalSpend = 0.0;
        $totalSpendUsd = 0.0;
        foreach (self::AD_PLATFORMS as $p) {
            $s = DailyMetric::query()
                ->where('brand_id', $brandId)
                ->where('platform', $p)
                ->whereBetween('date', [$start, $end])
                ->selectRaw("COUNT(*) AS n, COALESCE(SUM({$disp('spend')}), 0) AS spend, COALESCE(SUM({$usdc('spend')}), 0) AS spend_usd")
                ->first();
            $sp    = round((float) ($s->spend ?? 0), 2);
            $spUsd = (float) ($s->spend_usd ?? 0);

            // Missing ≠ zero: a connected platform with NO rows in the window
            // hasn't synced yet — null (the SPA says "not synced"), never €0.
            $spendByPlatform[$p] = ((int) ($s->n ?? 0)) > 0 ? $sp : null;
            $totalSpend    += $sp;
            $totalSpendUsd += $spUsd;
        }

        return [
            'revenue'         => round($revenue, 2),
            'netSales'        => round((float) ($c->net_sales ?? 0), 2),
            'orders'          => $orders,
            'aov'             => $orders > 0 ? round($revenue / $orders, 2) : null,
            'spendByPlatform' => $spendByPlatform,
            'totalSpend'      => round($totalSpend, 2),
            'roas'            => $totalSpendUsd > 0.0 ? round($revenueUsd / $totalSpendUsd, 2) : null,
        ];
    }

    /**
     * @param array<string, mixed> $cur
     * @param array<string, mixed>|null $prev
     * @return array<string, mixed>
     */
    private function kpis(array $cur, ?array $prev): array
    {
        return [
            'revenue'     => $this->kpi('money', $cur['revenue'], $prev['revenue'] ?? null),
            'adSpend'     => $this->kpi('money', $cur['totalSpend'], $prev['totalSpend'] ?? null),
            'blendedRoas' => $this->kpi('ratio', $cur['roas'], $prev['roas'] ?? null),
            'orders'      => $this->kpi('int', $cur['orders'], $prev['orders'] ?? null),
            'aov'         => $this->kpi('money', $cur['aov'], $prev['aov'] ?? null),
        ];
    }

    /** @return array{value: float|int|null, previous: float|int|null, deltaPct: ?float, deltaAbs: ?float} */
    private function kpi(string $kind, float|int|null $value, float|int|null $prev): array
    {
        return [
            'value'    => $value,
            'previous' => $prev,
            'deltaPct' => $kind === 'ratio' ? null : $this->pct($value, $prev),
            'deltaAbs' => $kind === 'ratio' && $value !== null && $prev !== null ? round((float) $value - (float) $prev, 2) : null,
        ];
    }

    /**
     * @param array<string, mixed> $cur
     * @param array<string, mixed>|null $prev
     * @return array<int, array<string, mixed>>
     */
    private function rows(array $cur, ?array $prev): array
    {
        $revAfter     = round((float) $cur['revenue'] - (float) $cur['totalSpend'], 2);
        $prevRevAfter = $prev !== null ? round((float) $prev['revenue'] - (float) $prev['totalSpend'], 2) : null;

        return [
            $this->row('Total revenue', 'money', $cur['revenue'], $prev['revenue'] ?? null),
            $this->row('Ad spend', 'money', $cur['totalSpend'], $prev['totalSpend'] ?? null),
            $this->row('Revenue after ad spend', 'money', $revAfter, $prevRevAfter),
            $this->row('Revenue ÷ ad spend', 'ratio', $cur['roas'], $prev['roas'] ?? null),
            $this->row('Orders', 'int', $cur['orders'], $prev['orders'] ?? null),
            $this->row('Average order value', 'money', $cur['aov'], $prev['aov'] ?? null),
        ];
    }

    /** @return array<string, mixed> */
    private function row(string $label, string $kind, float|int|null $value, float|int|null $prev): array
    {
        return [
            'label'    => $label,
            'kind'     => $kind,
            'value'    => $value,
            'previous' => $prev,
            'deltaPct' => $kind === 'ratio' ? null : $this->pct($value, $prev),
            'deltaAbs' => $kind === 'ratio' && $value !== null && $prev !== null ? round((float) $value - (float) $prev, 2) : null,
        ];
    }

    /**
     * @param array<string, mixed> $cur
     * @param array<int, string> $connected
     * @return array<int, array<string, mixed>>
     */
    private function byPlatform(array $cur, array $connected): array
    {
        $out = [];
        foreach (self::AD_PLATFORMS as $p) {
            $isConnected = in_array($p, $connected, true);
            $out[] = [
                'platform'  => $p,
                'connected' => $isConnected,
                // Null both when the platform isn't connected AND when it's
                // connected but has no synced rows in the window (missing ≠ 0).
                'spend'     => $isConnected ? ($cur['spendByPlatform'][$p] ?? null) : null,
            ];
        }

        return $out;
    }

    private function pct(float|int|null $cur, float|int|null $prev): ?float
    {
        if ($cur === null || $prev === null || (float) $prev === 0.0) {
            return null;
        }

        return round(((float) $cur - (float) $prev) / (float) $prev * 100, 1);
    }
}
