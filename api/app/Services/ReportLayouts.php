<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Brand;
use App\Models\ReportLayout;

/**
 * M1 + REV2 R2 (monthly-report-v2-mom.md): the report customizer's read path.
 * Resolution: brand OVERRIDE -> agency-wide DEFAULT (brand_id IS NULL) -> the
 * CODE default (config/momreport.php's 'sections' catalog, keyed by report_type).
 *
 * A share snapshots resolve()'s OUTPUT into the share's filters json at
 * share-creation time (ReportController::createShare) — that snapshot is a plain
 * value copy, so re-customizing the live layout afterward can never reshuffle an
 * already-issued client link. This service has no share-awareness itself; it only
 * guarantees resolve() is a pure function of (brand, report_type, current rows) at
 * the moment it's called, which is what makes snapshotting it safe.
 */
class ReportLayouts
{
    /**
     * @return array<int, array{key: string, label: string, enabled: bool, position: int, view: string, settings: ?array}>
     *   Ordered by position ascending.
     */
    public function resolve(Brand $brand, string $reportType): array
    {
        $row = ReportLayout::query()
            ->where('brand_id', $brand->id)
            ->where('report_type', $reportType)
            ->first();

        $row ??= ReportLayout::query()
            ->whereNull('brand_id')
            ->where('report_type', $reportType)
            ->first();

        $sections = $row !== null
            ? $this->normalize(is_array($row->sections) ? $row->sections : [])
            : $this->codeDefault($reportType);

        usort($sections, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);

        return $sections;
    }

    /** True when this brand has its own saved layout (vs reading the agency default or code default). */
    public function hasOverride(Brand $brand, string $reportType): bool
    {
        return ReportLayout::query()
            ->where('brand_id', $brand->id)
            ->where('report_type', $reportType)
            ->exists();
    }

    /**
     * The CODE-default catalog for a report type — config/momreport.php for 'mom',
     * empty for any type this program doesn't know about (never guesses a layout
     * for a report type it has no catalog for).
     *
     * @return array<int, array{key: string, label: string, enabled: bool, position: int, view: string, settings: ?array}>
     */
    public function codeDefault(string $reportType): array
    {
        if ($reportType !== 'mom') {
            return [];
        }

        $catalog = config('momreport.sections', []);
        $out = [];
        foreach ($catalog as $i => $s) {
            $out[] = [
                'key'      => (string) $s['key'],
                'label'    => (string) ($s['label'] ?? $s['key']),
                'enabled'  => (bool) ($s['enabled'] ?? true),
                'position' => $i,
                'view'     => (string) ($s['view'] ?? 'both'),
                'settings' => null,
            ];
        }

        return $out;
    }

    /**
     * Persist a full layout — brand-scoped (brand override) or agency-wide
     * (brand_id null, master_admin only per the route gate). Full-replace
     * semantics: the caller sends the complete desired section list.
     *
     * @param array<int, array{key: string, enabled: bool, position: int, view: string, settings?: ?array}> $sections
     */
    public function save(?Brand $brand, string $reportType, array $sections, ?int $updatedByUserId): ReportLayout
    {
        return ReportLayout::query()->updateOrCreate(
            ['brand_id' => $brand?->id, 'report_type' => $reportType],
            [
                'sections'   => $this->normalize($sections),
                'updated_by' => $updatedByUserId,
            ],
        );
    }

    /** Delete a brand's override layout — it reverts to the agency default / code default. */
    public function clearBrandLayout(Brand $brand, string $reportType): void
    {
        ReportLayout::query()->where('brand_id', $brand->id)->where('report_type', $reportType)->delete();
    }

    /**
     * The agency-wide DEFAULT layout for a report type, falling back to the code
     * default if the agency hasn't customized it yet — Settings -> "Report format"
     * editing surface (master_admin only, route-gated).
     *
     * @return array<int, array{key: string, label: string, enabled: bool, position: int, view: string, settings: ?array}>
     */
    public function agencyDefaultLayout(string $reportType): array
    {
        $row = ReportLayout::query()->whereNull('brand_id')->where('report_type', $reportType)->first();
        $sections = $row !== null ? $this->normalize(is_array($row->sections) ? $row->sections : []) : $this->codeDefault($reportType);
        usort($sections, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);

        return $sections;
    }

    /**
     * Fill defaults for any field the caller omitted and re-index position so it's
     * always a clean 0..n-1 sequence — the customizer's up/down buttons only ever
     * need to swap two positions, never renumber everything themselves.
     *
     * @param array<int, array<string, mixed>> $sections
     * @return array<int, array{key: string, label: string, enabled: bool, position: int, view: string, settings: ?array}>
     */
    private function normalize(array $sections): array
    {
        $out = [];
        $i = 0;
        foreach ($sections as $s) {
            if (! isset($s['key'])) {
                continue;
            }
            $out[] = [
                'key'      => (string) $s['key'],
                'label'    => (string) ($s['label'] ?? $s['key']),
                'enabled'  => (bool) ($s['enabled'] ?? true),
                'position' => $i,
                'view'     => in_array($s['view'] ?? null, ['chart', 'table', 'both'], true) ? $s['view'] : 'both',
                'settings' => $s['settings'] ?? null,
            ];
            $i++;
        }

        return $out;
    }
}
