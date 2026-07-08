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

    /** Landing-page-view action types in priority order — first present wins. */
    private const LANDING_PAGE_VIEW_TYPES = [
        'omni_landing_page_view',
        'landing_page_view',
    ];

    /**
     * Video-watch insight fields (each a [{action_type,value}] array) → the flat
     * metadata['meta'] key it stores as. Feeds the "Meta engagement" panel. Meta
     * has no "6-sec" metric — ThruPlay is its deep-watch equivalent. The 2-second
     * continuous field (video_continuous_2_sec_watched_actions) does NOT populate
     * at account level for this account (returned 0 against 3.1M plays), so the
     * hook metric comes from the `actions` array instead: video_view = Meta's
     * standard 3-second video plays (same numerator Helm uses for Thumbstop).
     */
    private const VIDEO_ENGAGEMENT_FIELDS = [
        'video_play_actions'         => 'video_play_actions',
        'video_thruplay_watched_actions' => 'thruplays',
        'video_p25_watched_actions'  => 'video_p25',
        'video_p50_watched_actions'  => 'video_p50',
        'video_p75_watched_actions'  => 'video_p75',
        'video_p100_watched_actions' => 'video_p100',
    ];

    /**
     * Social engagement, read from the `actions` array. Meta's closest concept to
     * a TikTok "follow" is a Page like; it reports no ad "profile visits", so that
     * TikTok column is intentionally absent from the Meta panel.
     */
    private const SOCIAL_ACTION_MAP = [
        'likes'      => 'post_reaction',
        'comments'   => 'comment',
        'shares'     => 'post',
        'page_likes' => 'like',
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
                'fields'                          => 'spend,impressions,clicks,reach,inline_link_clicks,actions,action_values,account_currency',
                'action_attribution_windows'      => json_encode([self::ATTRIBUTION_WINDOW]),
                'time_range'                      => json_encode(['since' => $day, 'until' => $day]),
                'time_increment'                  => 1,
                // Use the requested window, not whatever the account default is.
                'use_account_attribution_setting' => 'false',
            ]);

            $perAccount[] = self::mapInsightRow($body['data'][0] ?? [], $brandId, $date, $baseCurrency, $isComplete);
        }

        // Video + social engagement rides in metadata['meta'] via a SEPARATE
        // best-effort call, so it can never break the spend/revenue row above.
        $engagement = $this->fetchEngagement($accountIds, $date, $date)[$day] ?? [];

        return $this->blend($perAccount, $brandId, $date, $baseCurrency, $isComplete, $accountIds, $engagement);
    }

    /**
     * Isolated engagement pull (video completion + social) → day => flat
     * name=>value map for daily_metrics.metadata['meta'], mirroring TikTok's
     * fetchNative(). It is its OWN insights call with its OWN try/catch: a bad
     * field or a transient error returns [] and NEVER touches spend/revenue.
     * Video-watch fields are [{action_type,value}] arrays; social comes from the
     * `actions` array. Engagement metrics aren't attribution-windowed, so no
     * action_attribution_windows here (unlike the conversion call).
     *
     * @param array<int, string> $accountIds
     * @return array<string, array<string, float>> keyed by Y-m-d
     */
    private function fetchEngagement(array $accountIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $fields = implode(',', array_keys(self::VIDEO_ENGAGEMENT_FIELDS)) . ',actions';

        $byDay = [];
        foreach ($accountIds as $accountId) {
            try {
                $rows = $this->client->paged(self::normalizeAccountId($accountId) . '/insights', [
                    'level'          => 'account',
                    'fields'         => $fields,
                    'time_range'     => json_encode(['since' => $from->toDateString(), 'until' => $to->toDateString()]),
                    'time_increment' => 1,
                    'limit'          => 500,
                ]);
            } catch (\Throwable $e) {
                continue; // best-effort — skip this account's engagement, keep the day
            }

            foreach ($rows as $row) {
                $day = (string) ($row['date_start'] ?? '');
                if ($day === '') {
                    continue;
                }
                $acc = $byDay[$day] ?? [];
                foreach (self::VIDEO_ENGAGEMENT_FIELDS as $field => $key) {
                    $acc[$key] = ($acc[$key] ?? 0.0) + self::sumActionField($row[$field] ?? null);
                }
                foreach (self::SOCIAL_ACTION_MAP as $key => $type) {
                    $acc[$key] = ($acc[$key] ?? 0.0) + self::plainActionTotal($row['actions'] ?? [], [$type]);
                }
                // 3-sec video plays — Meta's hook metric, from the actions array
                // (video_continuous_2_sec_watched_actions returns empty here).
                $acc['video_3s'] = ($acc['video_3s'] ?? 0.0) + self::plainActionTotal($row['actions'] ?? [], ['video_view']);
                $byDay[$day] = $acc;
            }
        }

        return $byDay;
    }

    /**
     * Sum the numeric `value` across a Meta action-array field such as
     * video_p100_watched_actions = [{action_type, value}].
     *
     * @param mixed $field
     */
    private static function sumActionField($field): float
    {
        if (! is_array($field)) {
            return 0.0;
        }
        $sum = 0.0;
        foreach ($field as $entry) {
            if (is_array($entry) && is_numeric($entry['value'] ?? null)) {
                $sum += (float) $entry['value'];
            }
        }

        return $sum;
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
                'fields'                          => 'spend,impressions,clicks,reach,inline_link_clicks,actions,action_values,account_currency',
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

        // One ranged engagement pull for the whole window (best-effort) — sliced
        // per day into the blend so the backfill fills metadata['meta'] history.
        $engagementByDay = $this->fetchEngagement($accountIds, $from, $to);

        $out = [];
        foreach ($perDay as $day => $snaps) {
            $date      = CarbonImmutable::parse($day, $tz)->startOfDay();
            $out[$day] = $this->blend($snaps, $brandId, $date, $baseCurrency, $date->lessThan($today), $accountIds, $engagementByDay[$day] ?? []);
        }
        ksort($out);

        return $out;
    }

    /**
     * Daily CAMPAIGN-level insights for a date range — one paged call per
     * account with level=campaign, time_increment=1. Powers the ads audit
     * (slice 2.2): spend / purchases / ROAS per campaign per day, same 7d_click
     * window as the account-level fetch. Returns flat native-currency rows; the
     * backfill command stamps the fx rate and upserts.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchCampaignRange(PlatformConnection $conn, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $accountIds = $this->accountIdsFor($conn);
        if ($accountIds === []) {
            return [];
        }

        $fallbackCcy = strtoupper((string) ($conn->brand?->base_currency ?: 'USD'));

        $out = [];
        foreach ($accountIds as $accountId) {
            $rows = $this->client->paged(self::normalizeAccountId($accountId) . '/insights', [
                'level'                           => 'campaign',
                'fields'                          => 'campaign_id,campaign_name,spend,impressions,clicks,actions,action_values,account_currency',
                'action_attribution_windows'      => json_encode([self::ATTRIBUTION_WINDOW]),
                'time_range'                       => json_encode(['since' => $from->toDateString(), 'until' => $to->toDateString()]),
                'time_increment'                  => 1,
                'use_account_attribution_setting' => 'false',
                'limit'                           => 500,
            ]);

            foreach ($rows as $row) {
                $day = (string) ($row['date_start'] ?? '');
                $cid = (string) ($row['campaign_id'] ?? '');
                if ($day === '' || $cid === '') {
                    continue;
                }
                $out[] = [
                    'date'             => $day,
                    'campaign_id'      => $cid,
                    'campaign_name'    => (string) ($row['campaign_name'] ?? ''),
                    'spend'            => isset($row['spend']) ? round((float) $row['spend'], 2) : 0.0,
                    'impressions'      => (int) ($row['impressions'] ?? 0),
                    'clicks'           => (int) ($row['clicks'] ?? 0),
                    'conversions'      => (int) round(self::attributedTotal($row['actions'] ?? [], self::PURCHASE_ACTION_TYPES)),
                    'conversion_value' => round(self::attributedTotal($row['action_values'] ?? [], self::PURCHASE_ACTION_TYPES), 2),
                    'currency'         => strtoupper((string) ($row['account_currency'] ?? $fallbackCcy)),
                ];
            }
        }

        return $out;
    }

    /**
     * Daily insights split by a BREAKDOWN dimension over a date range — powers
     * the dashboard's Audience view. $breakdowns is the Meta breakdown list for
     * the chosen axis, e.g. ['age','gender'], ['publisher_platform','platform_position'],
     * or the ASC audience-segment key. The segment is the joined breakdown values
     * on each row. Returns flat native-currency rows; the backfill stamps fx.
     *
     * @param array<int, string> $breakdowns
     * @return array<int, array<string, mixed>>
     */
    public function fetchBreakdownRange(PlatformConnection $conn, array $breakdowns, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $accountIds = $this->accountIdsFor($conn);
        if ($accountIds === [] || $breakdowns === []) {
            return [];
        }

        $fallbackCcy = strtoupper((string) ($conn->brand?->base_currency ?: 'USD'));

        $out = [];
        foreach ($accountIds as $accountId) {
            $out = array_merge(
                $out,
                $this->pullBreakdownAdaptive(self::normalizeAccountId($accountId), $breakdowns, $from, $to, $fallbackCcy),
            );
        }

        return $out;
    }

    /**
     * Pull one account's breakdown rows for [from, to], halving the window and
     * retrying on a transport failure. High-cardinality axes (country has 200+
     * values, placement dozens) over a long window make Meta's query slow enough
     * that its gateway closes the connection mid-response — cURL 18
     * (CURLE_PARTIAL_FILE: "transfer closed with outstanding read data"). Each
     * split shrinks the query until it returns; a single day that still fails is
     * a real error and rethrows rather than silently dropping data.
     *
     * @param array<int, string> $breakdowns
     * @return array<int, array<string, mixed>>
     */
    private function pullBreakdownAdaptive(string $accountId, array $breakdowns, CarbonImmutable $from, CarbonImmutable $to, string $fallbackCcy): array
    {
        try {
            return $this->pullBreakdownWindow($accountId, $breakdowns, $from, $to, $fallbackCcy);
        } catch (\Throwable $e) {
            if ($from->greaterThanOrEqualTo($to)) {
                throw $e; // already a single day — can't narrow further
            }

            $days = (int) $from->diffInDays($to);
            $mid  = $from->addDays(intdiv(max($days, 1), 2));

            return array_merge(
                $this->pullBreakdownAdaptive($accountId, $breakdowns, $from, $mid, $fallbackCcy),
                $this->pullBreakdownAdaptive($accountId, $breakdowns, $mid->addDay(), $to, $fallbackCcy),
            );
        }
    }

    /**
     * One paged insights call for a single account + window, mapped to flat
     * native-currency breakdown rows. The page limit is deliberately smaller than
     * the account/campaign pulls: breakdowns multiply the row count, and an
     * oversized page is exactly what makes Meta truncate the response.
     *
     * @param array<int, string> $breakdowns
     * @return array<int, array<string, mixed>>
     */
    private function pullBreakdownWindow(string $accountId, array $breakdowns, CarbonImmutable $from, CarbonImmutable $to, string $fallbackCcy): array
    {
        $rows = $this->client->paged($accountId . '/insights', [
            'level'                           => 'account',
            'fields'                          => 'spend,impressions,clicks,actions,action_values,account_currency',
            'breakdowns'                      => implode(',', $breakdowns),
            'action_attribution_windows'      => json_encode([self::ATTRIBUTION_WINDOW]),
            'time_range'                      => json_encode(['since' => $from->toDateString(), 'until' => $to->toDateString()]),
            'time_increment'                  => 1,
            'use_account_attribution_setting' => 'false',
            'limit'                           => 200,
        ]);

        $out = [];
        foreach ($rows as $row) {
            $day = (string) ($row['date_start'] ?? '');
            if ($day === '') {
                continue;
            }
            // The segment is the combination of the requested breakdown fields
            // present on the row (e.g. age + gender → "25-34 · female").
            $parts = [];
            foreach ($breakdowns as $b) {
                $v = $row[$b] ?? null;
                if ($v !== null && $v !== '') {
                    $parts[] = (string) $v;
                }
            }
            $segment = $parts === [] ? 'unknown' : implode(' · ', $parts);

            $out[] = [
                'date'             => $day,
                'segment_key'      => $segment,
                'segment_label'    => $segment,
                'spend'            => isset($row['spend']) ? round((float) $row['spend'], 2) : 0.0,
                'impressions'      => (int) ($row['impressions'] ?? 0),
                'clicks'           => (int) ($row['clicks'] ?? 0),
                'conversions'      => (int) round(self::attributedTotal($row['actions'] ?? [], self::PURCHASE_ACTION_TYPES)),
                'conversion_value' => round(self::attributedTotal($row['action_values'] ?? [], self::PURCHASE_ACTION_TYPES), 2),
                'currency'         => strtoupper((string) ($row['account_currency'] ?? $fallbackCcy)),
            ];
        }

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
        array $engagement = [],
    ): MetricSnapshot {
        $impressions = 0;
        $clicks      = 0;
        $conversions = 0;
        $reach            = 0;
        $linkClicks       = 0;
        $landingPageViews = 0;
        $spendByCcy  = [];
        $valueByCcy  = [];

        foreach ($snapshots as $s) {
            $impressions += (int) ($s->impressions ?? 0);
            $clicks      += (int) ($s->clicks ?? 0);
            $conversions += (int) ($s->conversions ?? 0);
            $reach            += (int) ($s->reach ?? 0);
            $linkClicks       += (int) ($s->linkClicks ?? 0);
            $landingPageViews += (int) ($s->landingPageViews ?? 0);

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

        $metadata = [
            'attribution_window' => self::ATTRIBUTION_WINDOW,
            'ad_account_ids'     => array_values(array_map([self::class, 'normalizeAccountId'], $accountIds)),
        ];
        if ($engagement !== []) {
            $metadata['meta'] = $engagement; // video completion + social — see fetchEngagement
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
            metadata: $metadata,
            isComplete: $isComplete,
            reach: $reach,
            linkClicks: $linkClicks,
            landingPageViews: $landingPageViews,
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

        // Funnel/efficiency fields — 0 (not null) when the account delivered but
        // had none, so a re-synced day is distinguishable from a pre-migration
        // null row. reach + inline_link_clicks are top-level insights fields;
        // landing page views come from the actions array.
        $reach            = (int) ($row['reach'] ?? 0);
        $linkClicks       = (int) ($row['inline_link_clicks'] ?? 0);
        $landingPageViews = (int) round(self::plainActionTotal($row['actions'] ?? [], self::LANDING_PAGE_VIEW_TYPES));

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
            reach: $reach,
            linkClicks: $linkClicks,
            landingPageViews: $landingPageViews,
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

    /**
     * Sum the plain (unattributed) value of the first present action type — used
     * for funnel steps like landing_page_view that aren't attributed conversions.
     *
     * @param array<int, array<string, mixed>> $actions
     * @param array<int, string> $types
     */
    private static function plainActionTotal(array $actions, array $types): float
    {
        foreach ($types as $type) {
            foreach ($actions as $action) {
                if (! is_array($action) || ($action['action_type'] ?? null) !== $type) {
                    continue;
                }
                $val = $action['value'] ?? 0;
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
