<?php

declare(strict_types=1);

namespace App\Reports\Monthly;

use App\Models\Brand;
use App\Models\DailyMetric;
use App\Reports\Contracts\ReportFilters;
use App\Reports\Contracts\ReportType;
use App\Reports\Support\MonthlySeries;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The monthly client report Roasdriven sends store owners — the long, scrollable
 * per-brand report (mockup: design-reference/monthly-report-mockup.html). It
 * leads with an "Overall picture" (headline KPIs vs targets + the agency's
 * editable commentary) and then a series of month-over-month heatmap sections.
 *
 * Build order (incremental — "ship the ready sections first"): this first slice
 * ships the Overall picture + the commerce MoM sections that run on data Helm
 * already syncs (country revenue, categories, best sellers, via MonthlySeries).
 * The remaining sections are declared with a readiness `status` so the SPA
 * renders them honestly ("coming" / "needs source") instead of hiding the plan:
 *   - coming:       built next, data is present (roas-by-country, gender, market,
 *                   placement [needs reach/freq], landing×sellers).
 *   - needs_source: blocked on a data probe (new-vs-existing = customer-type
 *                   probe; the two web funnels = a web-analytics source).
 *
 * Targets + commentary are editable agency content, merged onto the payload by
 * the controller (like the overall-performance report) — not computed here.
 * Currency + FX follow the report: native, or ×the stored fx snapshot for USD.
 */
final class MonthlyReport implements ReportType
{
    private const AD_PLATFORMS = ['meta', 'google', 'tiktok'];

    /** How many trailing calendar months the MoM heatmaps show. */
    private const TRAILING_MONTHS = 6;

    public function __construct(private readonly MonthlySeries $series) {}

    public function key(): string
    {
        return 'monthly';
    }

    public function label(): string
    {
        return 'Monthly report';
    }

    public function build(Brand $brand, ReportFilters $filters): array
    {
        $tz  = $brand->timezone ?: 'UTC';
        $now = CarbonImmutable::now($tz);

        // The report is inherently monthly: the "report month" is the last COMPLETE
        // calendar month (today's month is partial and never sent to a client).
        $monthEnd   = $now->startOfMonth()->subDay()->endOfDay();
        $monthStart = $monthEnd->startOfMonth();
        $reportMonth = $monthStart->format('Y-m');

        // Trailing Y-m columns for the heatmaps, chronological, ending on the
        // report month.
        $months = [];
        for ($i = self::TRAILING_MONTHS - 1; $i >= 0; $i--) {
            $months[] = $monthStart->subMonths($i)->format('Y-m');
        }

        $momStart = $monthStart->subMonth();
        $yoyStart = $monthStart->subYear();

        $cur = $this->monthMetrics($brand->id, $monthStart->toDateString(), $monthEnd->toDateString(), $filters->usd);
        $mom = $this->monthMetrics($brand->id, $momStart->toDateString(), $momStart->endOfMonth()->toDateString(), $filters->usd);

        $currency = $filters->usd ? 'USD' : ($brand->base_currency ?: 'USD');
        $limit    = 8;

        return [
            'reportType' => $this->key(),
            'brand' => [
                'name'         => $brand->name,
                'slug'         => $brand->slug,
                'baseCurrency' => $brand->base_currency,
                'timezone'     => $brand->timezone,
            ],
            'currency' => $currency,
            'month'    => [
                'label' => $monthStart->isoFormat('MMMM YYYY'),
                'start' => $monthStart->toDateString(),
                'end'   => $monthEnd->toDateString(),
            ],
            'comparison' => [
                'mom' => $momStart->isoFormat('MMMM YYYY'),
                'yoy' => $yoyStart->isoFormat('MMMM YYYY'),
            ],
            // Headline KPIs — value + MoM previous + delta. Targets are editable
            // content (merged by the controller); the SPA reads value vs target.
            // New-customer ROAS + acquisition YoY await the customer-type probe.
            'overall' => [
                'blendedRoas'     => $this->kpi('ratio', $cur['roas'], $mom['roas']),
                'revenue'         => $this->kpi('money', $cur['revenue'], $mom['revenue']),
                'adSpend'         => $this->kpi('money', $cur['totalSpend'], $mom['totalSpend']),
                'newCustomerRoas' => null, // pending customer-type probe
                'acquisitionYoY'  => null, // pending customer-type probe
            ],
            // Each section carries a readiness status so the SPA renders the whole
            // report structure, lighting up sections as their data lands.
            'sections' => [
                'countryRevenue' => $this->commerceSection('country', $brand->id, $months, $filters->usd, $limit),
                'categories'     => $this->commerceSection('category', $brand->id, $months, $filters->usd, $limit),
                'bestSellers'    => $this->commerceSection('product', $brand->id, $months, $filters->usd, $limit),
                'roasByCountry'  => ['status' => 'coming'],   // Meta country spend ÷ commerce country revenue, per month
                'gender'         => ['status' => 'coming'],   // meta_breakdown age_gender, folded to gender
                'market'         => ['status' => 'coming'],   // country → market/tier grouping config
                'placement'      => ['status' => 'coming'],   // placement breakdown + reach/frequency on the Meta pull
                'landingSellers' => ['status' => 'coming'],   // ad_product_daily ⋈ commerce product
                'newVsExisting'  => ['status' => 'needs_source', 'note' => 'ShopifyQL new/returning split — customer-type probe not yet verified.'],
                'funnelCountry'  => ['status' => 'needs_source', 'note' => 'Session → cart → checkout funnel needs a web-analytics source (GA4 / Shopify analytics).'],
                'funnelLanding'  => ['status' => 'needs_source', 'note' => 'Landing-path funnels need page-level session data from a web-analytics source.'],
            ],
        ];
    }

    /**
     * A commerce MoM section (country / category / product): the MonthlySeries
     * matrix, or a `no_data` status when the commerce backfill hasn't landed rows
     * for this dimension (missing ≠ zero — the SPA shows "not synced", never €0).
     * Fault-isolated so one empty dimension never 500s the whole report.
     *
     * @param array<int, string> $months
     * @return array<string, mixed>
     */
    private function commerceSection(string $dimension, int $brandId, array $months, bool $usd, int $limit): array
    {
        try {
            $data = $this->series->forDimension($brandId, $dimension, $months, $usd, $limit);
        } catch (Throwable $e) {
            Log::warning('monthly_report.section_failed', ['dimension' => $dimension, 'error' => $e->getMessage()]);

            return ['status' => 'no_data'];
        }

        return $data === null
            ? ['status' => 'no_data']
            : ['status' => 'ready', 'data' => $data];
    }

    /**
     * Blended ROAS + revenue for one month, in display currency. Mirrors the
     * overall-performance report: revenue = Shopify total_sales with refunds added
     * back; spend = summed across ad platforms; ROAS computed in USD so the ratio
     * is correct in either currency mode.
     *
     * @return array{revenue: float, totalSpend: float, roas: float|null}
     */
    private function monthMetrics(int $brandId, string $start, string $end, bool $usd): array
    {
        $disp = static fn (string $col): string => $usd ? "{$col} * COALESCE(fx_rate_to_usd, 1)" : $col;
        $usdc = static fn (string $col): string => "{$col} * COALESCE(fx_rate_to_usd, 1)";
        $revCol = '(COALESCE(total_sales, 0) + COALESCE(refunds_amount, 0))';

        $c = DailyMetric::query()
            ->where('brand_id', $brandId)
            ->where('platform', 'shopify')
            ->whereBetween('date', [$start, $end])
            ->selectRaw("COALESCE(SUM({$disp($revCol)}), 0) AS revenue, COALESCE(SUM({$usdc($revCol)}), 0) AS revenue_usd")
            ->first();

        $revenue    = (float) ($c->revenue ?? 0);
        $revenueUsd = (float) ($c->revenue_usd ?? 0);

        $totalSpend = 0.0;
        $totalSpendUsd = 0.0;
        foreach (self::AD_PLATFORMS as $p) {
            $s = DailyMetric::query()
                ->where('brand_id', $brandId)
                ->where('platform', $p)
                ->whereBetween('date', [$start, $end])
                ->selectRaw("COALESCE(SUM({$disp('spend')}), 0) AS spend, COALESCE(SUM({$usdc('spend')}), 0) AS spend_usd")
                ->first();
            $totalSpend    += (float) ($s->spend ?? 0);
            $totalSpendUsd += (float) ($s->spend_usd ?? 0);
        }

        return [
            'revenue'    => round($revenue, 2),
            'totalSpend' => round($totalSpend, 2),
            'roas'       => $totalSpendUsd > 0.0 ? round($revenueUsd / $totalSpendUsd, 2) : null,
        ];
    }

    /**
     * @return array{value: float|int|null, previous: float|int|null, deltaPct: ?float, deltaAbs: ?float}
     */
    private function kpi(string $kind, float|int|null $value, float|int|null $prev): array
    {
        return [
            'value'    => $value,
            'previous' => $prev,
            'deltaPct' => $kind === 'ratio' ? null : $this->pct($value, $prev),
            'deltaAbs' => $kind === 'ratio' && $value !== null && $prev !== null ? round((float) $value - (float) $prev, 2) : null,
        ];
    }

    private function pct(float|int|null $cur, float|int|null $prev): ?float
    {
        if ($cur === null || $prev === null || (float) $prev === 0.0) {
            return null;
        }

        return round(((float) $cur - (float) $prev) / (float) $prev * 100, 1);
    }
}
