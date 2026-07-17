<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Reports\Mom\Support\CountryRevenueSpend;
use App\Services\CountryTiers;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * M1 (monthly-report-v2-mom.md §M1): country tiers CRUD, both layers.
 *
 *  - show()/store()/destroy()               — this BRAND's own tier set (brand Settings,
 *                                              the PRIMARY UI per Kanwar 2026-07-12).
 *  - showAgencyDefault()/storeAgencyDefault() — the agency-wide DEFAULT set every brand
 *                                              without its own override reads from
 *                                              (Settings -> General, master_admin only,
 *                                              same gate as workspace-settings).
 *  - availableCountries()                    — M5 addendum (Kanwar, 2026-07-15 — "tier
 *                                              sidebar... show list of countries against
 *                                              the brand to group them"). This explicitly
 *                                              SUPERSEDES the "PRIMARY UI = brand Settings"
 *                                              framing above: the sidebar becomes the real
 *                                              editing surface (brand-level button AND a
 *                                              button on the mom report itself), Settings
 *                                              keeps working unmodified as a second entry
 *                                              point onto the exact same CRUD routes below.
 *
 * Reading is brand-visible (any accessible-brand viewer sees which tier a country is
 * in); writing shapes what the mom report groups countries into for a client meeting,
 * so it's admin/manager only — mirrors BrandTargetController's split exactly.
 */
class CountryTierController extends Controller
{
    public function __construct(private readonly CountryTiers $tiers)
    {
    }

    /**
     * The brand's ACTUAL countries — real Shopify-revenue/Meta-spend rows over
     * a trailing 6-full-month window (`CountryRevenueSpend`, the SAME join S5/
     * S6 use — never a second country list), so the sidebar shows countries
     * this brand really sells/advertises into rather than a free-text field a
     * user has to type ISO-2 codes into blind.
     *
     * Unioned with any ISO-2 already sitting in one of this brand's resolved
     * tiers (`CountryTiers::resolve()`) even if that country has zero revenue
     * in the trailing window — an existing assignment must never silently
     * disappear from the picker just because the window rolled past it. Those
     * revenue-less entries carry `revenue: null`/`spend: null` (honest
     * "no recent data", never a fabricated 0) and sort after the real rows.
     */
    public function availableCountries(Brand $brand): JsonResponse
    {
        $this->authorize('view', $brand);

        $tz  = $brand->timezone ?: 'UTC';
        $end = CarbonImmutable::now($tz)->startOfMonth()->subDay()->endOfDay(); // last complete month end
        $start = $end->startOfMonth()->subMonths(5)->startOfMonth(); // 6 full months back, inclusive

        $joiner = new CountryRevenueSpend();
        $rows   = $joiner->compute($brand->id, $start->toDateString(), $end->toDateString());

        $resolved = $this->tiers->resolve($brand);

        $out = [];
        foreach ($rows as $key => $row) {
            if ($row['iso2'] === '') {
                continue; // '__unmatched_*' commerce rows — can't be assigned a tier without a resolvable ISO-2
            }
            $out[$row['iso2']] = [
                'iso2'    => $row['iso2'],
                'label'   => $row['label'],
                'revenue' => $row['revenue'],
                'spend'   => $row['spend'],
                'tierKey' => $resolved[$row['iso2']]['tierKey'] ?? null,
            ];
        }

        // Union in already-assigned countries this window's revenue/spend join
        // didn't surface (e.g. a market the brand exited, or a manually-typed
        // ISO-2 from before this sidebar existed) — honest null figures, not 0.
        foreach ($resolved as $iso2 => $tier) {
            if (! isset($out[$iso2])) {
                $out[$iso2] = ['iso2' => $iso2, 'label' => $iso2, 'revenue' => null, 'spend' => null, 'tierKey' => $tier['tierKey']];
            }
        }

        $list = array_values($out);
        usort($list, static function (array $a, array $b): int {
            // Real revenue rows first (highest revenue first), then the
            // revenue-less unioned-in rows alphabetically by ISO-2.
            if ($a['revenue'] === null && $b['revenue'] === null) {
                return $a['iso2'] <=> $b['iso2'];
            }
            if ($a['revenue'] === null) {
                return 1;
            }
            if ($b['revenue'] === null) {
                return -1;
            }

            return $b['revenue'] <=> $a['revenue'];
        });

        return response()->json(['countries' => $list, 'windowMonths' => 6]);
    }

    public function show(Brand $brand): JsonResponse
    {
        $this->authorize('view', $brand);

        $rows = $this->tiers->tiersFor($brand);

        return response()->json([
            'tiers'       => $rows->map(fn ($t) => $this->present($t))->values(),
            'resolved'    => $this->tiers->resolve($brand),
            'hasOverride' => $this->tiers->hasOverride($brand),
        ]);
    }

    public function store(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('update', $brand);

        $rows = $this->validateRows($request);
        $this->tiers->replaceBrandTiers($brand, $rows, Auth::id());

        return response()->json(['ok' => true, 'tiers' => $this->tiers->tiersFor($brand)->map(fn ($t) => $this->present($t))->values()], 201);
    }

    public function destroy(Brand $brand): JsonResponse
    {
        $this->authorize('update', $brand);

        $this->tiers->clearBrandTiers($brand);

        return response()->json(['ok' => true]);
    }

    public function showAgencyDefault(): JsonResponse
    {
        $rows = $this->tiers->agencyDefaultTiers();

        return response()->json(['tiers' => $rows->map(fn ($t) => $this->present($t))->values()]);
    }

    public function storeAgencyDefault(Request $request): JsonResponse
    {
        $rows = $this->validateRows($request);
        $this->tiers->replaceAgencyDefaultTiers($rows, Auth::id());

        return response()->json(['ok' => true, 'tiers' => $this->tiers->agencyDefaultTiers()->map(fn ($t) => $this->present($t))->values()], 201);
    }

    /** @return array<int, array{tier_key: string, label: string, color: string, countries: array<int, string>}> */
    private function validateRows(Request $request): array
    {
        // The read endpoint returns `tierKey` (camelCase, via present()), so the
        // SPA/tier drawer naturally sends `tierKey` back on save. Accept that here
        // — normalise camelCase `tierKey` to the snake_case `tier_key` this
        // controller (and CountryTiers) works in — so a save doesn't 422 on a
        // missing `tier_key` purely because of the casing mismatch (Kanwar,
        // 2026-07-16 — "why can't I save tiers"). snake_case input still works.
        $request->merge([
            'tiers' => collect($request->input('tiers', []))->map(static function ($t) {
                if (is_array($t) && ! isset($t['tier_key']) && isset($t['tierKey'])) {
                    $t['tier_key'] = $t['tierKey'];
                }

                return $t;
            })->all(),
        ]);

        $data = $request->validate([
            'tiers'                    => ['required', 'array', 'max:30'],
            'tiers.*.tier_key'         => ['required', 'string', 'max:24'],
            'tiers.*.label'            => ['required', 'string', 'max:48'],
            'tiers.*.color'            => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'tiers.*.countries'        => ['nullable', 'array'],
            'tiers.*.countries.*'      => ['string', 'size:2'],
        ]);

        $keys = array_map(static fn (array $t): string => strtoupper($t['tier_key']), $data['tiers']);
        if (count($keys) !== count(array_unique($keys))) {
            abort(422, 'Duplicate tier_key — every tier needs a unique key.');
        }

        return $data['tiers'];
    }

    /** @return array<string, mixed> */
    private function present(\App\Models\CountryTier $t): array
    {
        return [
            'id'        => $t->id,
            'tierKey'   => $t->tier_key,
            'label'     => $t->label,
            'color'     => $t->color,
            'countries' => $t->countries ?? [],
            'position'  => $t->position,
        ];
    }
}
