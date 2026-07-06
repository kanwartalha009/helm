<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AdCreativeDaily;
use App\Models\Brand;
use App\Platforms\Meta\MetaCreativeFetcher;
use App\Services\Currency\FxService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Backfill ad-level (creative) Meta performance into ad_creative_daily for the
 * Ads hub Creatives view (Phase D): spend / impressions / clicks / attributed
 * purchases + value per ad per day, plus the ad name and a creative thumbnail.
 *
 * Ad-level insights are the heaviest Meta pull (one call per DAY per account, per
 * AdProductFetcher), so this defaults to a RECENT window — creatives are about
 * current ads, not last year. Widen with --since for a longer history. Additive
 * upsert on (brand, meta, date, ad); never touches daily_metrics or the campaign
 * table. Meta only (creatives are platform-specific).
 *
 *   php artisan meta:backfill-creatives                       # all active brands, last ~60 days
 *   php artisan meta:backfill-creatives meller                # one brand
 *   php artisan meta:backfill-creatives meller --since=2026-05-01 --chunk-days=7
 *   php artisan meta:backfill-creatives --missing             # only brands with no creative rows yet
 */
class MetaBackfillCreativesCommand extends Command
{
    protected $signature = 'meta:backfill-creatives '
        . '{brand? : slug or id; omit for all active brands} '
        . '{--since=2026-05-01 : first day to pull (Y-m-d)} '
        . '{--chunk-days=7 : days per fetch window; lower it if Meta rate-limits} '
        . '{--missing : only brands that have NO ad_creative_daily rows yet}';

    protected $description = 'Backfill ad-level (creative) Meta performance + thumbnails into ad_creative_daily for the Ads hub Creatives view.';

    public function handle(MetaCreativeFetcher $meta, FxService $fx): int
    {
        $since = (string) $this->option('since');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) {
            $this->error('--since must be a Y-m-d date.');

            return self::FAILURE;
        }

        $onlyMissing = (bool) $this->option('missing');
        $chunkDays   = max(1, (int) $this->option('chunk-days'));

        $brands = $this->resolveBrands();
        if ($brands->isEmpty()) {
            $this->warn('No matching brands.');

            return self::SUCCESS;
        }

        $totalRows = 0;

        foreach ($brands as $brand) {
            $conn = $brand->connections->firstWhere('platform', 'meta');
            if (! $conn || $conn->status !== 'active') {
                continue; // brand doesn't run Meta
            }

            if ($onlyMissing && AdCreativeDaily::query()->where('brand_id', $brand->id)->where('platform', 'meta')->exists()) {
                $this->line("· {$brand->name}: already has creative rows — skipped (--missing).");
                continue;
            }

            $tz    = $brand->timezone ?: 'UTC';
            $from  = CarbonImmutable::parse($since, $tz)->startOfDay();
            $until = CarbonImmutable::now($tz)->subDay()->startOfDay(); // today is partial → live sync owns it
            if ($from->greaterThan($until)) {
                $this->line("· {$brand->name}: --since is in the future for this brand — skipped.");
                continue;
            }

            $conn->setRelation('brand', $brand);
            $currency = $brand->base_currency;
            $fxCache  = [];

            $rows   = 0;
            $failed = false;
            $cursor = $from;
            while ($cursor->lessThanOrEqualTo($until)) {
                $chunkEnd = $cursor->addDays($chunkDays - 1);
                if ($chunkEnd->greaterThan($until)) {
                    $chunkEnd = $until;
                }

                try {
                    $fetched = $meta->fetchCreativeRange($conn, $cursor, $chunkEnd);
                } catch (Throwable $e) {
                    $this->error("· {$brand->name} {$cursor->toDateString()}: {$e->getMessage()}");
                    $failed = true;
                    break;
                }

                if ($fetched !== []) {
                    $records = $this->records($brand, $fetched, $currency, $fxCache, $fx);
                    foreach (array_chunk($records, 500) as $chunk) {
                        AdCreativeDaily::upsert(
                            $chunk,
                            ['brand_id', 'platform', 'date', 'ad_id'],
                            ['ad_name', 'campaign_id', 'thumbnail_url', 'spend', 'impressions', 'clicks', 'conversions', 'conversion_value', 'currency', 'fx_rate_to_usd', 'is_complete', 'pulled_at'],
                        );
                    }
                    $rows += count($records);
                }

                $cursor = $chunkEnd->addDay();
                usleep(150_000);
            }

            if ($failed) {
                $this->warn("· {$brand->name}: stopped early — fix the cause and re-run (idempotent).");
                $totalRows += $rows;
                continue;
            }

            $this->info("· {$brand->name}: {$rows} creative-day rows backfilled.");
            $totalRows += $rows;
        }

        $this->info("Done. {$totalRows} creative-day rows upserted across {$brands->count()} brand(s).");

        return self::SUCCESS;
    }

    /**
     * Map fetched ad rows to ad_creative_daily records, resolving each day's USD
     * rate once (cached per brand). Native money + stored fx snapshot (spec rule 7).
     *
     * @param array<int, array<string, mixed>> $fetched
     * @param array<string, ?float>            $fxCache  by-date cache, mutated
     * @return array<int, array<string, mixed>>
     */
    private function records(Brand $brand, array $fetched, string $currency, array &$fxCache, FxService $fx): array
    {
        $records = [];
        foreach ($fetched as $r) {
            $date  = (string) $r['date'];
            $adId  = (string) $r['ad_id'];
            if ($adId === '') {
                continue;
            }

            $rowCcy = strtoupper((string) ($r['currency'] ?? $currency));
            $fxKey  = "{$rowCcy}|{$date}";
            $fxRate = $fxCache[$fxKey] ??= $fx->cachedToUsd($rowCcy, CarbonImmutable::parse($date));

            $records[] = [
                'brand_id'         => $brand->id,
                'platform'         => 'meta',
                'date'             => $date,
                'ad_id'            => mb_substr($adId, 0, 64),
                'ad_name'          => mb_substr((string) ($r['ad_name'] ?? ''), 0, 255),
                'campaign_id'      => mb_substr((string) ($r['campaign_id'] ?? ''), 0, 64) ?: null,
                'thumbnail_url'    => $r['thumbnail_url'] ?? null,
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
