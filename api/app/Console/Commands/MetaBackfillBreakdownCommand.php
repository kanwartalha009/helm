<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\MetaBreakdownDaily;
use App\Platforms\Meta\InsightsFetcher;
use App\Services\Currency\FxService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Backfill Meta spend split by a breakdown axis into meta_breakdown_daily for the
 * dashboard's Audience view. Default axis is `audience` (ASC new/engaged/existing/
 * unknown via user_segment_key); --type also accepts age_gender,
 * placement_platform (FB/IG/Audience Network/Messenger — the default Placement
 * view), placement (granular position split), country, device, or all. Monthly
 * windows, fx-stamped, additive upsert that never touches daily_metrics.
 *
 *   php artisan meta:backfill-breakdown                                  # all brands, audience, since 2025-01-01
 *   php artisan meta:backfill-breakdown meller --since=2026-04-01
 *   php artisan meta:backfill-breakdown --type=placement_platform
 *   php artisan meta:backfill-breakdown --type=all
 */
class MetaBackfillBreakdownCommand extends Command
{
    protected $signature = 'meta:backfill-breakdown '
        . '{brand? : slug or id; omit for all active brands} '
        . '{--since=2025-01-01 : first day to pull (Y-m-d)} '
        . '{--type=audience : audience|age_gender|placement_platform|placement|country|device|all} '
        . '{--missing : only brands/types with NO existing rows (freshly added brands) — skips anything already synced, so a portfolio re-run stays light on Meta}';

    protected $description = 'Backfill Meta spend by audience/placement/etc. breakdown into meta_breakdown_daily.';

    public function handle(InsightsFetcher $meta, FxService $fx): int
    {
        $since = (string) $this->option('since');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) {
            $this->error('--since must be a Y-m-d date.');

            return self::FAILURE;
        }

        /** @var array<string, array<int, string>> $map */
        $map     = (array) config('meta_breakdowns', []);
        $typeOpt = strtolower(trim((string) $this->option('type')));
        $types   = $typeOpt === 'all' ? array_keys($map) : [$typeOpt];
        if (array_diff($types, array_keys($map)) !== []) {
            $this->error('--type must be one of: ' . implode(', ', array_keys($map)) . ', all.');

            return self::FAILURE;
        }

        $brands = $this->resolveBrands();
        if ($brands->isEmpty()) {
            $this->warn('No matching brands.');

            return self::SUCCESS;
        }

        // --missing: only fill brands/types that have no rows yet (freshly added
        // brands). Skips everything already synced, so re-running across the whole
        // portfolio doesn't re-pull Meta for 70+ brands (rate-limit friendly).
        $missing   = (bool) $this->option('missing');
        $totalRows = 0;
        $skipped   = 0;

        foreach ($brands as $brand) {
            $conn = $brand->connections->firstWhere('platform', 'meta');
            if (! $conn || $conn->status !== 'active') {
                continue; // brand doesn't run Meta
            }
            $conn->setRelation('brand', $brand);

            $tz       = $brand->timezone ?: 'UTC';
            $from     = CarbonImmutable::parse($since, $tz)->startOfDay();
            $until    = CarbonImmutable::now($tz)->subDay()->startOfDay(); // today is partial → live sync owns it
            if ($from->greaterThan($until)) {
                $this->line("· {$brand->name}: --since is in the future for this brand — skipped.");
                continue;
            }
            $currency = $brand->base_currency;
            $fxCache  = [];

            foreach ($types as $type) {
                if ($missing && MetaBreakdownDaily::query()
                    ->where('brand_id', $brand->id)
                    ->where('breakdown_type', $type)
                    ->exists()
                ) {
                    $skipped++;
                    continue; // already has this breakdown — leave it, --missing only fills gaps
                }

                $breakdowns = $map[$type];
                $rows       = 0;
                $failed     = false;

                $cursor = $from;
                while ($cursor->lessThanOrEqualTo($until)) {
                    $chunkEnd = $cursor->addMonth()->subDay();
                    if ($chunkEnd->greaterThan($until)) {
                        $chunkEnd = $until;
                    }

                    try {
                        $fetched = $this->fetchWithSplit($meta, $conn, $breakdowns, $cursor, $chunkEnd);
                    } catch (Throwable $e) {
                        $this->error("· {$brand->name} [{$type}] {$cursor->toDateString()}: {$e->getMessage()}");
                        $failed = true;
                        $cursor = $chunkEnd->addDay(); // skip just this window, keep going
                        continue;
                    }

                    if ($fetched !== []) {
                        $records = $this->records($brand, $type, $fetched, $currency, $fxCache, $fx);
                        foreach (array_chunk($records, 500) as $chunk) {
                            MetaBreakdownDaily::upsert(
                                $chunk,
                                ['brand_id', 'date', 'breakdown_type', 'segment_key'],
                                ['segment_label', 'spend', 'impressions', 'clicks', 'conversions', 'conversion_value', 'currency', 'fx_rate_to_usd', 'is_complete', 'pulled_at'],
                            );
                        }
                        $rows += count($records);
                    }

                    $cursor = $chunkEnd->addDay();
                    usleep(150_000);
                }

                $totalRows += $rows;
                $note = $failed ? ' — stopped early, re-run to fill (idempotent)' : '';
                $this->info("· {$brand->name} [{$type}]: {$rows} rows backfilled ({$since}..{$until->toDateString()}){$note}.");
            }
        }

        $note = $missing && $skipped > 0 ? " ({$skipped} brand-type(s) already had data — skipped)" : '';
        $this->info("Done. {$totalRows} breakdown rows upserted across {$brands->count()} brand(s){$note}.");

        return self::SUCCESS;
    }

    /**
     * Fetch a breakdown window, halving it on Meta's "reduce the amount of data"
     * error (code 1) and retrying each half — recursing down to a single day.
     * country / age_gender over a whole month blow Meta's per-query row estimate;
     * splitting keeps each call small without losing any days (windows are
     * disjoint, so the upsert can't double-count).
     *
     * @param array<int, string> $breakdowns
     * @return array<int, array<string, mixed>>
     */
    private function fetchWithSplit(InsightsFetcher $meta, $conn, array $breakdowns, CarbonImmutable $from, CarbonImmutable $to): array
    {
        try {
            return $meta->fetchBreakdownRange($conn, $breakdowns, $from, $to);
        } catch (Throwable $e) {
            $msg     = strtolower($e->getMessage());
            $tooMuch = str_contains($msg, 'reduce the amount of data')
                || str_contains($msg, 'error 1:')
                || $e->getCode() === 1;

            if (! $tooMuch || $from->greaterThanOrEqualTo($to)) {
                throw $e; // genuine error, or already a single day → give up
            }

            $mid = $from->addDays(intdiv((int) $from->diffInDays($to), 2));

            return array_merge(
                $this->fetchWithSplit($meta, $conn, $breakdowns, $from, $mid),
                $this->fetchWithSplit($meta, $conn, $breakdowns, $mid->addDay(), $to),
            );
        }
    }

    /**
     * @param array<int, array<string, mixed>> $fetched
     * @param array<string, ?float>            $fxCache  by-date cache, mutated
     * @return array<int, array<string, mixed>>
     */
    private function records(Brand $brand, string $type, array $fetched, string $currency, array &$fxCache, FxService $fx): array
    {
        $records = [];
        foreach ($fetched as $r) {
            $date = (string) $r['date'];
            $seg  = trim((string) $r['segment_key']);
            if ($seg === '') {
                $seg = 'unknown';
            }

            $rowCcy = strtoupper((string) ($r['currency'] ?? $currency));
            $fxKey  = "{$rowCcy}|{$date}";
            $fxRate = $fxCache[$fxKey] ??= $fx->cachedToUsd($rowCcy, CarbonImmutable::parse($date));

            $records[] = [
                'brand_id'         => $brand->id,
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
