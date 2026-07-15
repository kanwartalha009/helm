<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Brand;
use App\Models\ReportNote;

/**
 * M4 (monthly-report-v2-mom.md §M4) — S19 "Novedades" read/write path. Mirrors
 * ReportLayouts' resolve()-then-save() shape: resolve() is a pure function of
 * (brand, month, current rows), which is what makes it safe for
 * SNovedadesSection::build() to call on every report load with no side effect.
 *
 * Resolution: brand's own edited copy -> agency-wide default -> absent (there
 * is deliberately no code default here, unlike report_layouts' config/momreport.php
 * catalog — Novedades is pure editorial text, never fabricated when nobody's
 * written one yet).
 */
class Novedades
{
    /** @return array{body: ?string, source: 'brand'|'workspace'|null} */
    public function resolve(Brand $brand, string $month): array
    {
        $row = ReportNote::query()->where('brand_id', $brand->id)->where('month', $month)->first();
        if ($row !== null) {
            return ['body' => $row->body, 'source' => 'brand'];
        }

        $default = ReportNote::query()->whereNull('brand_id')->where('month', $month)->first();
        if ($default !== null) {
            return ['body' => $default->body, 'source' => 'workspace'];
        }

        return ['body' => null, 'source' => null];
    }

    public function agencyDefault(string $month): ?ReportNote
    {
        return ReportNote::query()->whereNull('brand_id')->where('month', $month)->first();
    }

    public function saveAgencyDefault(string $month, string $body, ?int $updatedByUserId): ReportNote
    {
        return ReportNote::query()->updateOrCreate(
            ['brand_id' => null, 'month' => $month],
            ['body' => $body, 'updated_by' => $updatedByUserId],
        );
    }

    public function saveBrandCopy(Brand $brand, string $month, string $body, ?int $updatedByUserId): ReportNote
    {
        return ReportNote::query()->updateOrCreate(
            ['brand_id' => $brand->id, 'month' => $month],
            ['body' => $body, 'updated_by' => $updatedByUserId],
        );
    }

    /** Revert a brand back to reading the agency-wide default for that month. */
    public function clearBrandCopy(Brand $brand, string $month): void
    {
        ReportNote::query()->where('brand_id', $brand->id)->where('month', $month)->delete();
    }
}
