<?php

declare(strict_types=1);

namespace App\Platforms\Klaviyo;

use App\Models\Brand;
use Carbon\CarbonImmutable;

/**
 * Klaviyo email-attributed revenue per day, split by flow and by campaign
 * (growth-os-master-plan §3.1). Uses the cheap `metric-aggregates` endpoint on the
 * conversion metric (Placed Order), grouped by `$attributed_flow` then
 * `$attributed_message` (Klaviyo campaigns = messages). Returns flat NATIVE rows;
 * the sync stamps fx and brand currency. Values are raw store currency (Klaviyo
 * does no conversion) → currency is the brand's base currency.
 *
 * Missing ≠ 0 (§0): a (source, day) with no attributed orders yields NO row, never a
 * 0 — the read layer treats absent-within-the-synced-window as "no email revenue"
 * and beyond-the-window as "not synced" (—), exactly like the ad tables.
 *
 * Attribution is last-touch within Klaviyo's own windows and changes RETROACTIVELY;
 * a periodic re-sync of recent days is required (the day job re-pulls today+yesterday).
 */
class RevenueFetcher
{
    public function __construct(private readonly KlaviyoClient $client) {}

    /** @return array<int, array<string, mixed>> */
    public function fetchRange(Brand $brand, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $brandId  = (int) $brand->id;
        $metricId = $this->client->conversionMetricId($brandId);
        if ($metricId === null) {
            return []; // no Placed Order metric on this account — nothing to attribute
        }

        $tz       = $brand->timezone ?: 'UTC';
        $currency = strtoupper((string) ($brand->base_currency ?: 'USD'));

        $rows = [];
        foreach (['$attributed_flow' => 'flow', '$attributed_message' => 'campaign'] as $by => $source) {
            $body = $this->client->post($brandId, 'metric-aggregates/', [
                'data' => [
                    'type'       => 'metric-aggregate',
                    'attributes' => [
                        'metric_id'    => $metricId,
                        'measurements' => ['sum_value', 'count'],
                        'interval'     => 'day',
                        'by'           => [$by],
                        // Half-open window from the start of `from` up to the start of
                        // `to`+1 day, in the brand's timezone.
                        'filter'       => [
                            'greater-or-equal(datetime,' . $from->startOfDay()->format('Y-m-d\TH:i:s') . ')',
                            'less-than(datetime,' . $to->addDay()->startOfDay()->format('Y-m-d\TH:i:s') . ')',
                        ],
                        'timezone'     => $tz,
                    ],
                ],
            ]);

            foreach ($this->parse($body, $source, $currency) as $r) {
                $rows[] = $r;
            }
        }

        return $rows;
    }

    /**
     * Parse one metric-aggregate response. `dates` aligns positionally with each
     * group's `measurements.sum_value` / `.count` arrays.
     *
     * @param array<string, mixed> $body
     * @return array<int, array<string, mixed>>
     */
    private function parse(array $body, string $source, string $currency): array
    {
        $attr   = (array) ($body['data']['attributes'] ?? []);
        $dates  = (array) ($attr['dates'] ?? []);
        $groups = (array) ($attr['data'] ?? []);

        $out = [];
        foreach ($groups as $g) {
            $sourceId = (string) (($g['dimensions'][0] ?? '') ?: '');
            if ($sourceId === '' || strtolower($sourceId) === 'null') {
                continue; // the unattributed bucket — not a flow/campaign
            }
            $sum = (array) ($g['measurements']['sum_value'] ?? []);
            $cnt = (array) ($g['measurements']['count'] ?? []);

            foreach ($dates as $i => $d) {
                $value = (float) ($sum[$i] ?? 0);
                $count = (int) round((float) ($cnt[$i] ?? 0));
                if ($value === 0.0 && $count === 0) {
                    continue; // missing ≠ 0
                }
                $out[] = [
                    'date'             => substr((string) $d, 0, 10),
                    'source'           => $source,
                    'source_id'        => mb_substr($sourceId, 0, 64),
                    'source_name'      => null, // v1: names unresolved (saves calls) → renders id / "—"
                    'conversions'      => $count,
                    'conversion_value' => $value,
                    'currency'         => $currency,
                ];
            }
        }

        return $out;
    }
}
