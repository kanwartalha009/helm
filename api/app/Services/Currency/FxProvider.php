<?php

declare(strict_types=1);

namespace App\Services\Currency;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as Http;
use RuntimeException;

/**
 * Pulls FX rates from a free, no-key provider. Default is frankfurter.app
 * (ECB reference rates, daily, no API key, no rate-limit hassle).
 *
 * We deliberately do not use exchangerate.host any more — it moved to a
 * paid model in 2024 and now requires an `access_key`. Frankfurter has
 * the same shape, the same daily cadence, and the same ECB source.
 *
 * Endpoint shape:
 *   https://api.frankfurter.app/2026-03-23?from=EUR&to=USD
 *   → { "amount": 1.0, "base": "EUR", "date": "2026-03-21", "rates": { "USD": 1.0915 } }
 *
 * The `date` in the response is the most recent ECB working day on or
 * before the requested date — exactly the "fall back to the most recent
 * date <= $date" semantic the FxService expects.
 *
 * Set FX_PROVIDER_URL in .env if you want to swap providers; expects
 * the same /{date}?from=&to= contract.
 */
final class FxProvider
{
    public function __construct(
        private readonly Http $http,
        private readonly string $baseUrl,
    ) {}

    /**
     * Fetch a single rate (1 unit of $base in $target) on or before $date.
     * Returns [date, rate] — the date is what the provider actually returned
     * (may be earlier than $date if requested day was a weekend / holiday).
     *
     * @return array{0: CarbonImmutable, 1: float}
     */
    public function fetchRate(string $base, string $target, CarbonImmutable $date): array
    {
        $base   = strtoupper($base);
        $target = strtoupper($target);

        if ($base === $target) {
            return [$date, 1.0];
        }

        $url = rtrim($this->baseUrl, '/') . '/' . $date->toDateString();

        $resp = $this->http
            ->timeout(15)
            ->retry(2, 500, throw: false)
            ->acceptJson()
            ->get($url, ['from' => $base, 'to' => $target]);

        if (! $resp->successful()) {
            throw new RuntimeException(
                "FX provider error ({$resp->status()}) fetching {$base}→{$target} for {$date->toDateString()}: " . $resp->body()
            );
        }

        $payload = $resp->json();
        $rate    = $payload['rates'][$target] ?? null;
        $respDate = $payload['date'] ?? null;

        if (! is_numeric($rate) || ! is_string($respDate)) {
            throw new RuntimeException(
                "FX provider returned unexpected payload for {$base}→{$target} on {$date->toDateString()}: " . json_encode($payload)
            );
        }

        return [CarbonImmutable::parse($respDate), (float) $rate];
    }

    /**
     * Fetch a contiguous range. Used by the backfill command and the
     * nightly job. Frankfurter exposes a range endpoint at /{from}..{to}
     * so this is one HTTP call regardless of window size.
     *
     * @return array<string, float>  Map of YYYY-MM-DD → rate.
     */
    public function fetchRange(string $base, string $target, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $base   = strtoupper($base);
        $target = strtoupper($target);

        if ($base === $target) {
            return [];
        }

        $url = rtrim($this->baseUrl, '/') . '/' . $from->toDateString() . '..' . $to->toDateString();

        $resp = $this->http
            ->timeout(30)
            ->retry(2, 500, throw: false)
            ->acceptJson()
            ->get($url, ['from' => $base, 'to' => $target]);

        if (! $resp->successful()) {
            throw new RuntimeException(
                "FX provider error ({$resp->status()}) fetching {$base}→{$target} range {$from->toDateString()}..{$to->toDateString()}: " . $resp->body()
            );
        }

        $payload = $resp->json();
        $rates   = $payload['rates'] ?? [];
        $out     = [];
        foreach ($rates as $day => $row) {
            if (isset($row[$target]) && is_numeric($row[$target])) {
                $out[$day] = (float) $row[$target];
            }
        }
        return $out;
    }
}
