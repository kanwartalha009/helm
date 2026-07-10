<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\DailyMetric;
use App\Reports\Support\AdAudit;
use App\Reports\Support\DeadInventory;
use App\Services\Rules\ProductFlags;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Store audit findings (feature spec §2 store/conversion audit, slice 2.4).
 * RULES ONLY — every finding is composed from the existing engines
 * (AdAudit campaign verdicts, DeadInventory stock rules, sync freshness).
 * No LLM, no invented thresholds: this endpoint only rearranges what the
 * rules engines already assert, so the badges a client sees are
 * deterministic (spec §4.3: "rules, never LLM").
 */
class BrandAuditFindingsController extends Controller
{
    public function __construct(
        private readonly AdAudit $ads,
        private readonly DeadInventory $inventory,
        private readonly ProductFlags $productFlags,
    ) {}

    public function index(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('view', $brand);

        $data = $request->validate([
            'period' => ['nullable', 'in:last7,last30,mtd'],
        ]);

        $tz        = $brand->timezone ?: 'UTC';
        $yesterday = CarbonImmutable::now($tz)->subDay()->startOfDay();
        [$start, $end] = match ($data['period'] ?? 'last30') {
            'last7' => [$yesterday->subDays(6), $yesterday],
            'mtd'   => [CarbonImmutable::now($tz)->startOfMonth(), $yesterday],
            default => [$yesterday->subDays(29), $yesterday],
        };
        $len        = $start->diffInDays($end) + 1;
        $priorEnd   = $start->subDay();
        $priorStart = $priorEnd->subDays($len - 1);

        $findings = [];

        // --- Data freshness (same rule as the report gate) ------------------
        $lastComplete = DailyMetric::query()
            ->where('brand_id', $brand->id)
            ->where('platform', 'shopify')
            ->where('is_complete', true)
            ->max('date');
        $last      = $lastComplete !== null ? CarbonImmutable::parse((string) $lastComplete)->startOfDay() : null;
        $staleDays = ($last !== null && $last->lessThan($yesterday)) ? (int) $last->diffInDays($yesterday) : 0;

        if ($last === null) {
            $findings[] = $this->finding('data', 'critical', 'No synced revenue data', 'No complete Shopify day is on file for this brand. Run a sync before trusting anything on this page.');
        } elseif ($staleDays > 0) {
            $findings[] = $this->finding(
                'data',
                $staleDays >= 3 ? 'critical' : 'warn',
                "Data is {$staleDays} day" . ($staleDays === 1 ? '' : 's') . ' behind',
                "The latest complete day on file is {$last->toDateString()}. Findings below reflect that window, not today.",
            );
        }

        // --- Ads: campaign verdicts from the rules engine --------------------
        $connected = $brand->connections()->where('status', 'active')->pluck('platform')->all();
        foreach (['meta', 'google', 'tiktok'] as $platform) {
            if (! in_array($platform, $connected, true)) {
                continue;
            }
            $audit = $this->ads->forPlatform(
                $brand->id, $platform,
                $start->toDateString(), $end->toDateString(),
                $priorStart->toDateString(), $priorEnd->toDateString(),
                usd: false,
            );
            if ($audit === null) {
                continue; // no campaign rows in the window — absent, not zero
            }

            $label = ucfirst($platform);
            if (($audit['waste']['count'] ?? 0) > 0) {
                $findings[] = $this->finding(
                    'ads',
                    ($audit['waste']['sharePct'] ?? 0) >= 25 ? 'critical' : 'warn',
                    "{$label}: {$audit['waste']['count']} campaign" . ($audit['waste']['count'] === 1 ? '' : 's') . ' burning spend',
                    number_format((float) $audit['waste']['amount'], 2) . " {$brand->base_currency} of {$label} spend in this window sits in sub-1× ROAS campaigns"
                        . (($audit['waste']['sharePct'] ?? null) !== null ? " ({$audit['waste']['sharePct']}% of the platform's spend)." : '.'),
                    ['platform' => $platform, 'actions' => $audit['actions']],
                );
            }
            foreach ($audit['actions'] as $action) {
                if ($action['kind'] === 'scale') {
                    $findings[] = $this->finding('ads', 'good', "{$label}: {$action['title']}", $action['body'], ['platform' => $platform]);
                }
                if ($action['kind'] === 'fix') {
                    $findings[] = $this->finding('ads', 'warn', "{$label}: {$action['title']}", $action['body'], ['platform' => $platform]);
                }
            }
        }

        // --- Inventory: dead / overstocked stock ------------------------------
        $stock = $this->inventory->forDimension($brand->id, 'product', 8);
        if ($stock !== null && ($stock['deadCount'] ?? 0) > 0) {
            // The dead rule is brand-relative (≤10% of median units sold). A
            // zero threshold reads as the plain "zero sales" it is; a higher
            // one names the line so the badge never overstates.
            $threshold = (int) ($stock['deadThresholdUnits'] ?? 0);
            $salesText = $threshold === 0 ? 'zero sales' : "≤{$threshold} sale" . ($threshold === 1 ? '' : 's');
            $findings[] = $this->finding(
                'inventory',
                'warn',
                "{$stock['deadCount']} product" . ($stock['deadCount'] === 1 ? '' : 's') . " with stock and {$salesText}",
                "{$stock['deadUnits']} units are sitting on hand with effectively no sales in the {$stock['windowDays']}-day snapshot window (captured {$stock['capturedOn']}). Full list on the Inventory page.",
                ['rows' => array_slice($stock['rows'], 0, 5)],
            );
        }
        if ($stock !== null && ($stock['flaggedItems'] ?? 0) > ($stock['deadCount'] ?? 0)) {
            $slow = (int) $stock['flaggedItems'] - (int) $stock['deadCount'];
            $findings[] = $this->finding(
                'inventory',
                'info',
                "{$slow} slow mover" . ($slow === 1 ? '' : 's') . ' (>6 months of cover)',
                'Stock levels far ahead of the current sell rate — candidates for promotion or purchase-order review.',
            );
        }

        // ---- Phase 2 (spec §4 Phase 2): revenue, tracking, breakeven, products ----
        $store = (array) config('rules.store');

        $revNative = fn (CarbonImmutable $s, CarbonImmutable $e): float => (float) DailyMetric::query()
            ->where('brand_id', $brand->id)->where('platform', 'shopify')
            ->whereBetween('date', [$s->toDateString(), $e->toDateString()])
            ->selectRaw('COALESCE(SUM(COALESCE(total_sales,0) + COALESCE(refunds_amount,0)), 0) as v')->value('v');
        $refundsNative = fn (CarbonImmutable $s, CarbonImmutable $e): float => (float) DailyMetric::query()
            ->where('brand_id', $brand->id)->where('platform', 'shopify')
            ->whereBetween('date', [$s->toDateString(), $e->toDateString()])
            ->selectRaw('COALESCE(SUM(COALESCE(refunds_amount,0)), 0) as v')->value('v');
        $ordersWin = fn (CarbonImmutable $s, CarbonImmutable $e): int => (int) DailyMetric::query()
            ->where('brand_id', $brand->id)->where('platform', 'shopify')
            ->whereBetween('date', [$s->toDateString(), $e->toDateString()])
            ->selectRaw('COALESCE(SUM(COALESCE(orders,0)), 0) as v')->value('v');

        $curRev   = $revNative($start, $end);
        $priorRev = $revNative($priorStart, $priorEnd);

        // Never judge a partial window — the freshness card above already stands.
        $completeDays   = (int) DailyMetric::query()
            ->where('brand_id', $brand->id)->where('platform', 'shopify')->where('is_complete', true)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])->count();
        $windowComplete = $completeDays >= $len;

        if ($windowComplete && $priorRev > 0.0) {
            $drop = ($priorRev - $curRev) / $priorRev * 100;
            if ($drop >= 35) {
                $findings[] = $this->finding('revenue', 'critical', 'Revenue down ' . round($drop) . '% vs the previous ' . $len . ' days', 'A sharp drop — check for a tracking break, a sold-out hero product, or paused ads.');
            } elseif ($drop >= 20) {
                $findings[] = $this->finding('revenue', 'warn', 'Revenue down ' . round($drop) . '% vs the previous ' . $len . ' days', 'Softening vs the prior window — worth a look at top products and ad delivery.');
            } elseif ($drop <= -20) {
                $findings[] = $this->finding('revenue', 'good', 'Revenue up ' . round(-$drop) . '% vs the previous ' . $len . ' days', 'Growing vs the prior window.');
            }
        }

        // Refund spike vs the trailing-90-day baseline.
        $refundRate = $curRev > 0.0 ? $refundsNative($start, $end) / $curRev * 100 : 0.0;
        $base90     = $end->subDays(89);
        $baseRev    = $revNative($base90, $end);
        $baseRate   = $baseRev > 0.0 ? $refundsNative($base90, $end) / $baseRev * 100 : 0.0;
        if ($baseRate > 0.0 && $refundRate >= (float) ($store['refund_baseline_mult'] ?? 1.5) * $baseRate && $refundRate >= 5.0) {
            $findings[] = $this->finding('revenue', 'warn', 'Refunds are running high', 'Refunds are ' . round($refundRate, 1) . '% of revenue this window vs ' . round($baseRate, 1) . '% normally (trailing 90 days).');
        }

        // Tracking reconciliation + breakeven — need USD ad aggregates.
        $ad = DailyMetric::query()
            ->where('brand_id', $brand->id)->whereIn('platform', ['meta', 'google', 'tiktok'])
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('COALESCE(SUM(COALESCE(spend,0) * COALESCE(fx_rate_to_usd,1)), 0) as spend, COALESCE(SUM(COALESCE(conversion_value,0) * COALESCE(fx_rate_to_usd,1)), 0) as cv')
            ->first();
        $adSpendUsd = (float) ($ad->spend ?? 0);
        $adCvUsd    = (float) ($ad->cv ?? 0);
        $storeRevUsd = (float) DailyMetric::query()
            ->where('brand_id', $brand->id)->where('platform', 'shopify')
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('COALESCE(SUM((COALESCE(total_sales,0) + COALESCE(refunds_amount,0)) * COALESCE(fx_rate_to_usd,1)), 0) as v')->value('v');

        $reconcile = (float) ($store['reconcile_warn_pct'] ?? 10);
        if ($storeRevUsd > 0.0 && $adCvUsd > $storeRevUsd * (1 + $reconcile / 100)) {
            $findings[] = $this->finding('tracking', 'warn', 'Ad platforms are over-counting revenue', "Your ad platforms claim more revenue than the store actually took — attribution is over-counting; don't judge ROAS this window without a pinch of salt.");
        } elseif ($storeRevUsd > 0.0 && $adSpendUsd > 500.0 && $adCvUsd < $storeRevUsd * 0.20) {
            $findings[] = $this->finding('tracking', 'info', 'Purchases may be under-tracked', 'Ad platforms report far less revenue than the store took — the pixel/CAPI may be missing conversions.');
        }

        // Breakeven — only when the brand's gross margin is set (Phase 0).
        $margin = $brand->gross_margin_pct !== null ? (float) $brand->gross_margin_pct : null;
        if ($margin !== null && $margin > 0.0 && $adSpendUsd > 0.0) {
            $breakeven = round(100 / $margin, 2);
            $blended   = round($adCvUsd / $adSpendUsd, 2);
            $mer       = $storeRevUsd > 0.0 ? round($storeRevUsd / $adSpendUsd, 2) : null;
            $merLine   = $mer !== null ? ' Blended MER (store revenue ÷ spend) is ' . $mer . '×.' : '';
            if ($blended < $breakeven) {
                $findings[] = $this->finding('ads', 'critical', 'Paid traffic is below breakeven', 'Blended ROAS ' . $blended . '× is under your breakeven ' . $breakeven . '× — the store loses money on paid traffic at this margin.' . $merLine, ['blended' => $blended, 'breakeven' => $breakeven]);
            } elseif ($blended < $breakeven * 1.1) {
                $findings[] = $this->finding('ads', 'warn', 'Paid traffic is near breakeven', 'Blended ROAS ' . $blended . '× is within 10% of your breakeven ' . $breakeven . '×.' . $merLine, ['blended' => $blended, 'breakeven' => $breakeven]);
            }
        }

        // AOV trend (info-only).
        $curOrders   = $ordersWin($start, $end);
        $priorOrders = $ordersWin($priorStart, $priorEnd);
        if ($windowComplete && $curOrders > 0 && $priorOrders > 0 && $priorRev > 0.0) {
            $curAov   = $curRev / $curOrders;
            $priorAov = $priorRev / $priorOrders;
            if ($priorAov > 0.0) {
                $delta = ($curAov - $priorAov) / $priorAov * 100;
                if (abs($delta) >= 15) {
                    $findings[] = $this->finding('revenue', 'info', 'Average order value ' . ($delta >= 0 ? 'up' : 'down') . ' ' . round(abs($delta)) . '%', 'AOV is ' . number_format($curAov, 2) . ' ' . $brand->base_currency . ' this window vs ' . number_format($priorAov, 2) . ' prior. For context, paid-ads median AOV is ~$74 (Triple Whale 2025).');
                }
            }
        }

        // Product flag rollups — one card per flag type (counts, not per-product),
        // from the SAME engine the products page uses so nothing disagrees.
        $flagMap = $this->productFlags->forBrand($brand->id, $start, $end);
        $byFlag  = [];
        foreach ($flagMap as $title => $pf) {
            foreach ($pf['flags'] as $fl) {
                $byFlag[$fl['key']] ??= ['label' => $fl['label'], 'severity' => $fl['severity'], 'count' => 0, 'titles' => []];
                $byFlag[$fl['key']]['count']++;
                if (count($byFlag[$fl['key']]['titles']) < 3) {
                    $byFlag[$fl['key']]['titles'][] = (string) $title;
                }
            }
        }
        foreach ($byFlag as $key => $agg) {
            $sev = $agg['severity'] === 'critical' ? 'critical' : ($agg['severity'] === 'info' ? 'info' : 'warn');
            $findings[] = $this->finding(
                'products', $sev,
                $agg['count'] . ' product' . ($agg['count'] === 1 ? '' : 's') . ' — ' . $agg['label'],
                'Including ' . implode(', ', $agg['titles']) . '. Full list on the Products page.',
                ['flag' => $key, 'count' => $agg['count']],
            );
        }

        if ($findings === []) {
            $findings[] = $this->finding('data', 'good', 'No flags in this window', 'The rules engines raised nothing for this period.');
        }

        return response()->json([
            'periodStart' => $start->toDateString(),
            'periodEnd'   => $end->toDateString(),
            'findings'    => $findings,
            'generatedAt' => now()->toIso8601String(),
        ]);
    }

    /** @param array<string, mixed>|null $meta */
    private function finding(string $area, string $severity, string $title, string $detail, ?array $meta = null): array
    {
        return [
            'id'       => substr(md5($area . '|' . $title), 0, 12),
            'area'     => $area,      // ads | inventory | data
            'severity' => $severity,  // critical | warn | info | good
            'title'    => $title,
            'detail'   => $detail,
            'meta'     => $meta,
        ];
    }
}
