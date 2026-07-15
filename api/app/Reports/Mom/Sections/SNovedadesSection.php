<?php

declare(strict_types=1);

namespace App\Reports\Mom\Sections;

use App\Models\Brand;
use App\Reports\Contracts\ReportFilters;
use App\Reports\Mom\Contracts\MomSection;
use App\Services\Novedades;
use Carbon\CarbonImmutable;

/**
 * M4 (monthly-report-v2-mom.md §M4) — "S19 Novedades (slide 31). Agency-wide
 * monthly talking points... written once in Settings, appears in every
 * brand's report that month, per-brand editable copy."
 *
 * Pure read via Novedades::resolve() — brand's own edited copy wins, else the
 * agency-wide default written in Settings, else absent ('no_data', per the
 * "missing != zero" doctrine — an unwritten Novedades note is never rendered
 * as an empty-but-present block).
 */
final class SNovedadesSection implements MomSection
{
    public function __construct(private readonly Novedades $novedades)
    {
    }

    public function key(): string
    {
        return 'S19';
    }

    public function build(Brand $brand, ReportFilters $filters): array
    {
        $tz = $brand->timezone ?: 'UTC';
        $month = $filters->month ?? CarbonImmutable::now($tz)->subMonth()->format('Y-m');

        $resolved = $this->novedades->resolve($brand, $month);

        if ($resolved['body'] === null) {
            return [
                'key'    => $this->key(),
                'status' => 'no_data',
                'month'  => $month,
                'note'   => 'No novedades written for this month yet — add the agency-wide note in Settings, or a brand-specific copy here.',
            ];
        }

        return [
            'key'             => $this->key(),
            'status'          => 'ok',
            'month'           => $month,
            'body'            => $resolved['body'],
            'source'          => $resolved['source'], // 'brand' | 'workspace'
            'isBrandOverride' => $resolved['source'] === 'brand',
        ];
    }
}
