<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AdProductDaily;
use App\Models\Brand;
use App\Platforms\Meta\AdProductFetcher;
use App\Services\Currency\FxService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Backfill Meta spend attributed to Shopify products into ad_product_daily for
 * the Inventory Intelligence report (feature spec §3). Month-by-month per brand
 * so no single Meta call gets too heavy (error 17 is complexity-based and these
 * accounts run a lot of ads); fx-stamped; additive upsert that never touches
 * daily_metrics.
 *
 *   php artisan meta:backfill-ad-products                      # all Meta brands, since 2025-01-01
 *   php artisan meta:backfill-ad-products ganzitos --since=2026-01-01
 */
class MetaBackfillAdProductsCommand extends Command
{
    protected $signature = 'meta:backfill-ad-products '
        . '{brand? : slug or id; omit for all active brands} '
        . '{--since=2025-01-01 : first day to pull (Y-m-d)}';

    protected $description = 'Backfill Meta spend attributed to Shopify products (by ad landing URL) into ad_product_daily.';

    public function handle(AdProductFetcher $fetcher, FxService $fx): int
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
                continue; // brand doesn't run Meta
            }
            $conn->setRelation('brand', $brand);

            $tz    = $brand->timezone ?: 'UTC';
            $from  = CarbonImmutable::parse($since, $tz)->startOfDay();
            $until = CarbonImmutable::now($tz)->subDay()->startOfDay(); // today is partial → live sync owns it
            if ($from->greaterThan($until)) {
                $this->line("· {$brand->name}: --since is in the future for this brand — skipped.");
                continue;
            }

            $fxCache = [];
            $rows    = 0;
            $failed  = false;

            $cursor = $from;
            while ($cursor->lessThanOrEqualTo($until)) {
                $chunkEnd = $cursor->endOfMonth()->startOfDay();
                if ($chunkEnd->greaterThan($until)) {
                    $chunkEnd = $until;
                }

                try {
                    $fetched = $fetcher->fetchDailyByProduct($conn, $cursor, $chunkEnd);
                } catch (Throwable $e) {
                    $this->error("· {$brand->name} {$cursor->toDateString()}: {$e->getMessage()}");
                    $failed = true;
                    break;
                }

                if ($fetched !== []) {
                    $records = $this->records($brand, $fetched, $fxCache, $fx);
                    foreach (array_chunk($records, 500) as $chunk) {
                        AdProductDaily::upsert(
                            $chunk,
                            ['brand_id', 'date', 'product_key'],
                            ['spend', 'ads_count', 'currency', 'fx_rate_to_usd', 'is_complete', 'pulled_at'],
                        );
                    }
                    $rows += count($records);
                }

                $cursor = $chunkEnd->addDay();
                usleep(400_000); // breather between months — keep clear of Meta's rate limit
            }

            $note = $failed ? ' — stopped early, re-run to fill (idempotent)' : '';
            $this->info("· {$brand->name}: {$rows} rows backfilled ({$since}..{$until->toDateString()}){$note}.");
            $totalRows += $rows;
        }

        $this->info("Done. {$totalRows} ad-product rows upserted across {$brands->count()} brand(s).");

        return self::SUCCESS;
    }

    /**
     * @param array<int, array{date: string, key: string, spend: float, ads: int, currency: string}> $fetched
     * @param array<string, ?float> $fxCache  keyed "CCY|date", mutated
     * @return array<int, array<string, mixed>>
     */
    private function records(Brand $brand, array $fetched, array &$fxCache, FxService $fx): array
    {
        $records = [];
        foreach ($fetched as $r) {
            $date   = (string) $r['date'];
            $ccy    = strtoupper((string) ($r['currency'] ?? $brand->base_currency ?: 'USD'));
            $fxKey  = "{$ccy}|{$date}";
            $fxRate = $fxCache[$fxKey] ??= $fx->cachedToUsd($ccy, CarbonImmutable::parse($date));

            $records[] = [
                'brand_id'       => $brand->id,
                'date'           => $date,
                'product_key'    => mb_substr((string) $r['key'], 0, 191),
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
