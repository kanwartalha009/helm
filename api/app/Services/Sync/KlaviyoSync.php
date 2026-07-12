<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Models\Brand;
use App\Models\EmailDailyMetric;
use App\Platforms\Klaviyo\RevenueFetcher;
use App\Services\Currency\FxService;
use App\Services\PlatformCredentialService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Klaviyo email revenue → email_daily_metrics (GO-1.1). Mirrors
 * CampaignSync::syncMetaAdProducts: fetch native rows, stamp fx + brand currency at
 * sync time (guardrail 7), upsert on (brand, date, source, source_id).
 *
 * Two entry points by caller context:
 *  - syncBrandDaySafe(): best-effort for the day job — self-guards ALL throwables
 *    (incl. rate limits) so it can never re-queue or fail the brand's main sync,
 *    exactly like the other day-job side-syncs. No key on the brand → clean no-op.
 *  - syncRange(): raw (throws) for the backfill command, which sleeps on a rate limit.
 */
class KlaviyoSync
{
    public function __construct(
        private readonly RevenueFetcher $fetcher,
        private readonly FxService $fx,
        private readonly PlatformCredentialService $credentials,
    ) {}

    public function hasKey(Brand $brand): bool
    {
        return $this->credentials->has('klaviyo', 'private_key', (int) $brand->id);
    }

    /** Best-effort single-day pull for the day job. Never throws. */
    public function syncBrandDaySafe(Brand $brand, CarbonImmutable $date): int
    {
        if (! $this->hasKey($brand)) {
            return 0;
        }

        try {
            return $this->syncRange($brand, $date, $date);
        } catch (Throwable $e) {
            Log::warning('sync.klaviyo.failed', [
                'brand_id' => $brand->id,
                'date'     => $date->toDateString(),
                'error'    => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /** Raw ranged pull — throws (PlatformRateLimitedException included) for the backfill. */
    public function syncRange(Brand $brand, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $rows = $this->fetcher->fetchRange($brand, $from, $to);
        if ($rows === []) {
            return 0;
        }

        $brandId  = (int) $brand->id;
        $fallback = strtoupper((string) ($brand->base_currency ?: 'USD'));

        $records = [];
        foreach ($rows as $r) {
            $rowCcy = strtoupper((string) ($r['currency'] ?? $fallback));
            $day    = CarbonImmutable::parse((string) $r['date']);
            $records[] = [
                'brand_id'         => $brandId,
                'date'             => (string) $r['date'],
                'source'           => (string) $r['source'],
                'source_id'        => (string) $r['source_id'],
                'source_name'      => $r['source_name'] ?? null,
                'conversions'      => (int) $r['conversions'],
                'conversion_value' => (float) $r['conversion_value'],
                'currency'         => $rowCcy,
                'fx_rate_to_usd'   => $this->fx->cachedToUsd($rowCcy, $day),
                'is_complete'      => true,
                'pulled_at'        => now(),
            ];
        }

        foreach (array_chunk($records, 500) as $chunk) {
            EmailDailyMetric::upsert(
                $chunk,
                ['brand_id', 'date', 'source', 'source_id'],
                ['source_name', 'conversions', 'conversion_value', 'currency', 'fx_rate_to_usd', 'is_complete', 'pulled_at'],
            );
        }

        return count($records);
    }
}
