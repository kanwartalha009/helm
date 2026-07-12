<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Brand;
use App\Platforms\Support\PlatformRateLimitedException;
use App\Services\Sync\KlaviyoSync;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Backfill Klaviyo email-attributed revenue into email_daily_metrics (GO-1.1).
 * Per brand (the key is per brand), month-chunked (Klaviyo query windows are ≤1yr;
 * months stay well clear), clamped to the 2023-06-01 data floor (no reporting data
 * exists before it). Delegates to KlaviyoSync::syncRange — the SAME fx-snapshot
 * upsert path the day sync uses, so backfill and forward-sync are idempotent.
 *
 *   php artisan klaviyo:backfill                       # every brand with a Klaviyo key, since the data floor
 *   php artisan klaviyo:backfill meller                # one brand
 *   php artisan klaviyo:backfill meller --since=2026-01-01
 */
class KlaviyoBackfillCommand extends Command
{
    protected $signature = 'klaviyo:backfill '
        . '{brand? : slug or id; omit for all brands that have a Klaviyo key} '
        . '{--since= : first day to pull (Y-m-d); clamped to the 2023-06-01 data floor}';

    protected $description = 'Backfill Klaviyo email-attributed revenue into email_daily_metrics.';

    public function handle(KlaviyoSync $sync): int
    {
        $floor = (string) config('klaviyo.data_floor', '2023-06-01');
        $since = (string) ($this->option('since') ?: $floor);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) {
            $this->error('--since must be a Y-m-d date.');

            return self::FAILURE;
        }
        if ($since < $floor) {
            $this->warn("--since {$since} is before Klaviyo's {$floor} data floor — clamped.");
            $since = $floor;
        }

        $brands = $this->resolveBrands()->filter(fn (Brand $b) => $sync->hasKey($b))->values();
        if ($brands->isEmpty()) {
            $this->warn('No matching brands with a Klaviyo key.');

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

            $rows   = 0;
            $failed = false;
            $cursor = $from;
            while ($cursor->lessThanOrEqualTo($until)) {
                $chunkEnd = $cursor->endOfMonth()->startOfDay();
                if ($chunkEnd->greaterThan($until)) {
                    $chunkEnd = $until;
                }

                $attempts = 0;
                while (true) {
                    try {
                        $rows += $sync->syncRange($brand, $cursor, $chunkEnd);
                        break;
                    } catch (PlatformRateLimitedException $e) {
                        if (++$attempts > 5) {
                            $this->warn("· {$brand->name}: rate-limited 5× at {$cursor->toDateString()} — stopping (re-run is idempotent).");
                            $failed = true;
                            break;
                        }
                        $this->line("· {$brand->name}: rate-limited, sleeping {$e->retryAfterSeconds}s…");
                        sleep($e->retryAfterSeconds);
                    } catch (Throwable $e) {
                        $this->error("· {$brand->name} {$cursor->toDateString()}: {$e->getMessage()}");
                        $failed = true;
                        break;
                    }
                }
                if ($failed) {
                    break;
                }

                $cursor = $chunkEnd->addDay();
                usleep(300_000); // steady 60/m budget cushion
            }

            $totalRows += $rows;
            $failed
                ? $this->warn("· {$brand->name}: stopped early — fix the cause and re-run (idempotent).")
                : $this->info("· {$brand->name}: {$rows} email-revenue rows backfilled.");
        }

        $this->info("Done. {$totalRows} email-revenue rows upserted across {$brands->count()} brand(s).");

        return self::SUCCESS;
    }

    /** @return Collection<int, Brand> */
    private function resolveBrands(): Collection
    {
        $arg = $this->argument('brand');
        if ($arg === null) {
            return Brand::query()->where('status', 'active')->orderBy('name')->get();
        }

        $argStr = (string) $arg;
        $lower  = strtolower(trim($argStr));

        $brand = is_numeric($argStr)
            ? Brand::query()->find((int) $argStr)
            : (Brand::query()
                ->whereRaw('LOWER(slug) = ?', [$lower])
                ->orWhereRaw('LOWER(name) = ?', [$lower])
                ->first()
                ?: Brand::query()
                    ->where('name', 'like', '%' . $argStr . '%')
                    ->orWhere('slug', 'like', '%' . $argStr . '%')
                    ->first());

        return collect($brand ? [$brand] : []);
    }
}
