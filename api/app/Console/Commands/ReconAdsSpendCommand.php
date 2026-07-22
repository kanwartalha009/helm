<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Brand;
use App\Services\Recon\AdsSpendRecon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Nightly ads-spend reconciliation self-check (Kanwar, 2026-07-22 — Bruna amboise
 * incident). Runs AdsSpendRecon for a brand (or every brand with an ad platform)
 * over the last N days and reports the € + % drift of each invariant pair. Amber
 * (>1%) is logged; red (>5%) is logged AND ledgered to audit_logs, so the GO-3
 * track record includes the data-quality catches — Helm flags its own drift
 * before a client can.
 *
 *   php artisan recon:ads-spend bruna-jewellery --days=20
 *   php artisan recon:ads-spend --days=7            # sweep all ad brands
 *   php artisan recon:ads-spend bruna --strict      # nonzero exit on any red
 */
final class ReconAdsSpendCommand extends Command
{
    protected $signature = 'recon:ads-spend '
        . '{brand? : slug or id — omit to sweep every brand with an ad platform} '
        . '{--days=7 : window length, ending yesterday} '
        . '{--since= : explicit start Y-m-d (overrides --days)} '
        . '{--until= : explicit end Y-m-d (defaults to yesterday)} '
        . '{--strict : exit non-zero if any pair is red (>5% drift)}';

    protected $description = 'Reconcile ads roll-up tables (product/campaign/creative/adset) vs their source of truth; flag drift.';

    public function handle(AdsSpendRecon $recon): int
    {
        $to   = $this->option('until') ? CarbonImmutable::parse((string) $this->option('until')) : CarbonImmutable::yesterday();
        $from = $this->option('since')
            ? CarbonImmutable::parse((string) $this->option('since'))
            : $to->subDays(max(1, (int) $this->option('days')) - 1);
        $fromStr = $from->toDateString();
        $toStr   = $to->toDateString();

        $brands = $this->resolveBrands();
        if ($brands === []) {
            $this->warn('No brands with an ad platform to reconcile.');

            return self::SUCCESS;
        }

        $this->info("Ads-spend reconciliation · {$fromStr}..{$toStr} · " . count($brands) . ' brand(s)');
        $this->line('Thresholds: >' . AdsSpendRecon::AMBER_PCT . '% amber · >' . AdsSpendRecon::RED_PCT . '% red (of the source-of-truth side).');
        $this->newLine();

        $anyRed = false;

        foreach ($brands as $brand) {
            $report = $recon->forBrand($brand, $fromStr, $toStr);

            // Skip brands with genuinely nothing in the window (all pairs zero/zero).
            $hasData = false;
            foreach ($report['pairs'] as $p) {
                if ($p['actualTotal'] != 0.0 || $p['referenceTotal'] != 0.0) {
                    $hasData = true;
                    break;
                }
            }
            if (! $hasData) {
                continue;
            }

            $badge = ['ok' => '✓ ok', 'amber' => '▲ AMBER', 'red' => '✗ RED'][$report['worstLevel']] ?? $report['worstLevel'];
            $this->line("── {$brand->name}  [{$badge}]");
            $this->table(
                ['Invariant pair', 'Actual', 'Truth', 'Diff', 'Drift%', ''],
                array_map(static function (array $p): array {
                    $mark = ['ok' => '', 'amber' => '▲', 'red' => '✗'][$p['level']] ?? '';

                    return [
                        $p['label'],
                        number_format($p['actualTotal'], 2),
                        number_format($p['referenceTotal'], 2),
                        number_format($p['diff'], 2),
                        $p['driftPct'] === null ? '—' : number_format($p['driftPct'], 2) . '%',
                        $mark,
                    ];
                }, $report['pairs']),
            );

            // For every non-ok pair, show the worst offending days so the diff is
            // actionable (which day dropped spend), and log/ledger the alert.
            foreach ($report['pairs'] as $p) {
                if ($p['level'] === 'ok') {
                    continue;
                }
                $badDays = array_values(array_filter($p['days'], static fn (array $d): bool => $d['level'] !== 'ok'));
                usort($badDays, static fn (array $a, array $b): int => ($b['driftPct'] ?? 0) <=> ($a['driftPct'] ?? 0));
                $worst = array_slice($badDays, 0, 5);
                $detail = implode('; ', array_map(
                    static fn (array $d): string => "{$d['date']}: " . number_format($d['diff'], 2) . ' (' . number_format((float) $d['driftPct'], 2) . '%)',
                    $worst,
                ));
                $line = "   {$p['label']} — worst days: {$detail}";
                $p['level'] === 'red' ? $this->error($line) : $this->warn($line);

                $context = [
                    'brand'    => $brand->slug,
                    'pair'     => $p['key'],
                    'window'   => "{$fromStr}..{$toStr}",
                    'actual'   => $p['actualTotal'],
                    'truth'    => $p['referenceTotal'],
                    'driftPct' => $p['driftPct'],
                ];
                if ($p['level'] === 'red') {
                    $anyRed = true;
                    Log::error('recon.ads_spend.red', $context);
                    // Ledger the catch — GO-3 track record of data-quality saves.
                    AuditLog::create([
                        'actor_user_id' => null,
                        'action'        => 'recon.ads_spend.red',
                        'target_type'   => 'brand',
                        'target_id'     => $brand->id,
                        'metadata'      => $context,
                        'ip'            => null,
                        'user_agent'    => 'recon:ads-spend',
                    ]);
                } else {
                    Log::warning('recon.ads_spend.amber', $context);
                }
            }
            $this->newLine();
        }

        if ($anyRed && $this->option('strict')) {
            $this->error('At least one pair drifted RED (>' . AdsSpendRecon::RED_PCT . '%).');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @return array<int, Brand> */
    private function resolveBrands(): array
    {
        $arg = $this->argument('brand');
        if ($arg !== null) {
            $lower = strtolower(trim((string) $arg));
            $brand = is_numeric($arg)
                ? Brand::query()->find((int) $arg)
                : Brand::query()->whereRaw('LOWER(slug) = ?', [$lower])->orWhereRaw('LOWER(name) = ?', [$lower])->first();
            if (! $brand) {
                $this->error("Brand not found: {$arg}");

                return [];
            }

            return [$brand];
        }

        // Sweep: every brand that has an ad-platform connection.
        return Brand::query()
            ->whereHas('connections', fn ($q) => $q->whereIn('platform', ['meta', 'google', 'tiktok']))
            ->orderBy('name')
            ->get()
            ->all();
    }
}
