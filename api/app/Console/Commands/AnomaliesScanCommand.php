<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Brand;
use App\Services\Rules\AnomalyScanner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Daily anomaly scan (GO-2.4). Deterministic rules; idempotent per (brand, day, kind,
 * subject) so a re-run refreshes evidence rather than duplicating alerts.
 *
 *   php artisan anomalies:scan                      # all active brands, yesterday
 *   php artisan anomalies:scan meller               # one brand
 *   php artisan anomalies:scan --date=2026-06-10    # re-scan a specific day
 */
class AnomaliesScanCommand extends Command
{
    protected $signature = 'anomalies:scan '
        . '{brand? : slug or id; omit for all active brands} '
        . '{--date= : the day to scan (Y-m-d); defaults to yesterday in each brand tz}';

    protected $description = 'Scan brands for deterministic anomalies (cost spikes, ROAS drops, stockouts, tracking breaks).';

    public function handle(AnomalyScanner $scanner): int
    {
        $dateOpt = $this->option('date');
        if ($dateOpt !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $dateOpt)) {
            $this->error('--date must be a Y-m-d date.');

            return self::FAILURE;
        }

        $brands = $this->resolveBrands();
        if ($brands->isEmpty()) {
            $this->warn('No matching brands.');

            return self::SUCCESS;
        }

        $total = 0;

        foreach ($brands as $brand) {
            $date = $dateOpt !== null
                ? CarbonImmutable::parse((string) $dateOpt, $brand->timezone ?: 'UTC')->startOfDay()
                : null;

            try {
                // One brand's bad data must never stop the scan for the other 87.
                $found = $scanner->scan($brand, $date);
            } catch (Throwable $e) {
                Log::warning('anomalies.scan.failed', ['brand_id' => $brand->id, 'error' => $e->getMessage()]);
                $this->error("· {$brand->name}: {$e->getMessage()}");
                continue;
            }

            $total += count($found);
            if ($found !== []) {
                $kinds = implode(', ', array_unique(array_column($found, 'kind')));
                $this->line("· {$brand->name}: " . count($found) . " anomaly(ies) — {$kinds}");
            }
        }

        $this->info("Done. {$total} anomaly row(s) upserted across {$brands->count()} brand(s).");

        return self::SUCCESS;
    }

    /** @return \Illuminate\Support\Collection<int, Brand> */
    private function resolveBrands()
    {
        $arg = $this->argument('brand');
        if ($arg === null) {
            return Brand::query()->where('status', 'active')->orderBy('name')->get();
        }

        $s = strtolower(trim((string) $arg));
        $brand = is_numeric((string) $arg)
            ? Brand::query()->find((int) $arg)
            : Brand::query()->whereRaw('LOWER(slug) = ?', [$s])->orWhereRaw('LOWER(name) = ?', [$s])->first();

        return collect($brand ? [$brand] : []);
    }
}
