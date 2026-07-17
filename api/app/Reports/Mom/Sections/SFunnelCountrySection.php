<?php

declare(strict_types=1);

namespace App\Reports\Mom\Sections;

use App\Models\Brand;
use App\Models\ShopifyFunnelDaily;
use App\Reports\Contracts\ReportFilters;
use App\Reports\Mom\Contracts\MomSection;
use Carbon\CarbonImmutable;

/**
 * M2 (monthly-report-v2-mom.md §M2) — "S10 Funnel by country... (slide 22) —
 * sessions->ATC->checkout->purchase rates with YoY sub-rows; red/green flags
 * vs config thresholds."
 *
 * Reads `shopify_funnel_daily` dimension='country' (populated by
 * shopify:backfill-funnel — same table/contract v1's funnel sections already
 * read, reimplemented independently here per REV2 R7). "Red/green flags vs
 * config thresholds" — no funnel-stage conversion-rate threshold exists in
 * `config/momreport.php`'s benchmarks yet (only placement/audience/Klaviyo
 * ones); flags are NOT fabricated against a guessed number — the raw CVR per
 * stage is returned and flagging is deferred until a real threshold is
 * confirmed (logged unavailable).
 */
final class SFunnelCountrySection implements MomSection
{
    public function key(): string
    {
        return 'S10';
    }

    public function build(Brand $brand, ReportFilters $filters): array
    {
        $tz = $brand->timezone ?: 'UTC';
        $window = $filters->activeWindow($tz);
        if ($window === null) {
            return ['key' => $this->key(), 'status' => 'no_data', 'note' => 'No complete month selected.'];
        }
        [$start, $end] = $window;

        $cur = $this->funnel($brand->id, $start, $end);
        if ($cur === null) {
            return [
                'key'    => $this->key(),
                'status' => 'needs_source',
                'note'   => 'Run shopify:backfill-funnel for this brand to populate the country funnel.',
            ];
        }

        $compareWindow = $filters->activeComparisonWindow($tz);
        $cmp = $compareWindow !== null ? $this->funnel($brand->id, $compareWindow[0], $compareWindow[1]) : null;
        $cmpByKey = $cmp !== null ? collect($cmp)->keyBy('key') : collect();

        foreach ($cur as &$row) {
            $prev = $cmpByKey->get($row['key']);
            $row['compareCvr'] = $prev['cvr'] ?? null;
            $row['deltaPct'] = $this->delta($row['cvr'], $prev['cvr'] ?? null);
        }
        unset($row);

        return [
            'key'    => $this->key(),
            'status' => 'ok',
            'month'  => CarbonImmutable::parse($start)->format('Y-m'),
            'compareMonth' => $compareWindow !== null ? CarbonImmutable::parse($compareWindow[0])->format('Y-m') : null,
            // Summary row (all countries) — funnel RATES over the whole window.
            'summary' => $this->summary($cur),
            'rows'   => array_slice($cur, 0, 15),
            'unavailable' => [
                'thresholdFlags' => 'No funnel-stage CVR threshold confirmed in config/momreport.php yet — raw rates only, no red/green flag.',
            ],
        ];
    }

    /**
     * Brand-wide funnel rates across every row — the "Summary" line.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function summary(array $rows): array
    {
        $sessions = 0;
        $cart = 0;
        $checkout = 0;
        $purchase = 0;
        foreach ($rows as $r) {
            $sessions += (int) $r['sessions'];
            $cart     += (int) $r['cart'];
            $checkout += (int) $r['checkout'];
            $purchase += (int) $r['purchase'];
        }

        return [
            'label'    => 'Summary',
            'sessions' => $sessions,
            'cartPct'     => $sessions > 0 ? round($cart / $sessions * 100, 2) : null,
            'checkoutPct' => $sessions > 0 ? round($checkout / $sessions * 100, 2) : null,
            'completedCheckoutRate' => $checkout > 0 ? round($purchase / $checkout * 100, 2) : null,
            'cvr'      => $sessions > 0 ? round($purchase / $sessions * 100, 2) : null,
        ];
    }

    /** @return array<int, array<string, mixed>>|null */
    private function funnel(int $brandId, string $start, string $end): ?array
    {
        $rows = ShopifyFunnelDaily::query()
            ->where('brand_id', $brandId)
            ->where('dimension', 'country')
            ->whereBetween('date', [$start, $end])
            ->groupBy('segment_key', 'segment_label')
            ->selectRaw('segment_key, MAX(segment_label) AS label,
                COALESCE(SUM(sessions), 0) AS sessions,
                COALESCE(SUM(cart_additions), 0) AS cart_additions,
                COALESCE(SUM(reached_checkout), 0) AS reached_checkout,
                COALESCE(SUM(completed_checkout), 0) AS completed_checkout')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $out = [];
        foreach ($rows as $r) {
            $sessions = (int) $r->sessions;
            $cart     = (int) $r->cart_additions;
            $checkout = (int) $r->reached_checkout;
            $purchase = (int) $r->completed_checkout;
            $out[] = [
                'key'      => (string) $r->segment_key,
                'label'    => (string) ($r->label ?: $r->segment_key),
                'sessions' => $sessions,
                // Kanwar, 2026-07-16: funnel stages shown as % OF SESSIONS, not raw
                // counts. Raw counts kept too (for tooltips/exports), % is the render.
                'cart'     => $cart,
                'checkout' => $checkout,
                'purchase' => $purchase,
                'cartPct'     => $sessions > 0 ? round($cart / $sessions * 100, 2) : null,
                'checkoutPct' => $sessions > 0 ? round($checkout / $sessions * 100, 2) : null,
                'purchasePct' => $sessions > 0 ? round($purchase / $sessions * 100, 2) : null,
                // Completed-checkout rate = purchases ÷ reached-checkout (the
                // checkout→purchase step), distinct from the session-level CVR.
                'completedCheckoutRate' => $checkout > 0 ? round($purchase / $checkout * 100, 2) : null,
                'cvr'      => $sessions > 0 ? round($purchase / $sessions * 100, 2) : null,
            ];
        }
        usort($out, static fn (array $a, array $b): int => $b['sessions'] <=> $a['sessions']);

        return $out;
    }

    private function delta(?float $value, ?float $compare): ?float
    {
        if ($value === null || $compare === null || $compare === 0.0) {
            return null;
        }

        return round(($value - $compare) / $compare * 100, 1);
    }
}
