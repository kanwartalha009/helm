<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\DailyMetric;
use App\Platforms\PlatformRegistry;
use App\Services\Currency\FxService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Backfill account-level TikTok daily_metrics (spend / revenue / reach / native
 * engagement) over a date range. The forward daily sync only fills today onward,
 * so a newly-connected TikTok advertiser is missing history — which trips the ads
 * hub's "not fully synced" freshness gate (isComplete needs a finalized row for
 * every day in the window). Re-runs the exact adapter->fetchDay + upsert path
 * SyncBrandDayJob uses, per (brand, day). Additive + idempotent.
 *
 *   php artisan tiktok:backfill-daily nude-project --since=2026-06-01
 *   php artisan tiktok:backfill-daily --since=2026-06-01   # all active TikTok brands
 */
class TikTokBackfillDailyCommand extends Command
{
    protected $signature = 'tiktok:backfill-daily '
        . '{brand? : slug or id; omit for all active TikTok brands} '
        . '{--since=2026-05-01 : first day to pull (Y-m-d)}';

    protected $description = 'Backfill account-level TikTok daily_metrics (spend/revenue/reach/native) — fills history so the ads-hub freshness gate clears.';

    public function handle(PlatformRegistry $registry, FxService $fx): int
    {
        $since = (string) $this->option('since');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) {
            $this->error('--since must be a Y-m-d date.');

            return self::FAILURE;
        }

        $adapter = $registry->for('tiktok');

        $brands = $this->resolveBrands();
        if ($brands->isEmpty()) {
            $this->warn('No matching brands.');

            return self::SUCCESS;
        }

        $total = 0;
        foreach ($brands as $brand) {
            $conn = $brand->connections->firstWhere('platform', 'tiktok');
            if (! $conn || $conn->status !== 'active') {
                continue; // brand doesn't run TikTok
            }
            $conn->setRelation('brand', $brand);

            $tz    = $brand->timezone ?: 'UTC';
            $from  = CarbonImmutable::parse($since, $tz)->startOfDay();
            $until = CarbonImmutable::now($tz)->subDay()->startOfDay(); // today is partial → live sync owns it
            if ($from->greaterThan($until)) {
                $this->line("· {$brand->name}: --since is in the future for this brand — skipped.");
                continue;
            }

            $days   = 0;
            $failed = false;
            for ($d = $from; $d->lessThanOrEqualTo($until); $d = $d->addDay()) {
                try {
                    $snapshot = $adapter->fetchDay($conn, $d);
                    $fxRate   = $fx->cachedToUsd($snapshot->currency, $d);
                    $row      = $snapshot->toRow($fxRate, fxPending: $fxRate === null);

                    // Model::upsert bypasses the `array` cast on metadata — encode
                    // it here or PDO throws "Array to string conversion".
                    $row['metadata'] = is_array($row['metadata'] ?? null)
                        ? json_encode($row['metadata'])
                        : ($row['metadata'] ?? null);

                    DailyMetric::upsert([$row], ['brand_id', 'platform', 'date'], $snapshot->updateableFields());
                    $days++;
                } catch (Throwable $e) {
                    $this->error("· {$brand->name} {$d->toDateString()}: {$e->getMessage()}");
                    $failed = true;
                    break;
                }
                usleep(200_000);
            }

            if ($failed) {
                $this->warn("· {$brand->name}: stopped early — fix the cause and re-run (idempotent).");
            } else {
                $this->info("· {$brand->name}: {$days} TikTok day(s) synced.");
            }
            $total += $days;
        }

        $this->info("Done. {$total} TikTok daily rows upserted.");

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
            : Brand::query()->with('connections')->whereRaw('LOWER(slug) = ?', [$lower])->orWhereRaw('LOWER(name) = ?', [$lower])->first();

        return collect($brand ? [$brand] : []);
    }
}
