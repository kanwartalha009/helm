<?php

declare(strict_types=1);

namespace App\Platforms\Meta;

use App\Models\PlatformConnection;
use App\Platforms\Contracts\MetricSnapshot;
use App\Services\Currency\FxService;
use Carbon\CarbonImmutable;

/**
 * Pulls one day of account-level ad insights for a brand's Meta connection
 * and returns a single blended MetricSnapshot.
 *
 * A brand connects ONE Meta row but may select several ad accounts under the
 * agency Business Manager (metadata.ad_account_ids). This fetcher pulls each
 * account's day, then blends them into the one row daily_metrics allows per
 * (brand, meta, date): counts are summed; money is summed natively when every
 * account shares a currency, or converted to USD first when they differ.
 *
 * Each account call uses level=account, time_increment=1, and
 * action_attribution_windows=['7d_click'] — the locked default per
 * docs/05-platforms/meta.md.
 */
final class InsightsFetcher
{
    /** Locked default attribution window for blended ROAS — docs/05 meta. */
    public const ATTRIBUTION_WINDOW = '7d_click';

    /** Purchase action types in priority order — first present wins. */
    private const PURCHASE_ACTION_TYPES = [
        'omni_purchase',
        'purchase',
        'offsite_conversion.fb_pixel_purchase',
    ];

    public function __construct(
        private readonly MetaClient $client,
        private readonly FxService $fx,
    ) {}

    public function fetch(PlatformConnection $conn, CarbonImmutable $date): MetricSnapshot
    {
        $accountIds   = $this->accountIdsFor($conn);
        $day          = $date->toDateString();
        $tz           = $conn->brand?->timezone ?: 'UTC';
        $isComplete   = $date->startOfDay()->lessThan(CarbonImmutable::now($tz)->startOfDay());
        $baseCurrency = strtoupper((string) ($conn->brand?->base_currency ?: 'USD'));
        $brandId      = (int) $conn->brand_id;

        if ($accountIds === []) {
            // No ad accounts selected for this brand yet — store an empty row
            // in the brand currency rather than throwing, so the sync succeeds
            // and the dashboard shows a real zero, not a failure.
            return self::mapInsightRow([], $brandId, $date, $baseCurrency, $isComplete);
        }

        $perAccount = [];
        foreach ($accountIds as $accountId) {
            $body = $this->client->get(self::normalizeAccountId($accountId) . '/insights', [
                'level'                           => 'account',
                'fields'                          => 'spend,impressions,clicks,actions,action_values,account_currency',
                'action_attribution_windows'      => json_encode([self::ATTRIBUTION_WINDOW]),
                'time_range'                      => json_encode(['since' => $day, 'until' => $day]),
                'time_increment'                  => 1,
                // Use the requested window, not whatever the account default is.
                'use_account_attribution_setting' => 'false',
            ]);

            $perAccount[] = self::mapInsightRow($body['data'][0] ?? [], $brandId, $date, $baseCurrency, $isComplete);
        }

        return $this->blend($perAccount, $brandId, $date, $baseCurrency, $isComplete, $accountIds);
    }

    /**
     * Daily account-level insights for a DATE RANGE — one paged call per account
     * (time_increment=1 → one row per day), blended per day across the brand's
     * accounts. Powers the historical spend backfill (`ads:backfill-spend`) that
     * the year-over-year spend/ROAS comparison needs. Same field set + 7d_click
     * window as fetch(); Meta omits days with no delivery, so the result only
     * contains days that actually had activity.
     *
     * @return array<string, MetricSnapshot> keyed by Y-m-d, ascending
     */
    public function fetchRange(PlatformConnection $conn, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $accountIds = $this->accountIdsFor($conn);
        if ($accountIds === []) {
            return [];
        }

        $tz           = $conn->brand?->timezone ?: 'UTC';
        $today        = CarbonImmutable::now($tz)->startOfDay();
        $baseCurrency = strtoupper((string) ($conn->brand?->base_currency ?: 'USD'));
        $brandId      = (int) $conn->brand_id;

        // Y-m-d => array<MetricSnapshot> (one per account) for that day.
        $perDay = [];
        foreach ($accountIds as $accountId) {
            $rows = $this->client->paged(self::normalizeAccountId($accountId) . '/insights', [
                'level'                           => 'account',
                'fields'                          => 'spend,impressions,clicks,actions,action_values,account_currency',
                'action_attribution_windows'      => json_encode([self::ATTRIBUTION_WINDOW]),
                'time_range'                      => json_encode(['since' => $from->toDateString(), 'until' => $to->toDateString()]),
                'time_increment'                  => 1,
                'use_account_attribution_setting' => 'false',
                // Meta defaults to 25 rows/page; a multi-month daily pull then
                // leans on cursor pagination for every ~3 weeks. Ask for the
                // whole window up front so a single dropped cursor can't leave a
                // brand with only the first few weeks of last year.
                'limit'                           => 500,
            ]);

            foreach ($rows as $row) {
                $day = (string) ($row['date_start'] ?? '');
                if ($day === '') {
                    continue;
                }
                $date            = CarbonImmutable::parse($day, $tz)->startOfDay();
                $perDay[$day][]  = self::mapInsightRow($row, $brandId, $date, $baseCurrency, $date->lessThan($today));
            }
        }

        $out = [];
        foreach ($perDay as $day => $snaps) {
            $date      = CarbonImmutable::parse($day, $tz)->startOfDay();
            $out[$day] = $this->blend($snaps, $brandId, $date, $baseCurrency, $date->lessThan($today), $accountIds);
        }
        ksort($out);

        return $out;
    }

    /**
     * The ad accounts to pull for this brand: the selected list when present,
     * otherwise the single external_id (legacy / one-account connection).
     *
     * @return array<int, string>
     */
    private function accountIdsFor(PlatformConnection $conn): array
    {
        $ids = $conn->metadata['ad_account_ids'] ?? null;
        if (is_array($ids) && $ids !== []) {
            return array_values(array_map(static fn ($i) => (string) $i, $ids));
        }

        return $conn->external_id ? [(string) $conn->external_id] : [];
    }

    /**
     * Blend per-account snapshots into one brand-level Meta snapshot. Counts
     * always sum; money sums natively when all accounts share one currency,
     * else each currency is converted to USD before summing and the row is
     * stamped USD (its fx_rate_to_usd is then 1.0 at write time).
     *
     * @param array<int, MetricSnapshot> $snapshots
     * @param array<int, string> $accountIds
     */
    private function blend(
        array $snapshots,
        int $brandId,
        CarbonImmutable $date,
        string $baseCurrency,
        bool $isComplete,
        array $accountIds,
    ): MetricSnapshot {
        $impressions = 0;
        $clicks      = 0;
        $conversions = 0;
        $spendByCcy  = [];
        $valueByCcy  = [];

        foreach ($snapshots as $s) {
            $impressions += (int) ($s->impressions ?? 0);
            $clicks      += (int) ($s->clicks ?? 0);
            $conversions += (int) ($s->conversions ?? 0);

            $ccy = strtoupper($s->currency);
            $spendByCcy[$ccy] = ($spendByCcy[$ccy] ?? 0.0) + (float) ($s->spend ?? 0.0);
            $valueByCcy[$ccy] = ($valueByCcy[$ccy] ?? 0.0) + (float) ($s->conversionValue ?? 0.0);
        }

        $currencies = array_values(array_unique(array_merge(array_keys($spendByCcy), array_keys($valueByCcy))));

        if ($currencies === []) {
            $currency = $baseCurrency;
            $spend    = 0.0;
            $value    = 0.0;
        } elseif (count($currencies) === 1) {
            $currency = $currencies[0];
            $spend    = $spendByCcy[$currency] ?? 0.0;
            $value    = $valueByCcy[$currency] ?? 0.0;
        } else {
            // Mixed currencies across the brand's accounts — convert each to USD
            // and store the blended row in USD. toUsd is DB-first; it only hits
            // the provider for a genuinely missing rate, which is rare and only
            // on the (uncommon) multi-currency brand.
            $currency = 'USD';
            $spend    = 0.0;
            $value    = 0.0;
            foreach ($currencies as $ccy) {
                $rate   = $this->fx->toUsd($ccy, $date);
                $spend += ($spendByCcy[$ccy] ?? 0.0) * $rate;
                $value += ($valueByCcy[$ccy] ?? 0.0) * $rate;
            }
        }

        return new MetricSnapshot(
            brandId: $brandId,
            platform: 'meta',
            date: $date,
            currency: $currency,
            spend: round($spend, 2),
            impressions: $impressions,
            clicks: $clicks,
            conversions: $conversions,
            conversionValue: round($value, 2),
            metadata: [
                'attribution_window' => self::ATTRIBUTION_WINDOW,
                'ad_account_ids'     => array_values(array_map([self::class, 'normalizeAccountId'], $accountIds)),
            ],
            isComplete: $isComplete,
        );
    }

    /**
     * Pure mapping from a single Meta insights row to a MetricSnapshot. Static
     * and side-effect-free so it can be unit-tested against a captured payload
     * without touching the network (tests/Unit/MetaInsightsMapperTest.php).
     *
     * @param array<string, mixed> $row
     */
    public static function mapInsightRow(
        array $row,
        int $brandId,
        CarbonImmutable $date,
        string $fallbackCurrency,
        bool $isComplete,
    ): MetricSnapshot {
        $currency = strtoupper((string) (($row['account_currency'] ?? $fallbackCurrency) ?: 'USD'));

        $spend       = isset($row['spend'])       ? (float) $row['spend']     : 0.0;
        $impressions = isset($row['impressions']) ? (int) $row['impressions'] : 0;
        $clicks      = isset($row['clicks'])      ? (int) $row['clicks']      : 0;

        $conversions     = (int) round(self::attributedTotal($row['actions'] ?? [], self::PURCHASE_ACTION_TYPES));
        $conversionValue = self::attributedTotal($row['action_values'] ?? [], self::PURCHASE_ACTION_TYPES);

        return new MetricSnapshot(
            brandId: $brandId,
            platform: 'meta',
            date: $date,
            currency: $currency,
            spend: $spend,
            impressions: $impressions,
            clicks: $clicks,
            conversions: $conversions,
            conversionValue: $conversionValue,
            metadata: ['attribution_window' => self::ATTRIBUTION_WINDOW],
            isComplete: $isComplete,
        );
    }

    /**
     * Attributed value for the first purchase action type present. With
     * action_attribution_windows set, Meta returns each action as
     * { action_type, value, '7d_click' => ... } — prefer the window key,
     * fall back to the default `value`.
     *
     * @param array<int, array<string, mixed>> $actions
     * @param array<int, string> $types
     */
    private static function attributedTotal(array $actions, array $types): float
    {
        foreach ($types as $type) {
            foreach ($actions as $action) {
                if (! is_array($action) || ($action['action_type'] ?? null) !== $type) {
                    continue;
                }
                $val = $action[self::ATTRIBUTION_WINDOW] ?? $action['value'] ?? 0;
                return is_numeric($val) ? (float) $val : 0.0;
            }
        }

        return 0.0;
    }

    /** Meta ad account IDs are 'act_<digits>'. Accept either form, normalize to 'act_'. */
    public static function normalizeAccountId(string $id): string
    {
        $id = trim($id);

        return str_starts_with($id, 'act_') ? $id : 'act_' . $id;
    }
}
