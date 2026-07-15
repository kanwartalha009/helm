<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Brand;
use App\Models\CountryTier;
use Illuminate\Support\Collection;

/**
 * M1 (monthly-report-v2-mom.md §M1): country tiers as a PLATFORM PRIMITIVE.
 * `resolve()` is the ONE definition of "what tier is this country in" — the mom
 * report (M2/M3) and any other surface that shows a country breakdown (dashboard,
 * ads hub, ads-audit) call THIS, never a second tier mapping anywhere else in the
 * codebase. If you're about to write a country->group lookup, call this instead.
 *
 * Resolution: a brand's OWN tier rows if it has any, else the agency-wide DEFAULT
 * set (brand_id IS NULL). A country not covered by any tier is "Other" — that's a
 * property of resolve()'s output (the country's key is simply absent from the
 * returned map), never a fabricated tier assignment.
 */
class CountryTiers
{
    /**
     * @return array<string, array{tierKey: string, label: string, color: string}>
     *   ISO-2 -> tier info. A country absent here has no tier assigned — callers
     *   render it as "Other", they do not drop it.
     */
    public function resolve(Brand $brand): array
    {
        $map = [];
        foreach ($this->tiersFor($brand) as $row) {
            $countries = is_array($row->countries) ? $row->countries : [];
            foreach ($countries as $iso2) {
                $iso2 = strtoupper((string) $iso2);
                if ($iso2 === '') {
                    continue;
                }
                // First tier wins if a country is (mis-)listed in two rows —
                // position order, so the top tier in Settings takes precedence.
                $map[$iso2] ??= [
                    'tierKey' => (string) $row->tier_key,
                    'label'   => (string) $row->label,
                    'color'   => (string) $row->color,
                ];
            }
        }

        return $map;
    }

    /**
     * The tier catalog itself (rows, not the resolved country map) — what Settings
     * CRUD reads/writes. Same brand-override-else-agency-default resolution as
     * resolve(), because a country's tier and the editable tier LIST must always
     * agree — showing one set while resolve() secretly uses another would be a
     * silent, undebuggable mismatch.
     */
    public function tiersFor(Brand $brand): Collection
    {
        $brandRows = CountryTier::query()->where('brand_id', $brand->id)->orderBy('position')->get();
        if ($brandRows->isNotEmpty()) {
            return $brandRows;
        }

        return CountryTier::query()->whereNull('brand_id')->orderBy('position')->get();
    }

    /** True when the brand has customized its own set (vs still reading the agency default). */
    public function hasOverride(Brand $brand): bool
    {
        return CountryTier::query()->where('brand_id', $brand->id)->exists();
    }

    /**
     * Replace this brand's OWN tier set (the "customize for this brand" action —
     * copies-then-edits from the caller's perspective: the caller sends the full
     * desired row list, seeded client-side from the agency default on first edit).
     * Insert-then-delete-old inside a transaction so a mid-write failure never
     * leaves a brand with a half-replaced tier set (partial tiers would silently
     * misclassify countries).
     *
     * @param array<int, array{tier_key: string, label: string, color: string, countries: array<int, string>}> $rows
     */
    public function replaceBrandTiers(Brand $brand, array $rows, ?int $updatedByUserId): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($brand, $rows, $updatedByUserId): void {
            CountryTier::query()->where('brand_id', $brand->id)->delete();

            foreach ($rows as $i => $row) {
                CountryTier::query()->create([
                    'brand_id'   => $brand->id,
                    'tier_key'   => $row['tier_key'],
                    'label'      => $row['label'],
                    'color'      => $row['color'],
                    'countries'  => array_values(array_unique(array_map(
                        static fn (string $c): string => strtoupper($c),
                        $row['countries'] ?? [],
                    ))),
                    'position'   => $i,
                    'updated_by' => $updatedByUserId,
                ]);
            }
        });
    }

    /** Delete the brand's override set — the brand reverts to reading the agency default. */
    public function clearBrandTiers(Brand $brand): void
    {
        CountryTier::query()->where('brand_id', $brand->id)->delete();
    }

    /** The agency-wide DEFAULT tier set (brand_id IS NULL) — Settings -> General editing surface. */
    public function agencyDefaultTiers(): Collection
    {
        return CountryTier::query()->whereNull('brand_id')->orderBy('position')->get();
    }

    /**
     * Replace the agency-wide DEFAULT tier set. master_admin only (route-gated,
     * same as workspace-settings) — this is the fallback template every brand
     * without its own override reads from, so it's an agency-level decision, not
     * a per-brand one.
     *
     * @param array<int, array{tier_key: string, label: string, color: string, countries: array<int, string>}> $rows
     */
    public function replaceAgencyDefaultTiers(array $rows, ?int $updatedByUserId): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($rows, $updatedByUserId): void {
            CountryTier::query()->whereNull('brand_id')->delete();

            foreach ($rows as $i => $row) {
                CountryTier::query()->create([
                    'brand_id'   => null,
                    'tier_key'   => $row['tier_key'],
                    'label'      => $row['label'],
                    'color'      => $row['color'],
                    'countries'  => array_values(array_unique(array_map(
                        static fn (string $c): string => strtoupper($c),
                        $row['countries'] ?? [],
                    ))),
                    'position'   => $i,
                    'updated_by' => $updatedByUserId,
                ]);
            }
        });
    }
}
