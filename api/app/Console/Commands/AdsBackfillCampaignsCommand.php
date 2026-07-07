<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AdCampaignDailyMetric;
use App\Models\Brand;
use App\Platforms\Google\ReportsFetcher;
use App\Platforms\Meta\InsightsFetcher;
use App\Platforms\TikTok\ReportsFetcher as TikTokReportsFetcher;
use App\Services\Currency\FxService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Backfill campaign-level Meta + Google performance into ad_campaign_daily_metrics
 * for the reporting engine's ads audit (feature spec slice 2.2 / 2.4): spend,
 * purchases, conversion value and ROAS per campaign per day — the grain the
 * audit needs to rank winners, flag waste and build the kill-list + strategy.
 *
 * Small day-windows (campaign-level daily insights trip Meta's "reduce the
 * amount of data" cap on busy accounts over long ranges; --chunk-days tunes it,
 * Google is chunked the same way). Additive upsert on (brand, platform, date,
 * campaign) that NEVER touches daily_metrics.
 *
 *   php artisan ads:backfill-campaigns                          # all active brands, both platforms, since 2025-01-01
 *   php artisan ads:backfill-campaigns meller                   # one brand
 *   php artisan ads:backfill-campaigns meller --platform=meta   # one brand, Meta only
 *   php artisan ads:backfill-campaigns meller --since=2026-04-01 --chunk-days=3   # busy account → smaller windows
 */
class AdsBackfillCampaignsCommand extends Command
{
    protected $signature = 'ads:backfill-campaigns '
        . '{brand? : slug or id; omit for all active brands} '
        . '{--since=2025-01-01 : first day to pull (Y-m-d)} '
        . '{--platform= : meta|google|tiktok; omit for all} '
        . '{--chunk-days=7 : days per API request; lower it (e.g. 3 or 1) if Meta returns "reduce the amount of data"}';

    protected $description = 'Backfill campaign-level Meta + Google + TikTok performance into ad_campaign_daily_metrics for the ads audit.';

    public function handle(InsightsFetcher $meta, ReportsFetcher $google, TikTokReportsFetcher $tiktok, FxService $fx): int
    {
        $since = (string) $this->option('since');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) {
            $this->error('--since must be a Y-m-d date.');

            return self::FAILURE;
        }

        $platformOpt = strtolower(trim((string) ($this->option('platform') ?? '')));
        $platforms   = $platformOpt === '' ? ['meta', 'google', 'tiktok'] : [$platformOpt];
        if (array_diff($platforms, ['meta', 'google', 'tiktok']) !== []) {
            $this->error('--platform must be meta, google or tiktok.');

            return self::FAILURE;
        }

        // Campaign-level daily insights are far heavier than account-level, so a
        // month-long window trips Meta's "reduce the amount of data" cap on busy
        // accounts. Pull in small day-windows instead — tunable per run.
        $chunkDays = max(1, (int) $this->option('chunk-days'));

        $brands = $this->resolveBrands();
        if ($brands->isEmpty()) {
            $this->warn('No matching brands.');

            return self::SUCCESS;
        }

        $totalRows = 0;

        foreach ($brands as $brand) {
            $tz    = $brand->timezone ?: 'UTC';
            $from  = CarbonImmutable::parse($since, $tz)->startOfDay();
            $until = CarbonImmutable::now($tz)->subDay()->startOfDay(); // today is partial → live sync owns it
            if ($from->greaterThan($until)) {
                $this->line("· {$brand->name}: --since is in the future for this brand — skipped.");
                continue;
            }

            $currency = $brand->base_currency;
            $fxCache  = [];

            foreach ($platforms as $platform) {
                $conn = $brand->connections->firstWhere('platform', $platform);
                if (! $conn || $conn->status !== 'active') {
                    continue; // brand doesn't run this platform
                }
                $conn->setRelation('brand', $brand);

                $rows   = 0;
                $failed = false;
                $cursor = $from;
                while ($cursor->lessThanOrEqualTo($until)) {
                    $chunkEnd = $cursor->addDays($chunkDays - 1);
                    if ($chunkEnd->greaterThan($until)) {
                        $chunkEnd = $until;
                    }

                    try {
                        $fetched = match ($platform) {
                            'meta'   => $meta->fetchCampaignRange($conn, $cursor, $chunkEnd),
                            'google' => $google->fetchCampaignRange($conn, $cursor, $chunkEnd),
                            default  => $tiktok->fetchCampaignRange($conn, $cursor, $chunkEnd),
                        };
                    } catch (Throwable $e) {
                        $this->error("· {$brand->name} [{$platform}] {$cursor->toDateString()}: {$e->getMessage()}");
                        $failed = true;
                        break;
                    }

                    if ($fetched !== []) {
                        $records = $this->records($brand, $platform, $fetched, $currency, $fxCache, $fx);
                        foreach (array_chunk($records, 500) as $chunk) {
                            AdCampaignDailyMetric::upsert(
                                $chunk,
                                ['brand_id', 'platform', 'date', 'campaign_id'],
                                ['campaign_name', 'spend', 'impressions', 'clicks', 'conversions', 'conversion_value', 'currency', 'fx_rate_to_usd', 'is_complete', 'pulled_at'],
                            );
                        }
                        $rows += count($records);
                    }

                    $cursor = $chunkEnd->addDay();
                    usleep(150_000);
                }

                if ($failed) {
                    $this->warn("· {$brand->name} [{$platform}]: stopped early — fix the cause and re-run (idempotent).");
                    $totalRows += $rows;
                    continue;
                }

                if ($rows === 0) {
                    $this->line("· {$brand->name} [{$platform}]: no campaign rows for {$since}..{$until->toDateString()}.");
                } else {
                    $this->info("· {$brand->name} [{$platform}]: {$rows} campaign-day rows backfilled.");
                }
                $totalRows += $rows;
            }
        }

        $this->info("Done. {$totalRows} campaign-day rows upserted across {$brands->count()} brand(s).");

        return self::SUCCESS;
    }

    /**
     * Map fetched campaign rows to ad_campaign_daily_metrics records, resolving
     * each day's USD rate once (cached per brand). Spend/value stay native; the
     * stored fx snapshot lets the audit compute ROAS in USD without converting
     * at read time (spec rule 7).
     *
     * @param array<int, array<string, mixed>> $fetched
     * @param array<string, ?float>            $fxCache  by-date cache, mutated
     * @return array<int, array<string, mixed>>
     */
    private function records(Brand $brand, string $platform, array $fetched, string $currency, array &$fxCache, FxService $fx): array
    {
        $records = [];
        foreach ($fetched as $r) {
            $date = (string) $r['date'];
            $cid  = (string) $r['campaign_id'];
            if ($cid === '') {
                continue;
            }

            $rowCcy = strtoupper((string) ($r['currency'] ?? $currency));
            $fxKey  = "{$rowCcy}|{$date}";
            $fxRate = $fxCache[$fxKey] ??= $fx->cachedToUsd($rowCcy, CarbonImmutable::parse($date));

            $records[] = [
                'brand_id'         => $brand->id,
                'platform'         => $platform,
                'date'             => $date,
                'campaign_id'      => mb_substr($cid, 0, 64),
                'campaign_name'    => mb_substr((string) ($r['campaign_name'] ?? ''), 0, 255),
                'spend'            => (float) ($r['spend'] ?? 0),
                'impressions'      => (int) ($r['impressions'] ?? 0),
                'clicks'           => (int) ($r['clicks'] ?? 0),
                'conversions'      => (int) ($r['conversions'] ?? 0),
                'conversion_value' => (float) ($r['conversion_value'] ?? 0),
                'currency'         => $rowCcy,
                'fx_rate_to_usd'   => $fxRate,
                'is_complete'      => true,
                'pulled_at'        => now(),
            ];
        }

        return $records;
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
