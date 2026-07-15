<?php

declare(strict_types=1);

namespace App\Reports\Mom\Sections;

use App\Models\Brand;
use App\Reports\Contracts\ReportFilters;
use App\Reports\Mom\Contracts\MomSection;

/**
 * M2 (monthly-report-v2-mom.md §M2) — "S3 New vs Returning evolution. Daily
 * new vs returning revenue charts — customer_type probe dependent; hide with
 * note if unavailable."
 *
 * The `shopify:diagnose-customer-type` PROBE this section depends on (same
 * probe S1's New/Returning columns need — see
 * docs/feature-specs/brand-inventory-and-customer-mix-reports.md) requires
 * live production Shopify ShopifyQL access this sandbox does not have, and
 * has not been run in this session. Per the spec's OWN fallback rule ("hide
 * with note if unavailable... never fake them"), this section is a clean,
 * always-honest shell until the probe runs and its evidence is pasted into
 * the tracker — never a guessed new/returning split.
 */
final class SNewVsReturningSection implements MomSection
{
    public function key(): string
    {
        return 'S3';
    }

    public function build(Brand $brand, ReportFilters $filters): array
    {
        return [
            'key'    => $this->key(),
            'status' => 'needs_source',
            'note'   => 'Requires the ShopifyQL customer_type probe (not yet run — see the M2 tracker entry) before a real new-vs-returning split can render.',
        ];
    }
}
