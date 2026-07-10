<?php

declare(strict_types=1);

namespace App\Reports\Monthly;

use App\Models\AdProductDaily;
use App\Models\Brand;
use App\Models\CommerceDailyMetric;
use App\Models\DailyMetric;
use App\Models\MetaBreakdownDaily;
use App\Models\PlatformConnection;
use App\Models\ProductCatalog;
use App\Models\ShopifyFunnelDaily;
use App\Platforms\Meta\InsightsFetcher;
use App\Platforms\Shopify\RevenueFetcher;
use App\Reports\Contracts\ReportFilters;
use App\Reports\Contracts\ReportType;
use App\Reports\Support\MonthlySeries;
use App\Services\Currency\FxService;
use App\Support\CountryCodes;
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

    public function __construct(
        private readonly MonthlySeries $series,
        private readonly RevenueFetcher $revenue,
        private readonly InsightsFetcher $insights,
        private readonly FxService $fx,
    ) {}

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

        // §4 is built here (not inline in `sections`) so the Overall picture can
        // surface its ESTIMATED new-customer ROAS for the report month. Built once,
        // reused below — one ShopifyQL customer pull.
        $newVsExisting = $this->newVsExistingSection($brand, $months, $filters->usd);
        $curNewRoas    = $this->newRoasForMonth($newVsExisting, $monthStart->isoFormat('MMM YY'));
        $prevNewRoas   = $this->newRoasForMonth($newVsExisting, $momStart->isoFormat('MMM YY'));

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
                'newCustomerRoas' => $curNewRoas === null ? null : $this->kpi('ratio', $curNewRoas, $prevNewRoas),
                'acquisitionYoY'  => null, // unavailable: needs same-month-last-year new-customer counts
            ],
            // Each section carries a readiness status so the SPA renders the whole
            // report structure, lighting up sections as their data lands.
            'sections' => [
                'countryRevenue' => $this->commerceSection('country', $brand->id, $months, $filters->usd, $limit),
                'categories'     => $this->commerceSection('category', $brand->id, $months, $filters->usd, $limit),
                'bestSellers'    => $this->commerceSection('product', $brand->id, $months, $filters->usd, $limit),
                'market'         => $this->marketSection($brand->id, $months, $filters->usd),
                'gender'         => $this->genderSection($brand, $monthStart->toDateString(), $monthEnd->toDateString(), $filters->usd),
                'roasByCountry'  => $this->roasByCountrySection($brand->id, $months, $filters->usd),
                'placement'      => $this->placementSection($brand, $monthStart->toDateString(), $monthEnd->toDateString(), $filters->usd),
                'landingSellers' => $this->landingSellersSection($brand->id, $monthStart->toDateString(), $monthEnd->toDateString()),
                'newVsExisting'  => $newVsExisting,
                'funnelCountry'  => $this->funnelSection($brand->id, 'country', $monthStart->toDateString(), $monthEnd->toDateString()),
                'funnelLanding'  => $this->funnelSection($brand->id, 'landing', $monthStart->toDateString(), $monthEnd->toDateString()),
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
        // Shopify product_type / product_title come back with inconsistent casing
        // and stray whitespace ("T-shirt" vs "T-Shirt"), which otherwise split one
        // category across two rows and manufacture €0 months. Fold case/space
        // variants onto one canonical key (label keeps the first real spelling).
        $keyMap = in_array($dimension, ['category', 'product'], true)
            ? static fn (string $k): string => mb_strtolower(trim($k))
            : null;

        try {
            $data = $this->series->forDimension($brandId, $dimension, $months, $usd, $limit, null, [], $keyMap);
        } catch (Throwable $e) {
            Log::warning('monthly_report.section_failed', ['dimension' => $dimension, 'error' => $e->getMessage()]);

            return ['status' => 'no_data'];
        }

        return $data === null
            ? ['status' => 'no_data']
            : ['status' => 'ready', 'data' => $data];
    }

    /**
     * Market/tier revenue MoM — the commerce country series folded into markets
     * via the country_regions config (Europe / North America / … — the same map
     * the dashboard region rollup uses; Bosco's bespoke tiers become a per-brand
     * override later, [[helm_white_label]]). Reuses MonthlySeries + renders in the
     * same heat table as country. "coming" when no market map is configured.
     *
     * @param array<int, string> $months
     * @return array<string, mixed>
     */
    private function marketSection(int $brandId, array $months, bool $usd): array
    {
        $map    = array_change_key_case((array) config('country_regions.map', []), CASE_UPPER);
        $labels = (array) config('country_regions.labels', []);
        if ($map === []) {
            return ['status' => 'coming'];
        }

        try {
            // Fold Shopify country NAMES → ISO-2 first, so the code-keyed region
            // map matches instead of dumping everything into "Other".
            $data = $this->series->forDimension($brandId, 'country', $months, $usd, 8, $map, $labels, CountryCodes::toIso2(...));
        } catch (Throwable $e) {
            Log::warning('monthly_report.section_failed', ['dimension' => 'market', 'error' => $e->getMessage()]);

            return ['status' => 'no_data'];
        }

        return $data === null ? ['status' => 'no_data'] : ['status' => 'ready', 'data' => $data];
    }

    /**
     * Ad spend by gender for the report month — meta_breakdown_daily[age_gender]
     * folded onto the gender axis (mirrors the dashboard Audience fold). Cost /
     * clicks / CPC / CTR / CPM / share. Reach + frequency aren't stored on the
     * breakdown yet, so they're intentionally absent (added with the Meta pull).
     *
     * @return array<string, mixed>
     */
    private function genderSection(Brand $brand, string $start, string $end, bool $usd): array
    {
        $conn = $this->metaConnection($brand->id);
        if ($conn === null) {
            return ['status' => 'no_data'];
        }

        try {
            $segments = $this->insights->fetchBreakdownTotals($conn, ['age', 'gender'], CarbonImmutable::parse($start), CarbonImmutable::parse($end));
        } catch (Throwable $e) {
            Log::warning('monthly_report.section_failed', ['dimension' => 'gender', 'error' => $e->getMessage()]);

            return ['status' => 'no_data'];
        }

        if ($segments === []) {
            return ['status' => 'no_data'];
        }

        // Fold age × gender onto the gender axis. Age buckets are disjoint, so a
        // person lands in exactly one age×gender cell — summing the cells for a
        // gender gives that gender's unique reach (correct, unlike summing days).
        $folded = [];
        foreach ($segments as $s) {
            $g = $this->genderOf((string) $s['key']);
            $folded[$g] ??= [
                'key' => $g, 'label' => ucfirst($g),
                'spend' => 0.0, 'impressions' => 0, 'clicks' => 0, 'reach' => 0,
                'conversions' => 0, 'conversion_value' => 0.0,
            ];
            $folded[$g]['spend']            += (float) $s['spend'];
            $folded[$g]['impressions']      += (int) $s['impressions'];
            $folded[$g]['clicks']           += (int) $s['clicks'];
            $folded[$g]['reach']            += (int) $s['reach'];
            $folded[$g]['conversions']      += (int) $s['conversions'];
            $folded[$g]['conversion_value'] += (float) $s['conversion_value'];
        }

        $fx    = $this->monthFx($brand, $start, $usd);
        $total = 0.0;
        $rows  = [];
        foreach ($folded as $s) {
            $row    = $this->breakdownMetrics($s, $fx, 'gender');
            $total += $row['cost'];
            $rows[] = $row;
        }
        foreach ($rows as &$r) {
            $r['share'] = $total > 0 ? round($r['cost'] / $total, 4) : null;
        }
        unset($r);

        usort($rows, static fn (array $a, array $b): int => $b['cost'] <=> $a['cost']);

        return ['status' => 'ready', 'metrics' => $rows];
    }

    /**
     * ROAS by country, month over month = commerce country revenue ÷ Meta country
     * spend, per calendar month. Reveals the scaling opportunity: low-spend,
     * high-ROAS countries. Ranked by Meta spend (where the money actually is), and
     * the section's blended ROAS drives the green/red heat. "no_data" until the
     * Meta `country` breakdown is on file.
     *
     * @param array<int, string> $months
     * @return array<string, mixed>
     */
    private function roasByCountrySection(int $brandId, array $months, bool $usd): array
    {
        $start = CarbonImmutable::parse($months[0] . '-01')->toDateString();
        $end   = CarbonImmutable::parse(end($months) . '-01')->endOfMonth()->toDateString();

        try {
            // Commerce revenue keyed by Shopify country NAME → normalise to ISO-2
            // so it joins Meta spend (which is keyed by ISO-2 country code).
            $rev   = $this->series->rawByMonth($brandId, 'country', $start, $end, $usd, CountryCodes::toIso2(...));
            $spend = $this->metaSpendByMonth($brandId, 'country', $start, $end, $usd);
        } catch (Throwable $e) {
            Log::warning('monthly_report.section_failed', ['dimension' => 'roasByCountry', 'error' => $e->getMessage()]);

            return ['status' => 'no_data'];
        }

        if ($spend === []) {
            return ['status' => 'no_data'];
        }

        $totRevAll = 0.0;
        $totSpendAll = 0.0;
        $rows = [];
        foreach ($spend as $key => $s) {
            // Meta returns spend with no country for Advantage+/automatic geo — it
            // can't have a per-country efficiency, so it shows its spend with a "—"
            // (not a misleading 0.0×) and is kept OUT of the blended benchmark.
            $isUnattributed = mb_strtolower(trim((string) $key)) === 'unknown';
            $revByM  = $rev[$key]['byMonth'] ?? [];
            $byMonth = [];
            $tRev = 0.0;
            $tSpend = 0.0;
            foreach ($months as $m) {
                $sp = (float) ($s['byMonth'][$m] ?? 0);
                $rv = (float) ($revByM[$m] ?? 0);
                $byMonth[$m] = ($isUnattributed || $sp <= 0) ? null : round($rv / $sp, 2);
                $tSpend += $sp;
                $tRev   += $rv;
            }
            if (! $isUnattributed) {
                $totSpendAll += $tSpend;
                $totRevAll   += $tRev;
            }
            $rows[] = [
                'key'     => (string) $key,
                // Prefer the commerce country name ("Spain") over Meta's bare code.
                'label'   => $isUnattributed ? 'Automatic (Advantage+)' : (string) ($rev[$key]['label'] ?? $s['label']),
                'byMonth' => $byMonth,
                'spend'   => round($tSpend, 2),
                'roas'    => ($isUnattributed || $tSpend <= 0) ? null : round($tRev / $tSpend, 2),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['spend'] <=> $a['spend']);
        $rows = array_slice($rows, 0, 8);

        return [
            'status' => 'ready',
            'roas'   => [
                'months'  => $months,
                'rows'    => $rows,
                'blended' => $totSpendAll > 0 ? round($totRevAll / $totSpendAll, 2) : null,
            ],
        ];
    }

    /**
     * Meta spend per segment per calendar month from meta_breakdown_daily, in
     * display currency. Shape mirrors MonthlySeries::rawByMonth so the two pair up.
     *
     * @return array<string, array{label: string, byMonth: array<string, float>}>
     */
    private function metaSpendByMonth(int $brandId, string $dimension, string $start, string $end, bool $usd): array
    {
        $spend = $usd ? 'spend * COALESCE(fx_rate_to_usd, 1)' : 'spend';

        $rows = MetaBreakdownDaily::query()
            ->where('brand_id', $brandId)
            ->where('platform', 'meta')
            ->where('breakdown_type', $dimension)
            ->whereBetween('date', [$start, $end])
            ->groupByRaw("segment_key, DATE_FORMAT(date, '%Y-%m')")
            ->selectRaw("segment_key,
                MAX(segment_label) AS label,
                DATE_FORMAT(date, '%Y-%m') AS ym,
                COALESCE(SUM({$spend}), 0) AS spend")
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $key = (string) $r->segment_key;
            if ($key === '') {
                continue;
            }
            $out[$key] ??= ['label' => (string) ($r->label ?: $key), 'byMonth' => []];
            $out[$key]['byMonth'][(string) $r->ym] = (float) $r->spend;
        }

        return $out;
    }

    /**
     * Ad spend by landing page × best sellers — "is the ad budget behind the
     * winners?". The Inventory report's join: product_catalog bridges Meta spend
     * (ad_product_daily, by product HANDLE) and commerce revenue/units (by product
     * TITLE), plus current stock. Native currency (matches the Inventory report;
     * ad_product_daily carries no fx). Ranked by revenue, top 8.
     *
     * @return array<string, mixed>
     */
    private function landingSellersSection(int $brandId, string $start, string $end): array
    {
        try {
            $products = ProductCatalog::query()->where('brand_id', $brandId)->get();
            if ($products->isEmpty()) {
                return ['status' => 'no_data'];
            }

            $adByHandle = AdProductDaily::query()
                ->where('brand_id', $brandId)
                ->whereBetween('date', [$start, $end])
                ->selectRaw('product_key, COALESCE(SUM(spend), 0) AS spend')
                ->groupBy('product_key')
                ->get()
                ->keyBy('product_key');

            $commByTitle = CommerceDailyMetric::query()
                ->where('brand_id', $brandId)
                ->where('dimension_type', 'product')
                ->whereBetween('date', [$start, $end])
                ->selectRaw('dimension_key,
                    COALESCE(SUM(units), 0)          AS units,
                    COALESCE(SUM(total_sales), 0)    AS total_sales,
                    COALESCE(SUM(refunds_amount), 0) AS refunds')
                ->groupBy('dimension_key')
                ->get()
                ->keyBy('dimension_key');
        } catch (Throwable $e) {
            Log::warning('monthly_report.section_failed', ['dimension' => 'landingSellers', 'error' => $e->getMessage()]);

            return ['status' => 'no_data'];
        }

        $rows = [];
        foreach ($products as $p) {
            $ad      = $adByHandle->get($p->handle);
            $c       = $commByTitle->get($p->title);
            $spend   = round((float) ($ad->spend ?? 0), 2);
            $revenue = round((float) ($c->total_sales ?? 0) + (float) ($c->refunds ?? 0), 2);
            if ($spend <= 0.0 && $revenue <= 0.0) {
                continue; // neither advertised nor sold this month — skip
            }
            $stock = (int) $p->total_inventory;
            $roas  = $spend > 0 ? round($revenue / $spend, 2) : null;
            $rows[] = [
                'label'   => (string) $p->title,
                'spend'   => $spend,
                'revenue' => $revenue,
                'roas'    => $roas,
                'units'   => (int) ($c->units ?? 0),
                'stock'   => $stock,
                'read'    => $this->landingRead($spend, $revenue, $roas, $stock),
            ];
        }

        if ($rows === []) {
            return ['status' => 'no_data'];
        }

        usort($rows, static fn (array $a, array $b): int => $b['revenue'] <=> $a['revenue']);

        return ['status' => 'ready', 'products' => array_slice($rows, 0, 8)];
    }

    /** Plain-English read pairing spend efficiency with stock urgency. */
    private function landingRead(float $spend, float $revenue, ?float $roas, int $stock): string
    {
        if ($spend <= 0.0 && $revenue > 0.0) {
            return 'Selling organically — no ad spend behind it';
        }
        if ($roas !== null && $roas >= 4.0 && $stock > 0 && $stock <= 20) {
            return 'Winner, low stock — reorder';
        }
        if ($stock > 0 && $stock <= 20) {
            return 'Low stock — reorder';
        }
        if ($roas !== null && $roas < 2.0) {
            return 'Spend-heavy vs return';
        }

        return '';
    }

    /**
     * Ad spend by placement for the report month — meta_breakdown_daily[placement]
     * (publisher × position, e.g. "IG · Feed"). Cost / reach / frequency / clicks
     * / CPC / CTR / CPM / share. Reach + frequency are NULL until a re-sync
     * captures reach (added to the breakdown pull in this increment); summed reach
     * is an upper bound so frequency (impressions ÷ reach) is approximate.
     *
     * @return array<string, mixed>
     */
    private function placementSection(Brand $brand, string $start, string $end, bool $usd): array
    {
        $conn = $this->metaConnection($brand->id);
        if ($conn === null) {
            return ['status' => 'no_data'];
        }

        try {
            $segments = $this->insights->fetchBreakdownTotals(
                $conn,
                (array) config('meta_breakdowns.placement', ['publisher_platform', 'platform_position']),
                CarbonImmutable::parse($start),
                CarbonImmutable::parse($end),
            );
        } catch (Throwable $e) {
            Log::warning('monthly_report.section_failed', ['dimension' => 'placement', 'error' => $e->getMessage()]);

            return ['status' => 'no_data'];
        }

        if ($segments === []) {
            return ['status' => 'no_data'];
        }

        $fx    = $this->monthFx($brand, $start, $usd);
        $total = 0.0;
        $rows  = [];
        foreach ($segments as $s) {
            $row    = $this->breakdownMetrics($s, $fx, 'placement');
            $total += $row['cost'];
            $rows[] = $row;
        }
        foreach ($rows as &$r) {
            $r['share'] = $total > 0 ? round($r['cost'] / $total, 4) : null;
        }
        unset($r);

        usort($rows, static fn (array $a, array $b): int => $b['cost'] <=> $a['cost']);

        return ['status' => 'ready', 'placement' => array_slice($rows, 0, 10)];
    }

    /** The brand's active Meta connection (brand eager-loaded for currency), or null. */
    private function metaConnection(int $brandId): ?PlatformConnection
    {
        return PlatformConnection::query()
            ->with('brand')
            ->where('brand_id', $brandId)
            ->where('platform', 'meta')
            ->where('status', 'active')
            ->first();
    }

    /** Native → display-currency fx multiplier for the report month (1.0 in native mode). */
    private function monthFx(Brand $brand, string $start, bool $usd): float
    {
        if (! $usd) {
            return 1.0;
        }
        $rate = $this->fx->cachedToUsd((string) ($brand->base_currency ?: 'USD'), CarbonImmutable::parse($start));

        return $rate ?: 1.0;
    }

    /**
     * Shared placement/gender metric row from a fetchBreakdownTotals segment.
     * $fx multiplies native money into display currency; cost metrics (CPC/CPM/CPA)
     * and ROAS derive from the fx-adjusted cost/revenue so they're correct in either
     * mode, while ratios (CTR/frequency) are currency-agnostic. The Advantage+
     * unattributed placement bucket ("unknown" position) is relabelled honestly.
     * `share` is filled by the caller once the section total is known.
     *
     * @param  array<string, mixed>  $s
     * @return array<string, mixed>
     */
    private function breakdownMetrics(array $s, float $fx, string $kind): array
    {
        $cost  = round(((float) $s['spend']) * $fx, 2);
        $impr  = (int) $s['impressions'];
        $clk   = (int) $s['clicks'];
        $reach = (int) $s['reach'];
        $purch = (int) $s['conversions'];
        $rev   = round(((float) $s['conversion_value']) * $fx, 2);

        $raw   = (string) ($s['label'] ?: $s['key']);
        $label = $kind === 'placement' ? $this->placementLabel($raw) : $raw;
        if ($kind === 'placement' && mb_strtolower(trim($raw)) === 'unknown') {
            $label = 'Automatic (Advantage+)';
        }

        return [
            'label'     => $label,
            'cost'      => $cost,
            'reach'     => $reach > 0 ? $reach : null,
            'freq'      => $reach > 0 ? round($impr / $reach, 2) : null,
            'clicks'    => $clk,
            'cpc'       => $clk > 0 ? round($cost / $clk, 2) : null,
            'ctr'       => $impr > 0 ? round($clk / $impr * 100, 2) : null,
            'cpm'       => $impr > 0 ? round($cost / $impr * 1000, 2) : null,
            'purchases' => $purch,
            'roas'      => $cost > 0 ? round($rev / $cost, 2) : null,
            'cpa'       => $purch > 0 ? round($cost / $purch, 2) : null,
            'share'     => null,
        ];
    }

    /**
     * Web funnel (sessions → cart → checkout → purchase) for the report month, by
     * country or landing path — summed from shopify_funnel_daily (additive across
     * days). `needs_source` until shopify:backfill-funnel has run for the brand.
     *
     * @return array<string, mixed>
     */
    private function funnelSection(int $brandId, string $dimension, string $start, string $end): array
    {
        try {
            $rows = ShopifyFunnelDaily::query()
                ->where('brand_id', $brandId)
                ->where('dimension', $dimension)
                ->whereBetween('date', [$start, $end])
                ->groupBy('segment_key', 'segment_label')
                ->selectRaw('segment_key, MAX(segment_label) AS label,
                    COALESCE(SUM(sessions), 0) AS sessions,
                    COALESCE(SUM(cart_additions), 0) AS cart_additions,
                    COALESCE(SUM(reached_checkout), 0) AS reached_checkout,
                    COALESCE(SUM(completed_checkout), 0) AS completed_checkout')
                ->get();
        } catch (Throwable $e) {
            Log::warning('monthly_report.section_failed', ['dimension' => 'funnel_' . $dimension, 'error' => $e->getMessage()]);

            return ['status' => 'no_data'];
        }

        if ($rows->isEmpty()) {
            return ['status' => 'needs_source', 'note' => 'Run shopify:backfill-funnel for this brand to populate the web funnel.'];
        }

        // The landing-path funnel is a client "which entry pages convert" table, so
        // raw Shopify paths are prettified and entry pages with zero direct purchases
        // are dropped (a wall of 0/0/0 rows reads as broken). Country keeps every row.
        $isLanding = $dimension === 'landing';

        $out = [];
        foreach ($rows as $r) {
            $sessions = (int) $r->sessions;
            $purchase = (int) $r->completed_checkout;
            if ($isLanding && $purchase <= 0) {
                continue;
            }
            $rawLabel = (string) ($r->label ?: $r->segment_key);
            $out[] = [
                'label'    => $isLanding ? $this->landingLabel($rawLabel) : $rawLabel,
                'sessions' => $sessions,
                'cart'     => (int) $r->cart_additions,
                'checkout' => (int) $r->reached_checkout,
                'purchase' => $purchase,
                'cvr'      => $sessions > 0 ? round($purchase / $sessions * 100, 2) : null,
            ];
        }
        usort($out, static fn (array $a, array $b): int => $b['sessions'] <=> $a['sessions']);

        if ($out === []) {
            return ['status' => 'no_data'];
        }

        return ['status' => 'ready', 'funnel' => array_slice($out, 0, 10)];
    }

    /**
     * Prettify a raw Shopify landing path for the client funnel: "/" → "Home",
     * "/collections/t-shirts" → "T shirts (collection)", "/products/foo-bar" →
     * "Foo bar (product)". Query strings and fragments are dropped.
     */
    private function landingLabel(string $path): string
    {
        $p = trim($path);
        if ($p === '' || $p === '/') {
            return 'Home';
        }
        $p = (string) strtok($p, '?#');
        $segs = array_values(array_filter(explode('/', trim($p, '/')), static fn (string $s): bool => $s !== ''));
        if ($segs === []) {
            return 'Home';
        }

        $suffix = '';
        if (count($segs) >= 2 && in_array($segs[0], ['collections', 'products', 'pages', 'blogs'], true)) {
            $suffix = ' (' . rtrim($segs[0], 's') . ')';
        }
        $last  = (string) end($segs);
        $clean = ucfirst(trim(str_replace(['-', '_'], ' ', $last)));

        return ($clean === '' ? $p : $clean) . $suffix;
    }

    /**
     * §4 — new vs existing customers, month over month. Counts come from a LIVE
     * monthly ShopifyQL `sales` query (customersByMonthRange): unique customers
     * don't decompose to days, so — unlike the funnel — they can't ride the daily
     * sync. `new = customers − returning`. Revenue/spend/ROAS reuse monthMetrics
     * (fx-aware, consistent with the rest of the report); AOV = that revenue ÷
     * ShopifyQL orders; CAC = ad spend ÷ new customers.
     *
     * There is NO customer_type dimension on `sales` (verified via
     * shopify:diagnose-customer-type), so this is counts + blended money only:
     * revenue is NOT split by new/returning and there is no new-customer ROAS.
     *
     * @param  list<string>  $months  trailing Y-m, chronological
     * @return array<string, mixed>
     */
    private function newVsExistingSection(Brand $brand, array $months, bool $usd): array
    {
        $conn = PlatformConnection::query()
            ->where('brand_id', $brand->id)
            ->where('platform', 'shopify')
            ->where('status', 'active')
            ->first();

        if (! $conn) {
            return ['status' => 'needs_source', 'note' => 'No active Shopify connection for this brand.'];
        }

        $start = CarbonImmutable::parse($months[0] . '-01')->toDateString();
        $end   = CarbonImmutable::parse(end($months) . '-01')->endOfMonth()->toDateString();

        try {
            $byMonth = $this->revenue->customersByMonthRange($conn, $start, $end);
        } catch (Throwable $e) {
            Log::warning('monthly_report.section_failed', ['dimension' => 'newVsExisting', 'error' => $e->getMessage()]);

            return ['status' => 'no_data'];
        }

        if ($byMonth === []) {
            return ['status' => 'needs_source', 'note' => 'Shopify customer counts unavailable — the store needs ShopifyQL (read_reports) access.'];
        }

        $rows = [];
        foreach ($months as $ym) {
            $c = $byMonth[$ym] ?? null;
            if ($c === null) {
                continue;
            }
            $customers = (int) ($c['customers'] ?? 0);
            $returning = (int) ($c['returning'] ?? 0);
            $new       = max(0, $customers - $returning);
            $orders    = (int) ($c['orders'] ?? 0);

            $ms      = CarbonImmutable::parse($ym . '-01');
            $metrics = $this->monthMetrics($brand->id, $ms->toDateString(), $ms->endOfMonth()->toDateString(), $usd);
            $revenue = $metrics['revenue'];
            $spend   = $metrics['totalSpend'];
            $aov     = $orders > 0 ? round($revenue / $orders, 2) : null;

            // Estimated new-customer ROAS. Shopify can't split revenue by customer
            // type, so we model new-customer revenue as new customers × AOV and
            // divide by total ad spend (standard naMER convention). It uses BLENDED
            // AOV, so it skews slightly optimistic (new customers' first order is
            // usually below the blended average) — surfaced as an estimate, never a
            // hard figure.
            $newRoas = ($aov !== null && $spend > 0.0) ? round(($new * $aov) / $spend, 2) : null;

            $rows[] = [
                'month'     => $ms->isoFormat('MMM YY'),
                'new'       => $new,
                'returning' => $returning,
                'total'     => $customers,
                'retPct'    => $customers > 0 ? round($returning / $customers * 100, 1) : null,
                'revenue'   => $revenue,
                'orders'    => $orders,
                'aov'       => $aov,
                'spend'     => $spend,
                'roas'      => $metrics['roas'],
                'roasNew'   => $newRoas,
                'cac'       => $new > 0 ? round($spend / $new, 2) : null,
            ];
        }

        if ($rows === []) {
            return ['status' => 'no_data'];
        }

        return ['status' => 'ready', 'customers' => $rows];
    }

    /**
     * Pull the estimated new-customer ROAS for one month label ("Jun 26") out of
     * the §4 payload, so the Overall picture KPI and the §4 row agree exactly.
     *
     * @param array<string, mixed> $section
     */
    private function newRoasForMonth(array $section, string $monthLabel): ?float
    {
        if (($section['status'] ?? '') !== 'ready') {
            return null;
        }
        foreach ($section['customers'] ?? [] as $row) {
            if (($row['month'] ?? '') === $monthLabel) {
                $v = $row['roasNew'] ?? null;

                return $v === null ? null : (float) $v;
            }
        }

        return null;
    }

    /** Prettify a Meta placement segment ("instagram · feed" → "IG · Feed"). */
    private function placementLabel(string $seg): string
    {
        $parts = array_map(static function (string $p): string {
            $p = strtolower(trim($p));

            return match ($p) {
                'instagram'        => 'IG',
                'facebook'         => 'FB',
                'audience_network' => 'Audience Network',
                'messenger'        => 'Messenger',
                default            => ucwords(str_replace('_', ' ', $p)),
            };
        }, explode('·', $seg));

        return implode(' · ', $parts);
    }

    /** Parse the gender axis out of a Meta "AGE · GENDER" segment key. */
    private function genderOf(string $seg): string
    {
        $parts = array_map('trim', explode('·', $seg));
        $g     = strtolower((string) end($parts));
        if (str_contains($g, 'female')) {
            return 'female';
        }
        if (str_contains($g, 'male')) {
            return 'male';
        }

        return 'unknown';
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
