<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Brand;
use App\Platforms\Meta\MetaClient;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Root-cause the ad-level spend shortfall (Kanwar, 2026-07-22 — Bruna amboise).
 * recon:ads-spend measured level=ad running ~35% (€24k/wk) short of level=campaign
 * for Bruna's Meta, while campaign↔account reconciles. Both undercounting tables
 * (ad_product_daily, ad_creative_daily) are built from the SAME level=ad pull, so
 * the money that never reaches the ad level can't be attributed to a product OR a
 * creative no matter how good the URL parsing is.
 *
 * This pulls BOTH levels for the window and, PER CAMPAIGN, compares campaign-level
 * spend vs Σ(its ad-level spend). Campaigns where the ad level is short are the
 * culprits — typically Advantage+ Shopping / dynamic campaigns, whose spend Meta
 * reports at the campaign level but does not fully break out to stable ad rows.
 * The objective + name are shown so the pattern is obvious, and the aggregate
 * shortfall should reconcile to what recon:ads-spend reported.
 *
 * Read-only. Run on prod:
 *   php artisan meta:diagnose-ad-vs-campaign-spend bruna-jewellery \
 *       --account=act_1690557571077141 --since=2026-07-15 --until=2026-07-21
 */
final class MetaDiagnoseAdVsCampaignSpendCommand extends Command
{
    protected $signature = 'meta:diagnose-ad-vs-campaign-spend '
        . '{brand : slug or id} '
        . '{--account= : restrict to ONE act_… account (default: all selected)} '
        . '{--since= : start Y-m-d} {--until= : end Y-m-d} {--days=7 : used when --since/--until omitted} '
        . '{--min=1 : only list campaigns whose ad-level shortfall exceeds this € amount}';

    protected $description = 'Per-campaign campaign-level vs summed ad-level spend — finds where level=ad loses money (ASC/dynamic). Read-only.';

    public function handle(MetaClient $client): int
    {
        $brand = $this->resolveBrand();
        if ($brand === null) {
            $this->error('Brand not found.');

            return self::FAILURE;
        }
        $conn = $brand->connections->firstWhere('platform', 'meta');
        if (! $conn || $conn->status !== 'active') {
            $this->error("{$brand->name} has no active Meta connection.");

            return self::FAILURE;
        }

        $tz    = $brand->timezone ?: 'UTC';
        $until = $this->option('until') ? CarbonImmutable::parse((string) $this->option('until'), $tz) : CarbonImmutable::now($tz)->subDay();
        $since = $this->option('since') ? CarbonImmutable::parse((string) $this->option('since'), $tz) : $until->subDays(max(1, (int) $this->option('days')) - 1);
        $min   = (float) $this->option('min');

        $accounts = $this->accountIds($conn);
        if ($only = $this->option('account')) {
            $only = str_starts_with((string) $only, 'act_') ? (string) $only : 'act_' . $only;
            $accounts = array_values(array_filter($accounts, static fn (string $a): bool => $a === $only));
        }
        if ($accounts === []) {
            $this->error('No matching Meta accounts on this connection.');

            return self::FAILURE;
        }

        $this->info("Ad-vs-campaign spend · {$brand->name} · {$since->toDateString()}..{$until->toDateString()}");
        $this->line('Accounts: ' . implode(', ', $accounts));
        $this->newLine();

        // campaignId => ['name'=>, 'objective'=>, 'campaignSpend'=>, 'adSpend'=>]
        $campaigns = [];

        foreach ($accounts as $accountId) {
            // Campaign-level spend for the whole window (small; page to be safe).
            try {
                $campRows = $client->paged($accountId . '/insights', [
                    'level'      => 'campaign',
                    'fields'     => 'campaign_id,campaign_name,objective,spend',
                    'time_range' => json_encode(['since' => $since->toDateString(), 'until' => $until->toDateString()]),
                    'limit'      => 500,
                ]);
            } catch (Throwable $e) {
                $this->warn("  {$accountId}: campaign insights failed — {$e->getMessage()}");
                $campRows = [];
            }
            foreach ($campRows as $r) {
                $cid = (string) ($r['campaign_id'] ?? '');
                if ($cid === '') {
                    continue;
                }
                $campaigns[$cid] ??= ['name' => '', 'objective' => '', 'campaignSpend' => 0.0, 'adSpend' => 0.0];
                $campaigns[$cid]['name']          = (string) ($r['campaign_name'] ?? $campaigns[$cid]['name']);
                $campaigns[$cid]['objective']     = (string) ($r['objective'] ?? $campaigns[$cid]['objective']);
                $campaigns[$cid]['campaignSpend'] += (float) ($r['spend'] ?? 0);
            }

            // Ad-level spend, day by day, paged, summed per campaign.
            for ($d = $since; $d->lessThanOrEqualTo($until); $d = $d->addDay()) {
                $day = $d->toDateString();
                try {
                    $adRows = $client->paged($accountId . '/insights', [
                        'level'      => 'ad',
                        'fields'     => 'ad_id,campaign_id,spend',
                        'time_range' => json_encode(['since' => $day, 'until' => $day]),
                        'limit'      => 500,
                    ]);
                } catch (Throwable $e) {
                    $this->warn("  {$accountId} {$day}: ad insights failed — {$e->getMessage()}");
                    usleep(400_000);
                    continue;
                }
                foreach ($adRows as $r) {
                    $cid = (string) ($r['campaign_id'] ?? '');
                    $s   = (float) ($r['spend'] ?? 0);
                    if ($cid === '' || $s <= 0) {
                        continue;
                    }
                    $campaigns[$cid] ??= ['name' => '(no campaign-level row)', 'objective' => '', 'campaignSpend' => 0.0, 'adSpend' => 0.0];
                    $campaigns[$cid]['adSpend'] += $s;
                }
                usleep(120_000);
            }
        }

        if ($campaigns === []) {
            $this->warn('No campaigns with spend in this window.');

            return self::SUCCESS;
        }

        $totalCamp = 0.0;
        $totalAd   = 0.0;
        $short     = [];
        foreach ($campaigns as $cid => $c) {
            $totalCamp += $c['campaignSpend'];
            $totalAd   += $c['adSpend'];
            $diff = $c['campaignSpend'] - $c['adSpend']; // >0 = ad level is short
            if ($diff > $min) {
                $short[$cid] = $c + ['diff' => $diff];
            }
        }
        uasort($short, static fn ($a, $b) => $b['diff'] <=> $a['diff']);

        $this->line('AGGREGATE (should reconcile to recon:ads-spend):');
        $this->line('  campaign-level spend: ' . number_format($totalCamp, 2));
        $this->line('  Σ ad-level spend:     ' . number_format($totalAd, 2));
        $this->line('  ad-level shortfall:   ' . number_format($totalCamp - $totalAd, 2)
            . '  (' . ($totalCamp > 0 ? number_format(($totalCamp - $totalAd) / $totalCamp * 100, 2) : '0') . '%)');
        $this->newLine();

        $this->line('CAMPAIGNS LOSING AD-LEVEL SPEND (campaign − Σ ad, worst first):');
        $this->table(
            ['Campaign', 'Objective', 'Campaign €', 'Ad-level €', 'Shortfall €', '%'],
            array_map(static function (array $c): array {
                $pct = $c['campaignSpend'] > 0 ? number_format($c['diff'] / $c['campaignSpend'] * 100, 1) . '%' : '—';

                return [
                    mb_strimwidth((string) $c['name'], 0, 40, '…'),
                    $c['objective'] ?: '—',
                    number_format($c['campaignSpend'], 2),
                    number_format($c['adSpend'], 2),
                    number_format($c['diff'], 2),
                    $pct,
                ];
            }, array_slice($short, 0, 30, true)),
        );
        $this->line('  Campaigns at ~100% shortfall with no ad rows are the smoking gun — their objective/name');
        $this->line('  typically shows Advantage+ Shopping / dynamic. The fix: attribute those at the level Meta');
        $this->line('  DOES report (campaign or ad-set), or pull their ad rows via the ASC-specific breakdown,');
        $this->line('  rather than letting level=ad silently under-report them.');

        return self::SUCCESS;
    }

    /** @return array<int,string> */
    private function accountIds($conn): array
    {
        $ids = $conn->metadata['ad_account_ids'] ?? null;
        $ids = is_array($ids) && $ids !== []
            ? array_values(array_map(static fn ($i) => (string) $i, $ids))
            : ($conn->external_id ? [(string) $conn->external_id] : []);

        return array_map(static fn ($id) => str_starts_with($id, 'act_') ? $id : 'act_' . $id, $ids);
    }

    private function resolveBrand(): ?Brand
    {
        $arg   = (string) $this->argument('brand');
        $lower = strtolower(trim($arg));

        return is_numeric($arg)
            ? Brand::query()->with('connections')->find((int) $arg)
            : (Brand::query()->with('connections')
                ->whereRaw('LOWER(slug) = ?', [$lower])
                ->orWhereRaw('LOWER(name) = ?', [$lower])
                ->first()
                ?: Brand::query()->with('connections')->where('name', 'like', '%' . $arg . '%')->first());
    }
}
