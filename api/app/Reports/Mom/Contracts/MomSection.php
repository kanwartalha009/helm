<?php

declare(strict_types=1);

namespace App\Reports\Mom\Contracts;

use App\Models\Brand;
use App\Reports\Contracts\ReportFilters;

/**
 * M2 (monthly-report-v2-mom.md §M2): one mom-report section, independently
 * fetchable via its OWN endpoint (`GET brands/{brand}/reports/mom/sections/{key}`)
 * — the section-streamed architecture M0/M5 require ("never a monolith payload").
 * Every section is fault-isolated by the controller that calls build(): a thrown
 * exception here degrades to a 'no_data' status response, it never 500s the
 * whole report and never takes another section down with it.
 */
interface MomSection
{
    /** Stable key matching config/momreport.php's catalog — 'S-EX', 'S1', … */
    public function key(): string;

    /** @return array<string, mixed> render-ready payload for this ONE section */
    public function build(Brand $brand, ReportFilters $filters): array;
}
