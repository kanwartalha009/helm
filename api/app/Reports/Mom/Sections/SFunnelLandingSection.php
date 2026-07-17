<?php

declare(strict_types=1);

namespace App\Reports\Mom\Sections;

use App\Models\Brand;
use App\Models\ShopifyFunnelDaily;
use App\Reports\Contracts\ReportFilters;
use App\Reports\Mom\Contracts\MomSection;
use Carbon\CarbonImmutable;

/**
 * M2 (monthly-report-v2-mom.md §M2) — "S11... by landing path (slide 23) —
 * sessions->ATC->checkout->purchase rates with YoY sub-rows; red/green flags
 * vs config thresholds."
 *
 * Reads `shopify_funnel_daily` dimension='landing'. Entry pages with zero
 * direct purchases are dropped from the client-facing table — a wall of
 * 0/0/0 rows reads as broken, same judgment call v1's funnel section makes
 * (reimplemented independently here, not shared code, per REV2 R7). Path
 * prettification (raw Shopify paths -> "T shirts (collection)" style labels)
 * is intentionally NOT reimplemented this pass — it's cosmetic, not a data
 * gap, and the raw path is still a correct, honest label on its own.
 */
final class SFunnelLandingSection implements MomSection
{
    public function key(): string
    {
        return 'S11';
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
                'note'   => 'Run shopify:backfill-funnel for this brand to populate the landing-path funnel.',
            ];
        }
        if ($cur === []) {
            return ['key' => $this->key(), 'status' => 'no_data', 'note' => 'No landing pages with completed purchases this month.'];
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
            'summary' => $this->summary($cur),
            'rows'   => array_slice($cur, 0, 15),
            'unavailable' => [
                'thresholdFlags' => 'No funnel-stage CVR threshold confirmed in config/momreport.php yet — raw rates only, no red/green flag.',
                'pathLabels'     => 'Raw Shopify path shown — cosmetic prettification not reimplemented this pass.',
            ],
        ];
    }

    /** @return array<int, array<string, mixed>>|null */
    private function funnel(int $brandId, string $start, string $end): ?array
    {
        $rows = ShopifyFunnelDaily::query()
            ->where('brand_id', $brandId)
            ->where('dimension', 'landing')
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
            $purchase = (int) $r->completed_checkout;
            if ($purchase <= 0) {
                continue; // no direct purchase attributed to this landing page — drop, don't pad the table
            }
            $sessions = (int) $r->sessions;
            $cart     = (int) $r->cart_additions;
            $checkout = (int) $r->reached_checkout;
            $out[] = [
                'key'      => (string) $r->segment_key,
                'label'    => (string) ($r->label ?: $r->segment_key),
                'sessions' => $sessions,
                // Kanwar, 2026-07-16: funnel stages as % of sessions, not raw counts.
                'cart'     => $cart,
                'checkout' => $checkout,
                'purchase' => $purchase,
                'cartPct'     => $sessions > 0 ? round($cart / $sessions * 100, 2) : null,
                'checkoutPct' => $sessions > 0 ? round($checkout / $sessions * 100, 2) : null,
                'purchasePct' => $sessions > 0 ? round($purchase / $sessions * 100, 2) : null,
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

    /**
     * Brand-wide funnel rates across every landing page — the "Summary" line.
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
}
