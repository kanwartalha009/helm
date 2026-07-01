<?php

declare(strict_types=1);

namespace App\Services\Aggregation;

use App\Models\Brand;
use App\Models\DailyMetric;
use App\Models\MetaBreakdownDaily;
use App\Models\PlatformConnection;
use App\Services\Aggregation\Concerns\ScopesBrandsByManager;
use Carbon\CarbonImmutable;

/**
 * Assembles the dashboard's Audience view: each brand's Meta spend split by a
 * breakdown axis (audience segments, placement, age/gender, country, device)
 * over a chosen period. Reads meta_breakdown_daily (populated by the daily sync
 * for `audience`, and by `meta:backfill-breakdown` for the rest) and uses the
 * account-level Meta total from daily_metrics as the authoritative denominator.
 *
 * The key insight this view surfaces (Bosco, 2026-06-29): ASC audience segments
 * (new / engaged / existing / unknown via user_segment_key) only cover Advantage+
 * Shopping spend. The gap between the account total and the sum of the segments
 * is non-ASC spend — we compute it as a "Non-ASC" remainder rather than dropping
 * it, so the composition always reconciles to 100% of real spend.
 *
 * Currency + timezone follow the same rules as DashboardQuery: spend is summed
 * in the brand's native currency (or ×fx to USD when ?currency=USD), and each
 * brand's period window is computed in the BRAND's timezone, never UTC.
 */
final class AudienceQuery
{
    use ScopesBrandsByManager;

    /**
     * Breakdown axes the view understands. All but `region` map to a stored
     * breakdown_type in config/meta_breakdowns.php; `region` is virtual — it reads
     * stored `country` rows and rolls them up (see $storedType in run()).
     */
    private const VALID_BREAKDOWNS = ['audience', 'age_gender', 'placement_platform', 'placement', 'region', 'country', 'device'];

    /**
     * Pretty labels for the publisher_platform values behind the platform-level
     * "Placement" view. Meta returns these lowercase; everything else (countries,
     * age buckets, devices) keeps its stored label.
     *
     * @var array<string, string>
     */
    private const PLATFORM_LABELS = [
        'facebook'        => 'Facebook',
        'instagram'       => 'Instagram',
        'audience_network' => 'Audience Network',
        'messenger'       => 'Messenger',
        'unknown'         => 'Unknown',
    ];

    /**
     * ASC audience segments in funnel order. These are the fixed columns for the
     * `audience` breakdown; the remainder after them is the Non-ASC spend.
     *
     * @var array<string, string> raw user_segment_key => display label
     */
    private const AUDIENCE_LABELS = [
        'prospecting' => 'New',
        'engaged'     => 'Engaged',
        'existing'    => 'Existing',
        'unknown'     => 'Unknown',
    ];

    /**
     * High-cardinality axes (placement/country/…) show as many top segments as
     * needed to cover TARGET_COVERAGE of spend — so "Other" stays small — bounded
     * to [MIN_COLS, MAX_COLS] to keep the table readable. Country is so long-tailed
     * (brands ship to 50+ markets, each different) that it hits MAX_COLS with a
     * real remainder; that's inherent to a shared-column table, not a bug.
     */
    private const MIN_COLS = 4;
    private const MAX_COLS = 8;
    private const TARGET_COVERAGE = 0.90;

    /**
     * @param array<string, mixed> $params
     * @return array{breakdown: string, period: string, currency: string, columns: array<int, array<string, string>>, rows: array<int, array<string, mixed>>}
     */
    public function run(array $params): array
    {
        $breakdown = $this->resolveBreakdown((string) ($params['breakdown'] ?? 'audience'));
        $period    = $this->resolvePeriod((string) ($params['period'] ?? 'last30'));
        $usd       = strtoupper((string) ($params['currency'] ?? '')) === 'USD';

        // The Region view is a VIRTUAL breakdown: it reads the stored `country`
        // rows and rolls them up into regions at read time, so it reuses the
        // existing country backfill (no separate sync). Every other axis reads
        // its own stored breakdown_type.
        $storedType = $breakdown === 'region' ? 'country' : $breakdown;

        // Display spend follows the currency toggle; USD spend is always computed
        // separately so cross-brand/cross-segment ranking is currency-fair even in
        // Native mode (a EUR brand and a SEK brand rank by a common unit).
        $dispExpr = $usd ? 'spend * COALESCE(fx_rate_to_usd, 1)' : 'spend';
        $usdExpr  = 'spend * COALESCE(fx_rate_to_usd, 1)';

        $brandQuery = Brand::query()->where('status', 'active');
        $this->applyManagerScope($brandQuery, $params);
        $brands = $brandQuery->orderBy('name')->get();

        if ($brands->isEmpty()) {
            return ['breakdown' => $breakdown, 'period' => $period, 'currency' => $usd ? 'usd' : 'native', 'columns' => [], 'rows' => []];
        }

        $brandIds = $brands->pluck('id')->all();

        // Only brands with a live Meta connection belong in an audience view — a
        // Shopify-only brand has nothing to split. Prefetch the connections once
        // (status + health) so we can omit non-Meta brands and surface a real
        // "breakdown not synced yet" state instead of a misleading €0.
        $metaConn = PlatformConnection::query()
            ->whereIn('brand_id', $brandIds)
            ->where('platform', 'meta')
            ->get(['brand_id', 'status', 'last_sync_at', 'last_error'])
            ->keyBy('brand_id');

        // Pass 1 — pull each Meta brand's per-segment spend (display + USD) and its
        // authoritative account total, holding everything in memory so the global
        // column ranking can be computed without a second round of queries.
        /** @var array<int, array{brand: Brand, segDisp: array<string, float>, segUsd: array<string, float>, segLabels: array<string, string>, totalDisp: float, totalUsd: float, hasBreakdown: bool, hasSpend: bool, isComplete: bool, conn: ?PlatformConnection}> */
        $perBrand   = [];
        $globalUsd  = [];   // segment_key => summed USD spend across brands (for ranking)
        $globalTotalUsd = 0.0;

        foreach ($brands as $brand) {
            $conn = $metaConn->get($brand->id);
            if ($conn === null || $conn->status !== 'active') {
                continue; // not a Meta brand → not in the audience view
            }

            [$start, $end] = $this->periodWindow($period, $brand->timezone ?: 'UTC');

            $segRows = MetaBreakdownDaily::query()
                ->where('brand_id', $brand->id)
                ->where('breakdown_type', $storedType)
                ->whereBetween('date', [$start, $end])
                ->groupBy('segment_key', 'segment_label')
                ->selectRaw("segment_key, segment_label,
                    COALESCE(SUM({$dispExpr}), 0) AS disp,
                    COALESCE(SUM({$usdExpr}), 0)  AS usd,
                    MIN(CASE WHEN is_complete THEN 1 ELSE 0 END) AS complete,
                    COUNT(*) AS n")
                ->get();

            $totalRow = DailyMetric::query()
                ->where('brand_id', $brand->id)
                ->where('platform', 'meta')
                ->whereBetween('date', [$start, $end])
                ->selectRaw("COALESCE(SUM({$dispExpr}), 0) AS disp, COALESCE(SUM({$usdExpr}), 0) AS usd, COUNT(*) AS n")
                ->first();

            $segDisp = [];
            $segUsd  = [];
            $labels  = [];
            $complete = true;
            foreach ($segRows as $r) {
                // For the Region view, roll the country code up into its region and
                // ACCUMULATE (many countries → one region), so use += not =. Every
                // other axis has unique keys, so += behaves like assignment there.
                $rawKey = (string) $r->segment_key;
                $key    = $breakdown === 'region' ? $this->countryRegion($rawKey) : $rawKey;
                $label  = $breakdown === 'region' ? $key : (string) ($r->segment_label ?: $rawKey);

                $segDisp[$key] = ($segDisp[$key] ?? 0.0) + (float) $r->disp;
                $segUsd[$key]  = ($segUsd[$key] ?? 0.0) + (float) $r->usd;
                $labels[$key]  = $label;
                $complete      = $complete && ((int) $r->complete === 1);

                $globalUsd[$key] = ($globalUsd[$key] ?? 0.0) + (float) $r->usd;
            }

            $totalDisp = (float) ($totalRow->disp ?? 0);
            $totalUsd  = (float) ($totalRow->usd ?? 0);
            $globalTotalUsd += $totalUsd;

            $perBrand[$brand->id] = [
                'brand'        => $brand,
                'segDisp'      => $segDisp,
                'segUsd'       => $segUsd,
                'segLabels'    => $labels,
                'totalDisp'    => $totalDisp,
                'totalUsd'     => $totalUsd,
                'hasBreakdown' => $segRows->isNotEmpty(),
                // Spend to show if the account row OR any breakdown row landed —
                // covers the rare desync where the breakdown pull ran but the
                // account-level daily_metrics row for the day didn't.
                'hasSpend'     => ((int) ($totalRow->n ?? 0) > 0) || $segRows->isNotEmpty(),
                'isComplete'   => $complete,
                'conn'         => $conn,
            ];
        }

        if ($perBrand === []) {
            return ['breakdown' => $breakdown, 'period' => $period, 'currency' => $usd ? 'usd' : 'native', 'columns' => [], 'rows' => []];
        }

        // Decide the column set once, globally, so every row renders the same
        // columns in the same order (and gets the same stone shade per segment).
        [$columns, $columnKeys, $remainderKey] = $this->resolveColumns(
            $breakdown,
            $globalUsd,
            $globalTotalUsd,
            $perBrand,
        );

        // Age & gender gets a single Male-vs-Female comparison column, placed FIRST
        // (right after Total) so who-buys-more is the headline read (Kanwar,
        // 2026-06-30). The per-row male/female values ride in `segments` and the
        // frontend renders them as one split bar. It's an aggregate over ALL of the
        // brand's gender segments and reconciles to ~100%, so it sidesteps the
        // long-tail "Other"; kind='summary' keeps it out of the remainder math and
        // the composition bar so nothing double-counts.
        $genderSummary = $breakdown === 'age_gender';
        if ($genderSummary) {
            array_unshift($columns, ['key' => '__gender', 'label' => 'Gender', 'kind' => 'summary']);
        }

        $health = function (?PlatformConnection $conn): array {
            return [
                'status'     => $conn?->status ?? 'active',
                'lastSyncAt' => $conn?->last_sync_at?->toIso8601String(),
                'hasError'   => $conn ? ($conn->status === 'errored' || ! empty($conn->last_error)) : false,
            ];
        };

        $rows = [];
        foreach ($perBrand as $entry) {
            /** @var Brand $b */
            $b = $entry['brand'];

            // Build the segment map for exactly the chosen columns. The remainder
            // column = total − Σ(shown columns): for `audience` that's Non-ASC
            // spend, for high-cardinality axes it's the non-top-N tail + any gap.
            $segments  = [];
            $shownSum  = 0.0;
            foreach ($columnKeys as $key) {
                $val            = round((float) ($entry['segDisp'][$key] ?? 0.0), 2);
                $segments[$key] = $val;
                $shownSum      += $val;
            }

            // total is authoritative (account-level); clamp so a data race where
            // the breakdown pull ran ahead of the account pull can't make the
            // remainder negative or the bars exceed 100%.
            $total     = max($entry['totalDisp'], $shownSum);
            $remainder = round($total - $shownSum, 2);
            if ($remainderKey !== null) {
                $segments[$remainderKey] = max($remainder, 0.0);
            }

            if ($genderSummary) {
                [$maleDisp, $femaleDisp]     = $this->genderTotals($entry['segDisp']);
                $segments['__gender_male']   = round($maleDisp, 2);
                $segments['__gender_female'] = round($femaleDisp, 2);
            }

            $rows[] = [
                'brand' => [
                    'id'             => $b->id,
                    'name'           => $b->name,
                    'slug'           => $b->slug,
                    'initials'       => $this->initials($b->name),
                    'baseCurrency'   => $b->base_currency,
                    'groupTag'       => $b->group_tag,
                    'region'         => $b->group_tag ?? '—',
                    'platforms'      => ['meta'],
                    'platformHealth' => ['meta' => $health($entry['conn'])],
                ],
                'total'        => round($total, 2),
                'segments'     => $segments,
                'hasBreakdown' => $entry['hasBreakdown'],
                'hasSpend'     => $entry['hasSpend'],
                'isComplete'   => $entry['isComplete'],
            ];
        }

        // Sort brands by total Meta spend desc (biggest spenders first); a brand
        // with spend but no breakdown yet still ranks by its spend, and zero-spend
        // brands sink to the bottom alphabetically.
        usort($rows, function (array $a, array $b): int {
            $at = (float) $a['total'];
            $bt = (float) $b['total'];
            if ($at === $bt) {
                return strcasecmp((string) $a['brand']['name'], (string) $b['brand']['name']);
            }
            return $bt <=> $at;
        });

        return [
            'breakdown' => $breakdown,
            'period'    => $period,
            'currency'  => $usd ? 'usd' : 'native',
            'columns'   => $columns,
            'rows'      => $rows,
        ];
    }

    /**
     * Pick the columns to render and their order, plus the remainder column.
     *
     * - `audience`: the four ASC segments in funnel order (only those that appear
     *   in any brand's data), then an always-present "Non-ASC" remainder.
     * - everything else: the top-N segments by global USD spend, then an "Other"
     *   remainder column — included only when the tail is actually material, so a
     *   clean breakdown (segments already cover ~100%) doesn't grow a dead column.
     *
     * @param array<string, float> $globalUsd
     * @param array<int, array<string, mixed>> $perBrand
     * @return array{0: array<int, array<string, string>>, 1: array<int, string>, 2: ?string}
     *         [columns, shown-column keys, remainder key (null when no remainder column)]
     */
    private function resolveColumns(string $breakdown, array $globalUsd, float $globalTotalUsd, array $perBrand): array
    {
        if ($breakdown === 'audience') {
            $columns    = [];
            $columnKeys = [];
            foreach (self::AUDIENCE_LABELS as $key => $label) {
                if (! array_key_exists($key, $globalUsd)) {
                    continue; // no brand ever had this ASC segment — don't show an empty column
                }
                $columns[]    = ['key' => $key, 'label' => $label, 'kind' => 'segment'];
                $columnKeys[] = $key;
            }
            // Non-ASC is the whole point of the audience view — always shown.
            $columns[] = ['key' => '__remainder', 'label' => 'Non-ASC', 'kind' => 'remainder'];

            return [$columns, $columnKeys, '__remainder'];
        }

        // High-cardinality axis: rank by global USD spend, then show as many top
        // segments as needed to cover TARGET_COVERAGE of spend (so "Other" stays
        // small), bounded to [MIN_COLS, MAX_COLS]. Generic catch-all keys (device
        // "other", all-unknown combos) are dropped from the running so they fold
        // into the single "Other" remainder instead of doubling it.
        arsort($globalUsd);
        $ranked  = array_filter(array_keys($globalUsd), fn (string $k): bool => ! $this->isCatchAll($k));
        $topKeys = [];
        $cum     = 0.0;
        foreach ($ranked as $key) {
            $topKeys[] = $key;
            $cum      += (float) $globalUsd[$key];
            $covered   = $globalTotalUsd > 0 ? $cum / $globalTotalUsd : 1.0;
            if (count($topKeys) >= self::MAX_COLS) {
                break;
            }
            if (count($topKeys) >= self::MIN_COLS && $covered >= self::TARGET_COVERAGE) {
                break;
            }
        }

        // Prefer a human label seen on the rows for each kept key. The platform-
        // level placement view maps the raw publisher_platform value to a pretty
        // label (audience_network → "Audience Network").
        $labelFor = function (string $key) use ($perBrand, $breakdown): string {
            if ($breakdown === 'placement_platform') {
                return self::PLATFORM_LABELS[strtolower($key)] ?? ucwords(str_replace('_', ' ', $key));
            }
            foreach ($perBrand as $entry) {
                if (isset($entry['segLabels'][$key]) && $entry['segLabels'][$key] !== '') {
                    return (string) $entry['segLabels'][$key];
                }
            }
            return $key === '' ? 'Unknown' : $key;
        };

        $columns    = [];
        $columnKeys = [];
        $keptUsd    = 0.0;
        foreach ($topKeys as $key) {
            $columns[]    = ['key' => $key, 'label' => $labelFor($key), 'kind' => 'segment'];
            $columnKeys[] = $key;
            $keptUsd     += (float) ($globalUsd[$key] ?? 0.0);
        }

        // "Other" = everything outside the top N, plus any account-vs-breakdown gap.
        // Only worth a column when it's a meaningful slice of total spend.
        $tailUsd = max($globalTotalUsd - $keptUsd, 0.0);
        if ($globalTotalUsd > 0 && ($tailUsd / $globalTotalUsd) > 0.01) {
            $columns[] = ['key' => '__remainder', 'label' => 'Other', 'kind' => 'remainder'];

            return [$columns, $columnKeys, '__remainder'];
        }

        return [$columns, $columnKeys, null];
    }

    /** Validate the breakdown axis against the supported set; fall back to audience. */
    private function resolveBreakdown(string $breakdown): string
    {
        $breakdown = strtolower(trim($breakdown));

        return in_array($breakdown, self::VALID_BREAKDOWNS, true) ? $breakdown : 'audience';
    }

    /** Validate the period; fall back to last30. */
    private function resolvePeriod(string $period): string
    {
        $period = strtolower(trim($period));

        return in_array($period, ['last7', 'last30', 'mtd'], true) ? $period : 'last30';
    }

    /**
     * [start, end] date strings in the brand's timezone. End is yesterday (today
     * is partial — the live sync owns it). Windows mirror the dashboard: last7 =
     * the 7 days ending yesterday, last30 likewise, mtd = the 1st through yesterday.
     *
     * @return array{0: string, 1: string}
     */
    private function periodWindow(string $period, string $tz): array
    {
        $now       = CarbonImmutable::now($tz);
        $yesterday = $now->subDay()->startOfDay();

        $start = match ($period) {
            'last7'  => $now->subDays(7)->startOfDay(),
            'mtd'    => $now->startOfMonth(),
            default  => $now->subDays(30)->startOfDay(), // last30
        };

        // On the 1st of the month MTD's start would sit after yesterday — clamp so
        // the window is always at least one day and never start > end.
        if ($start->greaterThan($yesterday)) {
            $start = $yesterday;
        }

        return [$start->toDateString(), $yesterday->toDateString()];
    }

    /**
     * Sum a brand's age×gender spend into male / female totals for the Age &
     * gender summary columns. The segment key looks like "25-34 · female", so
     * check 'female' before 'male' (the former contains the latter). Unknown-
     * gender rows are excluded from both — the split is about the two audiences.
     *
     * @param array<string, float> $segDisp
     * @return array{0: float, 1: float} [male, female]
     */
    private function genderTotals(array $segDisp): array
    {
        $male   = 0.0;
        $female = 0.0;
        foreach ($segDisp as $key => $val) {
            $k = strtolower((string) $key);
            if (str_contains($k, 'female')) {
                $female += (float) $val;
            } elseif (str_contains($k, 'male')) {
                $male += (float) $val;
            }
        }

        return [$male, $female];
    }

    /**
     * Map an ISO-2 country code to its region label for the Region view. Unknown
     * or unlisted codes fall back to "Other" so regions always reconcile to 100%.
     */
    private function countryRegion(string $code): string
    {
        $labels = (array) config('country_regions.labels', []);
        $map    = (array) config('country_regions.map', []);
        $key    = $map[strtoupper(trim($code))] ?? 'other';

        return (string) ($labels[$key] ?? 'Other');
    }

    /**
     * True when EVERY part of a segment key is a generic catch-all (unknown /
     * other / not-set) — e.g. device "other", or an all-unknown combo. For
     * non-audience axes these fold into the single "Other" remainder rather than
     * standing as their own column, so the table never shows two competing
     * "Other"-ish columns (the device view had exactly that).
     */
    private function isCatchAll(string $key): bool
    {
        $parts = array_map('trim', explode(' · ', strtolower($key)));
        foreach ($parts as $p) {
            if (! in_array($p, ['', 'unknown', 'other', 'others', '(not set)', 'not set', 'none'], true)) {
                return false;
            }
        }

        return $parts !== [];
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        if (count($parts) >= 2) {
            return strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1));
        }

        return strtoupper(mb_substr($name, 0, 2));
    }
}
