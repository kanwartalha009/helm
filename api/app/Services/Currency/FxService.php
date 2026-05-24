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
