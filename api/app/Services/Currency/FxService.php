<?php

declare(strict_types=1);

namespace App\Services\Currency;

use App\Models\CurrencyRate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Reads FX rates from the currency_rates table. Rates are populated by the
 * nightly FetchDailyCurrencyRatesJob (yesterday for every active base
 * currency) and by on-demand fetch-and-cache at sync time for any rate
 * that's still missing.
 *
 * The dashboard's USD column reads `revenue × fx_rate_to_usd` directly in
 * SQL — this service is for code paths that need a single rate (sync time,
 * ad-hoc conversions).
 */
final class FxService
{
    public function __construct(private readonly FxProvider $provider) {}

    /**
     * Returns the rate to multiply $base by to get $target on $date.
     * Falls back to the most recent date <= $date if the exact day is
     * missing. If no row exists at all, fetches from the FxProvider on
     * demand and caches the result so the next call is a DB hit.
     */
    public function rate(string $base, string $target, CarbonImmutable $date): float
    {
        $base   = strtoupper($base);
        $target = strtoupper($target);

        if ($base === $target) {
            return 1.0;
        }

        if ($target === 'USD') {
            $peg = $this->pegRate($base);
            if ($peg !== null) {
                return $peg;
            }
        }

        $row = $this->lookup($base, $target, $date);
        if ($row !== null) {
            return (float) $row->rate;
        }

        // Cache miss — go to the provider and store the result so we
        // don't hammer them on every sync row.
        try {
            [$providerDate, $rate] = $this->provider->fetchRate($base, $target, $date);
        } catch (Throwable $e) {
            // Surface a clear error to the caller — sync_logs will record
            // it on the row's `error_message` column.
            throw new RuntimeException(
                "FX rate unavailable for {$base} → {$target} on {$date->toDateString()}: " . $e->getMessage(),
                previous: $e,
            );
        }

        CurrencyRate::query()->updateOrCreate(
            [
                'date'             => $providerDate->toDateString(),
                'base_currency'    => $base,
                'target_currency'  => $target,
            ],
            [
                'rate'       => $rate,
                'created_at' => now(),
            ],
        );

        return $rate;
    }

    /** Convenience: rate to USD. */
    public function toUsd(string $base, CarbonImmutable $date): float
    {
        return $this->rate($base, 'USD', $date);
    }

    /**
     * DB-only USD rate for the sync hot path. Returns 1.0 when the currency
     * is already the target (USD), the most recent cached rate on/before
     * $date, or null when no rate is cached yet. NEVER calls the provider —
     * sync must land native facts fast and not block on the network. A null
     * return tells the caller to flag the row fx_pending so BackfillFxRatesJob
     * (off the hot path) fills it once a rate exists.
     */
    public function cachedToUsd(string $currency, CarbonImmutable $date): ?float
    {
        $base   = strtoupper($currency);
        $target = strtoupper((string) config('sync.fx.target', 'USD'));

        if ($base === $target) {
            return 1.0;
        }

        // Hard-pegged currencies (AED, SAR, ...) the provider can't serve are
        // resolved from the fixed peg — no DB row or network call needed.
        $peg = $this->pegRate($base);
        if ($peg !== null) {
            return $peg;
        }

        $row = $this->lookup($base, $target, $date);

        return $row !== null ? (float) $row->rate : null;
    }

    /**
     * USD rate for a hard-pegged currency the ECB / frankfurter feed doesn't
     * cover (e.g. AED, SAR). The peg in config('sync.fx.pegs') is the official
     * currency-per-USD rate, so the USD rate is 1 / peg. Null when not pegged.
     */
    public function pegRate(string $base): ?float
    {
        $pegs = array_change_key_case((array) config('sync.fx.pegs', []), CASE_UPPER);
        $peg  = $pegs[strtoupper($base)] ?? null;

        return is_numeric($peg) && (float) $peg > 0 ? round(1.0 / (float) $peg, 6) : null;
    }

    /**
     * Pure DB lookup — no provider fallback. Used by the nightly job to
     * avoid re-fetching what we already have.
     */
    public function lookup(string $base, string $target, CarbonImmutable $date): ?CurrencyRate
    {
        return CurrencyRate::query()
            ->where('base_currency', strtoupper($base))
            ->where('target_currency', strtoupper($target))
            ->where('date', '<=', $date->toDateString())
            ->orderByDesc('date')
            ->first();
    }

    /**
     * Write a batch of rates returned by FxProvider::fetchRange().
     * Idempotent — the (date, base, target) unique index handles dedupe.
     *
     * @param array<string, float> $rates  Map of YYYY-MM-DD → rate.
     */
    public function storeRange(string $base, string $target, array $rates): int
    {
        $base   = strtoupper($base);
        $target = strtoupper($target);
        $now    = now();

        $payload = [];
        foreach ($rates as $day => $rate) {
            $payload[] = [
                'date'             => $day,
                'base_currency'    => $base,
                'target_currency'  => $target,
                'rate'             => $rate,
                'created_at'       => $now,
            ];
        }
        if ($payload === []) {
            return 0;
        }

        // upsert keyed on the unique index so a repeat run refreshes
        // rather than throwing on the duplicate constraint.
        CurrencyRate::query()->upsert(
            $payload,
            ['date', 'base_currency', 'target_currency'],
            ['rate', 'created_at'],
        );
        Log::info('fx.storeRange', [
            'base'   => $base,
            'target' => $target,
            'days'   => count($payload),
        ]);
        return count($payload);
    }
}
