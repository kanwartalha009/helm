<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\DailyMetric;
use App\Reports\Support\AdAudit;
use App\Reports\Support\DeadInventory;
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
            $findings[] = $this->finding(
                'inventory',
                'warn',
                "{$stock['deadCount']} product" . ($stock['deadCount'] === 1 ? '' : 's') . ' with stock and zero sales',
                "{$stock['deadUnits']} units are sitting on hand with no sales in the {$stock['windowDays']}-day snapshot window (captured {$stock['capturedOn']}). Full list on the Inventory page.",
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

        if ($findings === []) {
            $findings[] = $this->finding('data', 'good', 'No flags in this window', 'The rules engines (campaign verdicts, dead stock, freshness) raised nothing for this period.');
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
