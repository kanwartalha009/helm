<?php

declare(strict_types=1);

namespace App\Reports\Mom;

use App\Models\Brand;
use App\Models\DailyMetric;
use App\Reports\Contracts\ReportFilters;
use App\Reports\Contracts\ReportType;
use App\Services\ReportLayouts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * M2 (monthly-report-v2-mom.md): the "mom" report type — the SHELL only. This
 * class's build() is deliberately NOT a monolith: it returns month/currency/
 * availableMonths/freshness/the resolved layout manifest, and NOTHING from any
 * individual section. Section data is fetched separately, one request per
 * section, via MomSectionController — the exact architecture M0 exists to
 * teach ("the current monthly builds one 16.3s+ payload... freezes the tab...
 * never build a second monolith").
 *
 * v1 (`MonthlyReport`) is untouched by this class — no shared code, no shared
 * base class, per REV2 R7. Any duplication with v1 (availableMonths, freshness)
 * is deliberate: this report's whole point is to NOT depend on v1's shape.
 */
final class MomReport implements ReportType
{
    public function __construct(
        private readonly ReportLayouts $layouts,
        private readonly MomSectionRegistry $sections,
    ) {
    }

    public function key(): string
    {
        return 'mom';
    }

    public function label(): string
    {
        return 'MoM Strategy Report';
    }

    public function build(Brand $brand, ReportFilters $filters): array
    {
        $tz  = $brand->timezone ?: 'UTC';
        $now = CarbonImmutable::now($tz);

        // The active window honours a custom day range (Kanwar, 2026-07-17) when
        // one is set, else the selected month; either way the header names the
        // exact window shown. Falls back to the last complete month only when
        // nothing is selected at all.
        $isRange = $filters->isCustomRange();
        $window  = $filters->activeWindow($tz);
        if ($window !== null) {
            [$monthStart, $monthEnd] = [CarbonImmutable::parse($window[0], $tz), CarbonImmutable::parse($window[1], $tz)];
        } else {
            $monthEnd   = $now->startOfMonth()->subDay()->endOfDay();
            $monthStart = $monthEnd->startOfMonth();
        }

        $compareWindow = $filters->activeComparisonWindow($tz);

        $layout = $this->layouts->resolve($brand, $this->key());
        $sectionsManifest = array_map(
            fn (array $s): array => $s + ['ready' => $this->sections->has($s['key'])],
            $layout,
        );

        return [
            'reportType' => $this->key(),
            'brand' => [
                'name'         => $brand->name,
                'slug'         => $brand->slug,
                'baseCurrency' => $brand->base_currency,
                'timezone'     => $brand->timezone,
            ],
            'currency' => $filters->usd ? 'USD' : ($brand->base_currency ?: 'USD'),
            // `range` flags custom-range mode so the SPA can label the header as
            // a date range and show the "month-by-month tables need a month"
            // hint on the matrix sections rather than pretending they're empty.
            'range' => $isRange,
            'month' => [
                'label' => $isRange
                    ? $monthStart->isoFormat('D MMM YYYY') . ' – ' . $monthEnd->isoFormat('D MMM YYYY')
                    : $monthStart->isoFormat('MMMM YYYY'),
                'start' => $monthStart->toDateString(),
                'end'   => $monthEnd->toDateString(),
            ],
            'compareMonth' => $compareWindow !== null ? [
                'label' => $isRange
                    ? CarbonImmutable::parse($compareWindow[0])->isoFormat('D MMM YYYY') . ' – ' . CarbonImmutable::parse($compareWindow[1])->isoFormat('D MMM YYYY')
                    : CarbonImmutable::parse($compareWindow[0])->isoFormat('MMMM YYYY'),
                'start' => $compareWindow[0],
                'end'   => $compareWindow[1],
            ] : null,
            'availableMonths' => $this->availableMonths($brand->id, $monthStart),
            // The resolved, ORDERED section manifest (brand override -> agency
            // default -> code default) — each entry says whether M2/M3 has
            // actually built that section yet ('ready'), so the SPA can render
            // a "coming soon" tile for the rest instead of an error.
            'sections' => $sectionsManifest,
            'freshness' => $this->freshness($brand->id, $monthEnd->toDateString()),
        ];
    }

    /**
     * Every complete calendar month the brand has Shopify rows for, most recent
     * first, clamped to 12 — identical CONTRACT to v1's availableMonths (same
     * shape the SPA's month picker already knows how to render), independently
     * implemented per this class's own docblock.
     *
     * @return array<int, array{key: string, label: string}>
     */
    private function availableMonths(int $brandId, CarbonImmutable $lastCompleteMonthStart): array
    {
        try {
            $min = DailyMetric::query()
                ->where('brand_id', $brandId)
                ->where('platform', 'shopify')
                ->min('date');
        } catch (Throwable $e) {
            Log::warning('mom_report.section_failed', ['dimension' => 'availableMonths', 'error' => $e->getMessage()]);

            return [];
        }

        if ($min === null) {
            return [];
        }

        $minKey = CarbonImmutable::parse((string) $min)->format('Y-m');
        $out = [];
        for ($m = $lastCompleteMonthStart; count($out) < 12 && $m->format('Y-m') >= $minKey; $m = $m->subMonth()) {
            $out[] = ['key' => $m->format('Y-m'), 'label' => $m->isoFormat('MMMM YYYY')];
        }

        return $out;
    }

    /**
     * Same fail-closed contract as v1's freshness gate: the month is only
     * trustworthy when the latest COMPLETE Shopify day on file reaches the
     * month's end. A check failure reads as stale, never the other way round.
     *
     * @return array<string, mixed>
     */
    private function freshness(int $brandId, string $monthEnd): array
    {
        try {
            $lastComplete = DailyMetric::query()
                ->where('brand_id', $brandId)
                ->where('platform', 'shopify')
                ->where('is_complete', true)
                ->max('date');

            $end  = CarbonImmutable::parse($monthEnd)->startOfDay();
            $last = $lastComplete !== null ? CarbonImmutable::parse((string) $lastComplete)->startOfDay() : null;

            return [
                'upToDate'   => $last !== null && $last->greaterThanOrEqualTo($end),
                'lastSynced' => $last?->toDateString(),
                'staleDays'  => ($last !== null && $last->lessThan($end)) ? (int) $last->diffInDays($end) : 0,
                'windowEnd'  => $end->toDateString(),
            ];
        } catch (Throwable $e) {
            Log::warning('mom_report.section_failed', ['dimension' => 'freshness', 'error' => $e->getMessage()]);

            return [
                'upToDate'   => false,
                'lastSynced' => null,
                'staleDays'  => 0,
                'windowEnd'  => $monthEnd,
                'note'       => 'Freshness could not be verified — the report is held back until a sync confirms the data is current.',
            ];
        }
    }
}
