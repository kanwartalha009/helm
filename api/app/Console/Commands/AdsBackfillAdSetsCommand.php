<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Brand;
use App\Services\Sync\AdSetSync;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Backfill ad-set / ad-group / asset-group daily performance into
 * ad_set_daily_metrics for the under-performer drill-down (spec §4 Phase 3c):
 * spend, purchases, conversion value, ROAS + reach/frequency (Meta) or
 * impression-share (Google) per ad-set per day.
 *
 * Ad-set-level daily insights are heavier than campaign-level, so a long window
 * trips Meta's "reduce the amount of data" cap on busy accounts — pull in small
 * day-windows (--chunk-days, default 7; lower to 3/1 on the busiest accounts).
 * Each chunk delegates to AdSetSync (the same fx-snapshot upsert path the daily
 * sync uses), so backfill and forward-sync are idempotent against each other.
 * Additive upsert on (brand, platform, date, ad_set_id) — never touches
 * daily_metrics or ad_campaign_daily_metrics.
 *
 *   php artisan ads:backfill-adsets                          # all active brands, all platforms, since 2025-01-01
 *   php artisan ads:backfill-adsets meller                   # one brand
 *   php artisan ads:backfill-adsets meller --platform=meta   # one brand, Meta only
 *   php artisan ads:backfill-adsets meller --since=2026-04-01 --chunk-days=3
 */
class AdsBackfillAdSetsCommand extends Command
{
    protected $signature = 'ads:backfill-adsets '
        . '{brand? : slug or id; omit for all active brands} '
        . '{--since=2025-01-01 : first day to pull (Y-m-d)} '
        . '{--platform= : meta|google|tiktok; omit for all} '
        . '{--chunk-days=7 : days per API request; lower it (e.g. 3 or 1) if Meta returns "reduce the amount of data"}';

    protected $description = 'Backfill ad-set-level Meta + Google + TikTok performance into ad_set_daily_metrics for the under-performer drill-down.';

    public function handle(AdSetSync $adSetSync): int
    {
        $since = (string) $this->option('since');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) {
            $this->error('--since must be a Y-m-d date.');

            return self::FAILURE;
        }

        $platformOpt = strtolower(trim((string) ($this->option('platform') ?? '')));
        $platforms   = $platformOpt === '' ? ['meta', 'google', 'tiktok'] : [$platformOpt];
        if (array_diff($platforms, ['meta', 'google', 'tiktok']) !== []) {
            $this->error('--platform must be meta, google or tiktok.');

            return self::FAILURE;
        }

        $chunkDays = max(1, (int) $this->option('chunk-days'));

        $brands = $this->resolveBrands();
        if ($brands->isEmpty()) {
            $this->warn('No matching brands.');

            return self::SUCCESS;
        }

        $totalRows = 0;

        foreach ($brands as $brand) {
            $tz    = $brand->timezone ?: 'UTC';
            $from  = CarbonImmutable::parse($since, $tz)->startOfDay();
            $until = CarbonImmutable::now($tz)->subDay()->startOfDay(); // today is partial → live sync owns it
            if ($from->greaterThan($until)) {
                $this->line("· {$brand->name}: --since is in the future for this brand — skipped.");
                continue;
            }

            foreach ($platforms as $platform) {
                $conn = $brand->connections->firstWhere('platform', $platform);
                if (! $conn || $conn->status !== 'active') {
                    continue; // brand doesn't run this platform
                }
                $conn->setRelation('brand', $brand);

                $rows   = 0;
                $failed = false;
                $cursor = $from;
                while ($cursor->lessThanOrEqualTo($until)) {
                    $chunkEnd = $cursor->addDays($chunkDays - 1);
                    if ($chunkEnd->greaterThan($until)) {
                        $chunkEnd = $until;
                    }

                    try {
                        $rows += $adSetSync->syncRange($conn, $cursor, $chunkEnd);
                    } catch (Throwable $e) {
                        // AdSetSync self-guards and returns 0 on a platform error,
                        // so reaching here is an unexpected fault — stop this
                        // platform, keep what landed (idempotent re-run).
                        $this->error("· {$brand->name} [{$platform}] {$cursor->toDateString()}: {$e->getMessage()}");
                        $failed = true;
                        break;
                    }

                    $cursor = $chunkEnd->addDay();
                    usleep(150_000);
                }

                if ($failed) {
                    $this->warn("· {$brand->name} [{$platform}]: stopped early — fix the cause and re-run (idempotent).");
                    $totalRows += $rows;
                    continue;
                }

                if ($rows === 0) {
                    $this->line("· {$brand->name} [{$platform}]: no ad-set rows for {$since}..{$until->toDateString()}.");
                } else {
                    $this->info("· {$brand->name} [{$platform}]: {$rows} ad-set-day rows backfilled.");
                }
                $totalRows += $rows;
            }
        }

        $this->info("Done. {$totalRows} ad-set-day rows upserted across {$brands->count()} brand(s).");

        return self::SUCCESS;
    }

    /** @return Collection<int, Brand> */
    private function resolveBrands(): Collection
    {
        $arg = $this->argument('brand');

        if ($arg === null) {
            return Brand::query()->with('connections')->where('status', 'active')->orderBy('name')->get();
        }

        $argStr = (string) $arg;
        $lower  = strtolower(trim($argStr));

        $brand = is_numeric($argStr)
            ? Brand::query()->with('connections')->find((int) $argStr)
            : (Brand::query()->with('connections')
                ->whereRaw('LOWER(slug) = ?', [$lower])
                ->orWhereRaw('LOWER(name) = ?', [$lower])
                ->first()
                ?: Brand::query()->with('connections')
                    ->where('name', 'like', '%' . $argStr . '%')
                    ->orWhere('slug', 'like', '%' . $argStr . '%')
                    ->first());

        return collect($brand ? [$brand] : []);
    }
}
