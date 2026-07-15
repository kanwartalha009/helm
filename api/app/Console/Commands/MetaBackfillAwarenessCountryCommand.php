<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AdCampaignDailyMetric;
use App\Models\Brand;
use App\Models\MetaBreakdownDaily;
use App\Platforms\Meta\InsightsFetcher;
use App\Platforms\Meta\MetaObjectives;
use App\Services\Currency\FxService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * mom S16 (monthly-report-v2-mom.md §M3) — history for meta_breakdown_daily's
 * new 'awareness_country' breakdown_type. Unlike meta:backfill-breakdown's
 * axes (a fixed `breakdowns` list), this one needs to know WHICH campaigns
 * were awareness-objective on each day — that list changes day to day, so
 * this reads it from ad_campaign_daily_metrics.objective (already backfilled
 * by ads:backfill-campaigns) rather than a static config axis. A day with no
 * awareness campaigns in the DB is silently skipped — DEPENDS ON
 * ads:backfill-campaigns having run first for the same window (the
 * 'campaigns' dataset button runs both commands back to back — see
 * BackfillBrandDatasetJob); running this alone against a brand with no
 * campaign history yet just does nothing, honestly, rather than erroring.
 *
 *   php artisan meta:backfill-awareness-country                       # all brands, since 2025-01-01
 *   php artisan meta:backfill-awareness-country meller --since=2026-04-01
 */
class MetaBackfillAwarenessCountryCommand extends Command
{
    protected $signature = 'meta:backfill-awareness-country '
        . '{brand? : slug or id; omit for all active brands} '
        . '{--since=2025-01-01 : first day to pull (Y-m-d)}';

    protected $description = "Backfill meta_breakdown_daily's awareness_country axis (mom S16) from already-synced campaign objectives.";

    public function handle(InsightsFetcher $meta, FxService $fx): int
    {
        $since = (string) $this->option('since');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) {
            $this->error('--since must be a Y-m-d date.');

            return self::FAILURE;
        }

        $brands = $this->resolveBrands();
        if ($brands->isEmpty()) {
            $this->warn('No matching brands.');

            return self::SUCCESS;
        }

        $totalRows = 0;
        foreach ($brands as $brand) {
            $conn = $brand->connections->firstWhere('platform', 'meta');
            if (! $conn || $conn->status !== 'active') {
                continue;
            }
            $conn->setRelation('brand', $brand);
            $currency = $brand->base_currency;

            // Days that actually have at least one awareness campaign on record —
            // the whole point of reading this from the DB instead of re-deriving
            // objective from a fresh API call every day.
            $dates = AdCampaignDailyMetric::query()
                ->where('brand_id', $brand->id)
                ->where('platform', 'meta')
                ->where('date', '>=', $since)
                ->whereIn('objective', MetaObjectives::awarenessValues())
                ->distinct()
                ->orderBy('date')
                ->pluck('date');

            if ($dates->isEmpty()) {
                $this->line("· {$brand->name}: no awareness-objective campaigns on record since {$since} — nothing to do (run ads:backfill-campaigns first if this brand should have some).");
                continue;
            }

            $rows   = 0;
            $failed = 0;
            foreach ($dates as $date) {
                $day = $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : (string) $date;
                $campaignIds = AdCampaignDailyMetric::query()
                    ->where('brand_id', $brand->id)
                    ->where('platform', 'meta')
                    ->where('date', $day)
                    ->whereIn('objective', MetaObjectives::awarenessValues())
                    ->pluck('campaign_id')
                    ->all();

                if ($campaignIds === []) {
                    continue;
                }

                try {
                    $d       = CarbonImmutable::parse($day);
                    $fetched = $meta->fetchCampaignsCountryBreakdown($conn, $campaignIds, $d, $d);
                } catch (Throwable $e) {
                    $this->error("· {$brand->name} {$day}: {$e->getMessage()}");
                    $failed++;
                    continue;
                }

                if ($fetched === []) {
                    continue;
                }

                $records = [];
                foreach ($fetched as $r) {
                    $seg    = trim((string) $r['segment_key']) ?: 'unknown';
                    $rowCcy = strtoupper((string) ($r['currency'] ?? $currency));
                    $records[] = [
                        'brand_id'         => $brand->id,
                        'platform'         => 'meta',
                        'date'             => (string) $r['date'],
                        'breakdown_type'   => 'awareness_country',
                        'segment_key'      => mb_substr($seg, 0, 191),
                        'segment_label'    => mb_substr((string) ($r['segment_label'] ?? $seg), 0, 191),
                        'spend'            => (float) ($r['spend'] ?? 0),
                        'impressions'      => (int) ($r['impressions'] ?? 0),
                        'clicks'           => (int) ($r['clicks'] ?? 0),
                        'reach'            => (int) ($r['reach'] ?? 0),
                        'conversions'      => (int) ($r['conversions'] ?? 0),
                        'conversion_value' => (float) ($r['conversion_value'] ?? 0),
                        'currency'         => $rowCcy,
                        'fx_rate_to_usd'   => $fx->cachedToUsd($rowCcy, CarbonImmutable::parse($day)),
                        'is_complete'      => true,
                        'pulled_at'        => now(),
                    ];
                }

                foreach (array_chunk($records, 500) as $chunk) {
                    MetaBreakdownDaily::upsert(
                        $chunk,
                        ['brand_id', 'platform', 'date', 'breakdown_type', 'segment_key'],
                        ['segment_label', 'spend', 'impressions', 'clicks', 'reach', 'conversions', 'conversion_value', 'currency', 'fx_rate_to_usd', 'is_complete', 'pulled_at'],
                    );
                }
                $rows += count($records);
                usleep(150_000);
            }

            $totalRows += $rows;
            $note = $failed > 0 ? " — {$failed} day(s) failed, re-run to fill (idempotent)" : '';
            $this->info("· {$brand->name}: {$rows} awareness_country rows across " . $dates->count() . " awareness-campaign day(s){$note}.");
        }

        $this->info("Done. {$totalRows} rows upserted across {$brands->count()} brand(s).");

        return self::SUCCESS;
    }

    /** @return \Illuminate\Support\Collection<int, Brand> */
    private function resolveBrands(): \Illuminate\Support\Collection
    {
        $arg = $this->argument('brand');

        if ($arg === null) {
            return Brand::query()->with('connections')->where('status', 'active')->orderBy('name')->get();
        }

        $argStr = (string) $arg;
        $lower  = strtolower(trim($argStr));

        $brand = is_numeric($argStr)
            ? Brand::query()->with('connections')->find((int) $argStr)
            : (Brand::query()->with('connections')
                ->whereRaw('LOWER(slug) = ?', [$lower])
                ->orWhereRaw('LOWER(name) = ?', [$lower])
                ->first()
                ?: Brand::query()->with('connections')
                    ->where('name', 'like', '%' . $argStr . '%')
                    ->orWhere('slug', 'like', '%' . $argStr . '%')
                    ->first());

        return collect($brand ? [$brand] : []);
    }
}
