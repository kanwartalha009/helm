<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AdCreativeDaily;
use App\Models\Brand;
use App\Platforms\TikTok\CreativeFetcher;
use App\Services\Currency\FxService;
use App\Support\BackfillCoverage;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Backfill ad-level (creative) TikTok performance + thumbnails into
 * ad_creative_daily[platform=tiktok] for the ads hub Creatives tab. Mirrors
 * meta:backfill-creatives. Heavy (ad×day report + asset resolution), so defaults
 * to a recent window. Additive upsert on (brand, tiktok, date, ad).
 *
 *   php artisan tiktok:backfill-creatives                          # all active TikTok brands, last ~month
 *   php artisan tiktok:backfill-creatives nude-project --since=2026-06-01
 */
class TikTokBackfillCreativesCommand extends Command
{
    protected $signature = 'tiktok:backfill-creatives '
        . '{brand? : slug or id; omit for all active TikTok brands} '
        . '{--since=2026-06-01 : first day to pull (Y-m-d)} '
        . '{--chunk-days=7 : days per fetch window} '
        . '{--force : re-pull windows already recorded in backfill_coverage}';

    private const DATASET = 'creatives';

    protected $description = 'Backfill ad-level (creative) TikTok performance + thumbnails into ad_creative_daily for the ads hub Creatives tab.';

    public function handle(CreativeFetcher $tiktok, FxService $fx, BackfillCoverage $coverage): int
    {
        $since = (string) $this->option('since');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) {
            $this->error('--since must be a Y-m-d date.');

            return self::FAILURE;
        }
        $chunkDays = max(1, (int) $this->option('chunk-days'));

        $brands = $this->resolveBrands();
        if ($brands->isEmpty()) {
            $this->warn('No matching brands.');

            return self::SUCCESS;
        }

        $total = 0;
        foreach ($brands as $brand) {
            $conn = $brand->connections->firstWhere('platform', 'tiktok');
            if (! $conn || $conn->status !== 'active') {
                continue;
            }
            $conn->setRelation('brand', $brand);

            $tz    = $brand->timezone ?: 'UTC';
            $from  = CarbonImmutable::parse($since, $tz)->startOfDay();
            $until = CarbonImmutable::now($tz)->subDay()->startOfDay();
            if ($from->greaterThan($until)) {
                $this->line("· {$brand->name}: --since is in the future for this brand — skipped.");
                continue;
            }

            $currency = $brand->base_currency;
            $fxCache  = [];
            $rows     = 0;
            $failed   = false;

            // RESUME: skip the windows already pulled for this brand.
            if ((bool) $this->option('force')) {
                $coverage->forget($brand->id, self::DATASET, 'tiktok', $from->toDateString(), $until->toDateString());
            }
            $chunks = $coverage->pendingChunks(
                $brand->id, self::DATASET, 'tiktok',
                $from->toDateString(), $until->toDateString(), $chunkDays,
            );
            if ($chunks === []) {
                $this->line("· {$brand->name}: already backfilled — skipped (--force to re-pull).");
                continue;
            }

            foreach ($chunks as [$chunkFrom, $chunkTo]) {
                $cursor   = CarbonImmutable::parse($chunkFrom, $tz);
                $chunkEnd = CarbonImmutable::parse($chunkTo, $tz);

                try {
                    $fetched = $tiktok->fetchCreativeRange($conn, $cursor, $chunkEnd);
                } catch (Throwable $e) {
                    $this->error("· {$brand->name} {$cursor->toDateString()}: {$e->getMessage()}");
                    $failed = true;
                    break;
                }

                $written = 0;
                if ($fetched !== []) {
                    $records = $this->records($brand, $fetched, $currency, $fxCache, $fx);
                    foreach (array_chunk($records, 500) as $chunk) {
                        AdCreativeDaily::upsert(
                            $chunk,
                            ['brand_id', 'platform', 'date', 'ad_id'],
                            ['ad_name', 'body_text', 'campaign_id', 'thumbnail_url', 'media_type', 'spend', 'impressions', 'clicks', 'video_3s', 'thruplays', 'add_to_cart', 'conversions', 'conversion_value', 'currency', 'fx_rate_to_usd', 'is_complete', 'pulled_at'],
                        );
                    }
                    $written = count($records);
                    $rows   += $written;
                }

                $coverage->mark($brand->id, self::DATASET, 'tiktok', $chunkFrom, $chunkTo, $written);

                usleep(150_000);
            }

            if ($failed) {
                $this->warn("· {$brand->name}: stopped early — fix the cause and re-run (idempotent).");
            } else {
                $this->info("· {$brand->name}: {$rows} creative-day rows backfilled.");
            }
            $total += $rows;
        }

        $this->info("Done. {$total} TikTok creative-day rows upserted.");

        return self::SUCCESS;
    }

    /**
     * @param array<int, array<string, mixed>> $fetched
     * @param array<string, ?float>            $fxCache
     * @return array<int, array<string, mixed>>
     */
    private function records(Brand $brand, array $fetched, string $currency, array &$fxCache, FxService $fx): array
    {
        $records = [];
        foreach ($fetched as $r) {
            $date = (string) $r['date'];
            $adId = (string) $r['ad_id'];
            if ($adId === '') {
                continue;
            }
            $rowCcy = strtoupper((string) ($r['currency'] ?? $currency));
            $fxKey  = "{$rowCcy}|{$date}";
            $fxRate = $fxCache[$fxKey] ??= $fx->cachedToUsd($rowCcy, CarbonImmutable::parse($date));

            $records[] = [
                'brand_id'         => $brand->id,
                'platform'         => 'tiktok',
                'date'             => $date,
                'ad_id'            => mb_substr($adId, 0, 64),
                'ad_name'          => mb_substr((string) ($r['ad_name'] ?? ''), 0, 255),
                'body_text'        => isset($r['body_text']) && $r['body_text'] !== null ? mb_substr((string) $r['body_text'], 0, 2000) : null,
                'campaign_id'      => $r['campaign_id'] ? mb_substr((string) $r['campaign_id'], 0, 64) : null,
                'thumbnail_url'    => $r['thumbnail_url'] ?? null,
                'media_type'       => in_array($r['media_type'] ?? 'image', ['image', 'video'], true) ? $r['media_type'] : 'image',
                'spend'            => (float) ($r['spend'] ?? 0),
                'impressions'      => (int) ($r['impressions'] ?? 0),
                'clicks'           => (int) ($r['clicks'] ?? 0),
                'video_3s'         => (int) ($r['video_3s'] ?? 0),
                'thruplays'        => (int) ($r['thruplays'] ?? 0),
                'add_to_cart'      => (int) ($r['add_to_cart'] ?? 0),
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

    /** @return Collection<int, Brand> */
    private function resolveBrands(): Collection
    {
        $arg = $this->argument('brand');
        if ($arg === null) {
            return Brand::query()->with('connections')->where('status', 'active')->orderBy('name')->get();
        }

        $argStr = (string) $arg;
        $lower  = strtolower(trim($argStr));

        $brand = is_numeric($argStr)
            ? Brand::query()->with('connections')->find((int) $argStr)
            : Brand::query()->with('connections')->whereRaw('LOWER(slug) = ?', [$lower])->orWhereRaw('LOWER(name) = ?', [$lower])->first();

        return collect($brand ? [$brand] : []);
    }
}
