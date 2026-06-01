<?php

declare(strict_types=1);

namespace App\Platforms\Meta;

use App\Models\PlatformConnection;
use App\Platforms\Contracts\MetricSnapshot;
use Carbon\CarbonImmutable;

/**
 * Pulls one day of account-level ad insights (spend, impressions, clicks,
 * conversions, conversion value) for one Meta ad account and returns a
 * MetricSnapshot with the attribution window stamped into metadata.
 *
 * Calls act_{id}/insights with level=account, time_increment=1, and
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
    ) {}

    public function fetch(PlatformConnection $conn, CarbonImmutable $date): MetricSnapshot
    {
        $accountId = self::normalizeAccountId((string) $conn->external_id);
        $day       = $date->toDateString();

        $body = $this->client->get("{$accountId}/insights", [
            'level'                       => 'account',
            'fields'                      => 'spend,impressions,clicks,actions,action_values,account_currency',
            'action_attribution_windows'  => json_encode([self::ATTRIBUTION_WINDOW]),
            'time_range'                  => json_encode(['since' => $day, 'until' => $day]),
            'time_increment'              => 1,
            // Use the requested window, not whatever the account's default is set to.
            'use_account_attribution_setting' => 'false',
        ]);

        $row = $body['data'][0] ?? [];

        // A day is complete once it is fully in the past in the brand's own
        // timezone — today's row is still accruing spend and is partial.
        $tz         = $conn->brand?->timezone ?: 'UTC';
        $isComplete = $date->startOfDay()->lessThan(CarbonImmutable::now($tz)->startOfDay());

        $fallbackCurrency = (string) ($conn->metadata['currency'] ?? 'USD');

        return self::mapInsightRow($row, (int) $conn->brand_id, $date, $fallbackCurrency, $isComplete);
    }

    /**
     * Pure mapping from a Meta insights row to a MetricSnapshot. Static and
     * side-effect-free so it can be unit-tested against a captured payload
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
