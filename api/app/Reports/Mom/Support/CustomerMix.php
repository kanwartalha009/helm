<?php

declare(strict_types=1);

namespace App\Reports\Mom\Support;

use App\Models\Brand;
use App\Models\PlatformConnection;
use App\Platforms\Shopify\RevenueFetcher;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * M5 addendum (Kanwar, 2026-07-15 — "complete the report end to end... once we
 * sync all data for 1 brand"): the ONE shared new-vs-returning source both S3
 * (evolution chart) and S-EX (the New-vs-Returning % / CAC tiles) read — never
 * two copies of this logic.
 *
 * Wraps the EXISTING `RevenueFetcher::customersByMonthRange` (the same
 * bounded, live ShopifyQL call v1's MonthlyReport already uses — reused, not
 * reimplemented) for a SINGLE month window. Shopify has NO customer_type
 * dimension on `sales` (verified via shopify:diagnose-customer-type), so
 * revenue can't be split by customer type — only these aggregate COUNTS are
 * available (`customers`, `returning_customers`; `new = customers − returning`).
 *
 * Honesty contract (spec rule 9, missing != zero):
 *   - no active Shopify connection      → null (caller renders needs_source)
 *   - the token lacks read_reports scope → customersByMonthRange returns [] → null
 *   - any transport failure              → caught → null
 * A null is ALWAYS an honest "we don't have this yet", never a fabricated 0.
 * The live call is bounded to REPORT_CONTEXT_TIMEOUT_SECS inside the fetcher.
 */
final class CustomerMix
{
    public function __construct(private readonly RevenueFetcher $revenue)
    {
    }

    /**
     * New-vs-returning customer counts for ONE month window.
     *
     * @return array{customers: int, returning: int, new: int, orders: int, newPct: ?float, retPct: ?float}|null
     */
    public function forMonth(Brand $brand, string $start, string $end): ?array
    {
        $conn = PlatformConnection::query()
            ->where('brand_id', $brand->id)
            ->where('platform', 'shopify')
            ->where('status', 'active')
            ->first();

        if ($conn === null) {
            return null; // not connected → honestly absent, and NO external call is made
        }

        try {
            // customersByMonthRange groups by month; for a single-month window it
            // returns one entry keyed 'Y-m'. Any missing token / scope / transport
            // problem degrades to [] (or throws pre-request), handled below.
            $byMonth = $this->revenue->customersByMonthRange($conn, $start, $end);
        } catch (Throwable) {
            return null;
        }

        if ($byMonth === []) {
            return null;
        }

        $ym  = CarbonImmutable::parse($start)->format('Y-m');
        $row = $byMonth[$ym] ?? (count($byMonth) === 1 ? reset($byMonth) : null);
        if (! is_array($row)) {
            return null;
        }

        $customers = (int) ($row['customers'] ?? 0);
        $returning = (int) ($row['returning'] ?? 0);
        if ($customers <= 0) {
            return null; // a zero-customer month is no signal, not a real "0% new"
        }

        $new = max(0, $customers - $returning);

        return [
            'customers' => $customers,
            'returning' => $returning,
            'new'       => $new,
            'orders'    => (int) ($row['orders'] ?? 0),
            'newPct'    => round($new / $customers * 100, 1),
            'retPct'    => round($returning / $customers * 100, 1),
        ];
    }

}
