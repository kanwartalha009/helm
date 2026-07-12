<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Brand;
use App\Services\Ledger\LedgerRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The silent ledger writer (GO-2.5). Runs nightly, logs what the engines assert.
 *
 * Nothing renders these rows yet — the track record can only be computed from history,
 * so the history must start accruing before the UI that needs it exists (GO-3.3). Every
 * night this runs, the moat gets one day deeper.
 *
 *   php artisan ledger:record                    # all active brands
 *   php artisan ledger:record meller             # one brand
 *   php artisan ledger:record --date=2026-06-10  # as of a specific day
 */
class LedgerRecordCommand extends Command
{
    protected $signature = 'ledger:record '
        . '{brand? : slug or id; omit for all active brands} '
        . '{--date= : as-of day (Y-m-d); defaults to yesterday in each brand tz}';

    protected $description = 'Record what the rules engines currently recommend into the ledger (insert-only, idempotent).';

    public function handle(LedgerRecorder $recorder): int
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
            $asOf = $dateOpt !== null
                ? CarbonImmutable::parse((string) $dateOpt, $brand->timezone ?: 'UTC')->startOfDay()
                : null;

            try {
                // One brand's bad data must never stop the ledger for the other 87.
                $written = $recorder->recordForBrand($brand, $asOf);
            } catch (Throwable $e) {
                Log::warning('ledger.record.failed', ['brand_id' => $brand->id, 'error' => $e->getMessage()]);
                $this->error("· {$brand->name}: {$e->getMessage()}");
                continue;
            }

            $total += $written;
            if ($written > 0) {
                $this->line("· {$brand->name}: {$written} new recommendation(s) recorded.");
            }
        }

        // Re-running is a no-op for advice already open — that is the design, not a bug.
        $this->info("Done. {$total} new recommendation(s) across {$brands->count()} brand(s).");

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
