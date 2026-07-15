<?php

declare(strict_types=1);

namespace App\Reports\Mom\Sections;

use App\Models\Brand;
use App\Reports\Contracts\ReportFilters;
use App\Reports\Mom\Contracts\MomSection;
use App\Reports\Mom\Support\CustomerMix;
use Carbon\CarbonImmutable;

/**
 * M2 (monthly-report-v2-mom.md §M2) — "S3 New vs Returning evolution. Daily
 * new vs returning revenue charts — customer_type probe dependent; hide with
 * note if unavailable."
 *
 * M5 end-to-end completion (Kanwar, 2026-07-15): wired to real data via the
 * shared `CustomerMix` (the SAME bounded live ShopifyQL new/returning count
 * S-EX's tiles use — one source, never two). The customer_type PROBE
 * (shopify:diagnose-customer-type) confirmed Shopify has NO dimension that
 * splits REVENUE by customer type — only aggregate COUNTS exist — so this
 * section honestly shows new vs returning CUSTOMER COUNTS (+ new/returning %),
 * NOT a revenue split, exactly the fallback the spec sanctions ("hide with
 * note if unavailable... never fake them"). It stays `needs_source` until a
 * Shopify connection with ShopifyQL (read_reports) access exists for the brand.
 */
final class SNewVsReturningSection implements MomSection
{
    public function __construct(private readonly CustomerMix $customerMix)
    {
    }

    public function key(): string
    {
        return 'S3';
    }

    public function build(Brand $brand, ReportFilters $filters): array
    {
        $tz = $brand->timezone ?: 'UTC';
        $window = $filters->monthWindow($tz);
        if ($window === null) {
            return ['key' => $this->key(), 'status' => 'no_data', 'note' => 'No complete month selected.'];
        }
        [$start, $end] = $window;

        $cur = $this->customerMix->forMonth($brand, $start, $end);
        if ($cur === null) {
            return [
                'key'    => $this->key(),
                'status' => 'needs_source',
                'note'   => 'Needs the Shopify customer split — connect Shopify with ShopifyQL (read_reports) access, then it fills automatically.',
            ];
        }

        $compareWindow = $filters->compareMonthWindow($tz);
        $cmp = $compareWindow !== null ? $this->customerMix->forMonth($brand, $compareWindow[0], $compareWindow[1]) : null;

        return [
            'key'    => $this->key(),
            'status' => 'ok',
            'month'  => CarbonImmutable::parse($start)->format('Y-m'),
            'compareMonth' => $compareWindow !== null ? CarbonImmutable::parse($compareWindow[0])->format('Y-m') : null,
            'new'          => $cur['new'],
            'returning'    => $cur['returning'],
            'total'        => $cur['customers'],
            'orders'       => $cur['orders'],
            'newPct'       => ['value' => $cur['newPct'], 'compare' => $cmp['newPct'] ?? null, 'deltaPct' => $this->delta($cur['newPct'], $cmp['newPct'] ?? null)],
            'retPct'       => ['value' => $cur['retPct'], 'compare' => $cmp['retPct'] ?? null, 'deltaPct' => $this->delta($cur['retPct'], $cmp['retPct'] ?? null)],
            // Shopify can't split revenue by customer type — surfaced so the UI
            // never implies a new/returning REVENUE breakdown that doesn't exist.
            'note'         => 'Shopify reports new vs returning customer COUNTS, not a revenue split — these are unique-customer counts for the month.',
        ];
    }

    private function delta(?float $value, ?float $compare): ?float
    {
        if ($value === null || $compare === null || $compare === 0.0) {
            return null;
        }

        return round(($value - $compare) / $compare * 100, 1);
    }
}
