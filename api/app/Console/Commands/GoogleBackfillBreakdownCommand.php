<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\MetaBreakdownDaily;
use App\Platforms\Google\ReportsFetcher;
use App\Services\Currency\FxService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Backfill Google device breakdowns into meta_breakdown_daily[platform=google]
 * for the ads hub's Google Overview (device donut + detail). Mirrors
 * tiktok:backfill-breakdown; the daily sync keeps it fresh going forward, this
 * fills history. Additive upsert keyed on (brand, platform, date, type, segment),
 * best-effort per window (a failed chunk stops that brand and can be re-run).
 *
 * Only `device` exists today — geo is deferred (see ReportsFetcher).
 *
 *   php artisan google:backfill-breakdown                            # all active Google brands
 *   php artisan google:backfill-breakdown nude-project               # one brand
 *   php artisan google:backfill-breakdown nude-project --since=2026-06-01
 */
class GoogleBackfillBreakdownCommand extends Command
{
    protected $signature = 'google:backfill-breakdown '
        . '{brand? : slug or id; omit for all active Google brands} '
        . '{--type=device : device (only axis today)} '
        . '{--since=2026-05-01 : first day to pull (Y-m-d)} '
        . '{--chunk-days=30 : days per fetch window}';

    protected $description = 'Backfill Google device breakdowns into meta_breakdown_daily[platform=google] for the ads hub.';

    public function handle(ReportsFetcher $google, FxService $fx): int
    {
        $since = (string) $this->option('since');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) {
            $this->error('--since must be a Y-m-d date.');

            return self::FAILURE;
        }

        $type = strtolower(trim((string) $this->option('type')));
        if ($type !== 'device') {
            $this->error('--type must be device (the only Google breakdown axis today).');

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
            $conn = $brand->connections->firstWhere('platform', 'google');
            if (! $conn || $conn->status !== 'active') {
                continue;
            }
            $conn->setRelation('brand', $brand);

            $tz    = $brand->timezone ?: 'UTC';
            $from  = CarbonImmutable::parse($since, $tz)->startOfDay();
            $until = CarbonImmutable::now($tz)->subDay()->startOfDay(); // today is partial → live sync owns it
            if ($from->greaterThan($until)) {
                $this->line("· {$brand->name}: --since is in the future for this brand — skipped.");
                continue;
            }

            $fallback = strtoupper((string) ($brand->base_currency ?: 'USD'));
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
                    $fetched = $google->fetchBreakdownRange($conn, $type, $cursor, $chunkEnd);
                } catch (Throwable $e) {
                    $this->error("· {$brand->name} [{$type}] {$cursor->toDateString()}: {$e->getMessage()}");
                    $failed = true;
                    break;
                }

                if ($fetched !== []) {
                    $records = $this->records($brand->id, $type, $fetched, $fallback, $fxCache, $fx);
                    foreach (array_chunk($records, 500) as $chunk) {
                        MetaBreakdownDaily::upsert(
                            $chunk,
                            ['brand_id', 'platform', 'date', 'breakdown_type', 'segment_key'],
                            ['segment_label', 'spend', 'impressions', 'clicks', 'conversions', 'conversion_value', 'currency', 'fx_rate_to_usd', 'is_complete', 'pulled_at'],
                        );
                    }
                    $rows += count($records);
                }

                $cursor = $chunkEnd->addDay();
                usleep(150_000);
            }

            if ($failed) {
                $this->warn("· {$brand->name} [{$type}]: stopped early — fix the cause and re-run (idempotent).");
            } else {
                $this->info("· {$brand->name} [{$type}]: {$rows} breakdown-day rows backfilled.");
            }
            $total += $rows;
        }

        $this->info("Done. {$total} Google breakdown-day rows upserted.");

        return self::SUCCESS;
    }

    /**
     * @param array<int, array<string, mixed>> $fetched
     * @param array<string, ?float>            $fxCache
     * @return array<int, array<string, mixed>>
     */
    private function records(int $brandId, string $type, array $fetched, string $fallback, array &$fxCache, FxService $fx): array
    {
        $records = [];
        foreach ($fetched as $r) {
            $date = (string) $r['date'];
            $seg  = trim((string) ($r['segment_key'] ?? ''));
            if ($seg === '') {
                $seg = 'unknown';
            }
            $rowCcy = strtoupper((string) ($r['currency'] ?? $fallback));
            $fxKey  = "{$rowCcy}|{$date}";
            $fxRate = $fxCache[$fxKey] ??= $fx->cachedToUsd($rowCcy, CarbonImmutable::parse($date));

            $records[] = [
                'brand_id'         => $brandId,
                'platform'         => 'google',
                'date'             => $date,
                'breakdown_type'   => $type,
                'segment_key'      => mb_substr($seg, 0, 191),
                'segment_label'    => mb_substr((string) ($r['segment_label'] ?? $seg), 0, 191),
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
