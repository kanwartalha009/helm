<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Brand;
use App\Services\Currency\FxProvider;
use App\Services\Currency\FxService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pulls yesterday's FX rates from the configured provider (default
 * frankfurter.app) for every active base currency in use by the active
 * brands and writes one row per (base, target) into currency_rates.
 *
 * Scheduled at 13:30 UTC, after the daily sync starts at 13:00.
 *
 * Currencies fetched:
 *   - every distinct base_currency on an active brand (so we cover
 *     anything the agency actually has on the books)
 *   - plus the seed list in config('sync.fx.currencies') so we keep
 *     a baseline for currencies that don't have a brand yet but will
 *     soon — avoids a sync-time on-demand fetch on day one.
 */
class FetchDailyCurrencyRatesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(FxService $fx, FxProvider $provider): void
    {
        $target = strtoupper((string) config('sync.fx.target', 'USD'));
        $yesterday = CarbonImmutable::yesterday('UTC');

        $brandCurrencies = Brand::query()
            ->where('status', 'active')
            ->pluck('base_currency')
            ->map(fn ($c) => strtoupper((string) $c))
            ->filter()
            ->unique()
            ->all();

        $seeded = array_map('strtoupper', (array) config('sync.fx.currencies', []));

        $bases = array_values(array_unique(array_merge($brandCurrencies, $seeded)));
        $bases = array_values(array_filter($bases, fn ($b) => $b !== $target));

        // Pegged currencies are resolved from a fixed peg by FxService — they
        // have no provider feed, so don't try to fetch them (avoids 404s).
        $pegged = array_map('strtoupper', array_keys((array) config('sync.fx.pegs', [])));
        $bases  = array_values(array_filter($bases, fn ($b) => ! in_array($b, $pegged, true)));

        if ($bases === []) {
            Log::info('fx.fetch.skip', ['reason' => 'no non-target currencies to fetch']);
            return;
        }

        $failures = [];
        foreach ($bases as $base) {
            try {
                [$providerDate, $rate] = $provider->fetchRate($base, $target, $yesterday);
                $fx->storeRange($base, $target, [
                    $providerDate->toDateString() => $rate,
                ]);
            } catch (Throwable $e) {
                $failures[$base] = $e->getMessage();
                report($e);
            }
        }

        Log::info('fx.fetch.done', [
            'target'   => $target,
            'date'     => $yesterday->toDateString(),
            'bases'    => $bases,
            'failures' => $failures,
        ]);

        if ($failures !== [] && count($failures) === count($bases)) {
            // Every base failed — likely a provider outage. Let the queue
            // back off and retry instead of silently moving on.
            throw new \RuntimeException(
                'FX fetch failed for every base currency: ' . json_encode($failures)
            );
        }
    }
}
