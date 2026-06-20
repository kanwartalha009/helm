<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\DailyMetric;
use App\Platforms\Contracts\MetricSnapshot;
use App\Platforms\Google\ReportsFetcher;
use App\Platforms\Meta\InsightsFetcher;
use App\Services\Currency\FxService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Backfill historical daily ad spend (Meta + Google) so the dashboard's
 * year-over-year comparison can show Spend + ROAS vs last year (Bosco,
 * 2026-06-20). The live sync only began when the ad platforms were connected
 * (June 2026), so neither last year nor early June this year has spend on file.
 *
 * One ranged Insights call per ad account covers the whole window (mirrors the
 * Shopify sales backfill), and the upsert touches ONLY ad columns on the
 * (brand, meta|google, date) rows — Shopify revenue rows are a different
 * platform key and are never affected. TikTok is excluded until its BC token
 * exists.
 *
 *   php artisan ads:backfill-spend                          # all active brands, both platforms, since 2025-01-01
 *   php artisan ads:backfill-spend meller                   # one brand
 *   php artisan ads:backfill-spend meller --platform=meta   # one brand, Meta only
 *   php artisan ads:backfill-spend --since=2025-05-01
 */
class AdsBackfillSpendCommand extends Command
{
    protected $signature = 'ads:backfill-spend {brand? : slug or id; omit for all active brands} {--since=2025-01-01 : first day to pull (Y-m-d)} {--platform= : meta|google; omit for both}';

    protected $description = 'Backfill historical daily Meta + Google spend for the year-over-year spend/ROAS comparison (ad-only upsert, never touches Shopify rows).';

    public function handle(InsightsFetcher $meta, ReportsFetcher $google, FxService $fx): int
    {
        $since = (string) $this->option('since');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) {
            $this->error('--since must be a Y-m-d date.');

            return self::FAILURE;
        }

        $platformOpt = strtolower(trim((string) ($this->option('platform') ?? '')));
        $platforms   = $platformOpt === '' ? ['meta', 'google'] : [$platformOpt];
        if (array_diff($platforms, ['meta', 'google']) !== []) {
            $this->error('--platform must be meta or google (TikTok has no historical token yet).');

            return self::FAILURE;
        }

        $brands = $this->resolveBrands();
        if ($brands->isEmpty()) {
            $this->warn('No matching brands.');

            return self::SUCCESS;
        }

        $totalDays = 0;

        foreach ($brands as $brand) {
            $tz    = $brand->timezone ?: 'UTC';
            $from  = CarbonImmutable::parse($since, $tz)->startOfDay();
            // Stop at yesterday — today is partial and owned by the live sync.
            $until = CarbonImmutable::now($tz)->subDay()->startOfDay();
            if ($from->greaterThan($until)) {
                $this->line("· {$brand->name}: --since is in the future for this brand — skipped.");
                continue;
            }

            foreach ($platforms as $platform) {
                $conn = $brand->connections->firstWhere('platform', $platform);
                if (! $conn || $conn->status !== 'active') {
                    continue; // brand doesn't run this platform — nothing to backfill
                }

                // The fetchers read $conn->brand (timezone, base currency); set
                // the relation so they don't lazy-load it per call.
                $conn->setRelation('brand', $brand);

                // Pull in MONTHLY windows and merge by day. A single multi-year
                // daily request to Meta's insights endpoint comes back short for
                // high-volume accounts (partial pages / silent data caps) — which
                // is how a brand that has advertised for years still shows last
                // year as "new". Each small window returns complete; we stitch
                // them together. Google is chunked the same way for parity.
                /** @var array<string, MetricSnapshot> $snapshots */
                $snapshots = [];
                $failed    = false;
                $cursor    = $from;
                while ($cursor->lessThanOrEqualTo($until)) {
                    $chunkEnd = $cursor->addMonth()->subDay();
                    if ($chunkEnd->greaterThan($until)) {
                        $chunkEnd = $until;
                    }

                    try {
                        $chunk = $platform === 'meta'
                            ? $meta->fetchRange($conn, $cursor, $chunkEnd)
                            : $google->fetchRange($conn, $cursor, $chunkEnd);
                    } catch (Throwable $e) {
                        $this->error("· {$brand->name} [{$platform}] {$cursor->toDateString()}..{$chunkEnd->toDateString()}: {$e->getMessage()}");
                        $failed = true;
                        break;
                    }

                    foreach ($chunk as $day => $snap) {
                        $snapshots[$day] = $snap;
                    }
                    $cursor = $chunkEnd->addDay();
                }
                if ($failed) {
                    continue; // a window errored — fix the cause, then re-run (idempotent)
                }

                if ($snapshots === []) {
                    // 0 rows = the account has no spend on record in this window
                    // (e.g. a fresh ad account created at onboarding, so last
                    // year's spend lives in the brand's old, unconnected account).
                    $this->warn("· {$brand->name} [{$platform}]: 0 day-rows — account has no spend on record for {$since}..{$until->toDateString()}.");
                    continue;
                }

                // Snapshot the native->USD rate per day at write time (docs/10),
                // exactly as the live sync does. 2025 days whose rate isn't
                // cached land fx_pending and are filled later by BackfillFxRatesJob.
                $rows = [];
                foreach ($snapshots as $snapshot) {
                    $fxRate = $fx->cachedToUsd($snapshot->currency, $snapshot->date);
                    $row    = $snapshot->toRow($fxRate, fxPending: $fxRate === null);
                    // Model::upsert() bypasses the metadata cast — encode here.
                    if (is_array($row['metadata'] ?? null)) {
                        $row['metadata'] = json_encode($row['metadata']);
                    }
                    $rows[] = $row;
                }

                // Ad-only update set: a backfilled (brand, meta|google, date) row
                // never touches Shopify revenue (different platform key), and we
                // never overwrite commerce columns even in principle.
                foreach (array_chunk($rows, 500) as $chunk) {
                    DailyMetric::upsert(
                        $chunk,
                        ['brand_id', 'platform', 'date'],
                        ['spend', 'impressions', 'clicks', 'conversions', 'conversion_value', 'currency', 'fx_rate_to_usd', 'metadata', 'is_complete', 'pulled_at'],
                    );
                }

                // Report the ACTUAL covered span, not the requested one — a
                // brand whose row only spans recent weeks (e.g. 2026-05-06.. )
                // never had last-year data, which is exactly the gap that shows
                // as an inflated YoY ROAS. Lets you tell real coverage from a
                // partial pull at a glance.
                $dates = array_keys($snapshots);
                $span  = sprintf('%s..%s', min($dates), max($dates));
                $totalDays += count($rows);
                $this->info("· {$brand->name} [{$platform}]: " . count($rows) . " day-rows · covered {$span} (requested {$since}..{$until->toDateString()}).");

                usleep(200_000); // 0.2s breather between platform pulls
            }
        }

        $this->info("Done. {$totalDays} day-rows upserted across {$brands->count()} brand(s).");

        return self::SUCCESS;
    }

    /** @return \Illuminate\Support\Collection<int, Brand> */
    private function resolveBrands(): \Illuminate\Support\Collection
    {
        $arg = $this->argument('brand');

        if ($arg === null) {
            return Brand::query()->with('connections')->where('status', 'active')->orderBy('name')->get();
        }

        // Match on id, exact slug/name (case-insensitive), or a partial name/slug
        // — mirrors shopify:backfill-sales so "meller" finds the brand "Meller".
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
