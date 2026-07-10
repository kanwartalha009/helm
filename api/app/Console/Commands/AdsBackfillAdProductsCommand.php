<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AdProductDaily;
use App\Models\Brand;
use App\Platforms\Google\AdProductFetcher as GoogleAdProductFetcher;
use App\Platforms\TikTok\AdProductFetcher as TikTokAdProductFetcher;
use App\Services\Currency\FxService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Backfill Google + TikTok ad spend attributed to a Shopify PRODUCT (by landing
 * URL) into ad_product_daily (spec §4 Phase 5). Meta has its own command
 * (meta:backfill-ad-products); this widens the same table to the other two
 * platforms so the products page can show cross-platform ad spend + ROAS.
 *
 * Additive upsert on (brand, platform, date, product_key) — never touches the
 * Meta rows, never touches daily_metrics. Idempotent: re-running resumes.
 *
 *   php artisan ads:backfill-ad-products                          # all active brands, google+tiktok, since 2025-01-01
 *   php artisan ads:backfill-ad-products meller --platform=google
 *   php artisan ads:backfill-ad-products meller --since=2026-04-01 --chunk-days=14
 */
class AdsBackfillAdProductsCommand extends Command
{
    protected $signature = 'ads:backfill-ad-products '
        . '{brand? : slug or id; omit for all active brands} '
        . '{--since=2025-01-01 : first day to pull (Y-m-d)} '
        . '{--platform= : google|tiktok; omit for both (Meta has its own command)} '
        . '{--chunk-days=30 : days per API request}';

    protected $description = 'Backfill Google + TikTok product-attributed ad spend into ad_product_daily for the products page ROAS.';

    public function handle(GoogleAdProductFetcher $google, TikTokAdProductFetcher $tiktok, FxService $fx): int
    {
        $since = (string) $this->option('since');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) {
            $this->error('--since must be a Y-m-d date.');

            return self::FAILURE;
        }

        $platformOpt = strtolower(trim((string) ($this->option('platform') ?? '')));
        $platforms   = $platformOpt === '' ? ['google', 'tiktok'] : [$platformOpt];
        if (array_diff($platforms, ['google', 'tiktok']) !== []) {
            $this->error('--platform must be google or tiktok (Meta uses meta:backfill-ad-products).');

            return self::FAILURE;
        }

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
            $until = CarbonImmutable::now($tz)->subDay()->startOfDay();
            if ($from->greaterThan($until)) {
                $this->line("· {$brand->name}: --since is in the future for this brand — skipped.");
                continue;
            }
            $fxCache = [];

            foreach ($platforms as $platform) {
                $conn = $brand->connections->firstWhere('platform', $platform);
                if (! $conn || $conn->status !== 'active') {
                    continue;
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
                        $fetched = $platform === 'google'
                            ? $google->fetchRange($conn, $cursor, $chunkEnd)
                            : $tiktok->fetchRange($conn, $cursor, $chunkEnd);
                    } catch (Throwable $e) {
                        $this->error("· {$brand->name} [{$platform}] {$cursor->toDateString()}: {$e->getMessage()}");
                        $failed = true;
                        break;
                    }

                    if ($fetched !== []) {
                        $records = $this->records($brand, $platform, $fetched, $fxCache, $fx);
                        foreach (array_chunk($records, 500) as $chunk) {
                            AdProductDaily::upsert(
                                $chunk,
                                ['brand_id', 'platform', 'date', 'product_key'],
                                ['spend', 'ads_count', 'currency', 'fx_rate_to_usd', 'is_complete', 'pulled_at'],
                            );
                        }
                        $rows += count($records);
                    }

                    $cursor = $chunkEnd->addDay();
                    usleep(200_000);
                }

                if ($failed) {
                    $this->warn("· {$brand->name} [{$platform}]: stopped early — fix the cause and re-run (idempotent).");
                } elseif ($rows === 0) {
                    $this->line("· {$brand->name} [{$platform}]: no product-attributed rows for {$since}..{$until->toDateString()}.");
                } else {
                    $this->info("· {$brand->name} [{$platform}]: {$rows} ad-product rows backfilled.");
                }
                $totalRows += $rows;
            }
        }

        $this->info("Done. {$totalRows} ad-product rows upserted across {$brands->count()} brand(s).");

        return self::SUCCESS;
    }

    /**
     * @param array<int, array{date: string, key: string, spend: float, ads: int, currency: string}> $fetched
     * @param array<string, ?float> $fxCache  keyed "CCY|date", mutated
     * @return array<int, array<string, mixed>>
     */
    private function records(Brand $brand, string $platform, array $fetched, array &$fxCache, FxService $fx): array
    {
        $records = [];
        foreach ($fetched as $r) {
            $date   = (string) $r['date'];
            $key    = (string) $r['key'];
            if ($date === '' || $key === '') {
                continue;
            }
            $ccy    = strtoupper((string) ($r['currency'] ?? $brand->base_currency ?: 'USD'));
            $fxKey  = "{$ccy}|{$date}";
            $fxRate = $fxCache[$fxKey] ??= $fx->cachedToUsd($ccy, CarbonImmutable::parse($date));

            $records[] = [
                'brand_id'       => $brand->id,
                'platform'       => $platform,
                'date'           => $date,
                'product_key'    => mb_substr($key, 0, 191),
                'spend'          => (float) $r['spend'],
                'ads_count'      => (int) $r['ads'],
                'currency'       => $ccy,
                'fx_rate_to_usd' => $fxRate,
                'is_complete'    => true,
                'pulled_at'      => now(),
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
