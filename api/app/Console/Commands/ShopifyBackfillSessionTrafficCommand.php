<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\SessionTrafficDaily;
use App\Services\Sync\SessionTrafficSync;
use App\Support\BackfillCoverage;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Backfill sessions-by-traffic-type per landing entity into session_traffic_daily
 * (Bosco item B). The daily sync keeps it fresh; this fills history.
 *
 *   php artisan shopify:backfill-session-traffic                     # all active brands, since -90d
 *   php artisan shopify:backfill-session-traffic flabelus            # one brand
 *   php artisan shopify:backfill-session-traffic --since=2025-07-01  # probe confirmed ≥12mo of history
 *   php artisan shopify:backfill-session-traffic --missing           # only brands with no rows yet
 *
 * ══ WHY THIS RUNS DAY BY DAY AND NOT MONTH BY MONTH ══
 * shopify:backfill-funnel chunks by month — one ShopifyQL call per month. This command CANNOT,
 * and copying that pattern would silently corrupt the data. `LIMIT` in ShopifyQL applies to the
 * WHOLE result set, not per day in the TIMESERIES (probe-verified: a 3-day query with LIMIT 8
 * returned eight rows all from the single busiest day). A month-ranged, limited query would
 * therefore return the busiest days and simply omit the quiet ones — and every omitted day
 * would look like a day with no traffic. Per-day is the price of a number that is correct.
 *
 * Cost: roughly 4-6 ShopifyQL calls per brand-day. A year of history for one brand is a few
 * thousand calls, so it is throttled and safe to re-run — the write is idempotent, keyed on
 * (brand, date, entity_type, entity_key, traffic_type).
 */
class ShopifyBackfillSessionTrafficCommand extends Command
{
    protected $signature = 'shopify:backfill-session-traffic '
        . '{brand? : slug or id; omit for all active brands} '
        . '{--since= : first day to pull (Y-m-d); default 90 days ago} '
        . '{--until= : last day to pull (Y-m-d); default yesterday} '
        . '{--missing : only brands with NO existing rows} '
        . '{--force : re-pull days already recorded in backfill_coverage}';

    protected $description = 'Backfill sessions by traffic type per product/collection into session_traffic_daily.';

    /** Politeness pause between days — ShopifyQL is cost-throttled. */
    private const SLEEP_MICROSECONDS = 200_000;

    private const DATASET = 'session_traffic';

    public function handle(SessionTrafficSync $sync, BackfillCoverage $coverage): int
    {
        $sinceOpt = (string) ($this->option('since') ?? '');
        $untilOpt = (string) ($this->option('until') ?? '');

        foreach (['--since' => $sinceOpt, '--until' => $untilOpt] as $flag => $val) {
            if ($val !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
                $this->error("{$flag} must be a Y-m-d date.");

                return self::FAILURE;
            }
        }

        $brands = $this->resolveBrands();
        if ($brands->isEmpty()) {
            $this->warn('No matching brands.');

            return self::SUCCESS;
        }

        $missing   = (bool) $this->option('missing');
        $totalRows = 0;
        $totalDays = 0;

        foreach ($brands as $brand) {
            $conn = $brand->connections->firstWhere('platform', 'shopify');
            if (! $conn || $conn->status !== 'active') {
                $this->line("· {$brand->name}: no active Shopify connection — skipped.");
                continue;
            }

            if ($missing && SessionTrafficDaily::query()->where('brand_id', $brand->id)->exists()) {
                $this->line("· {$brand->name}: already has data — skipped (--missing).");
                continue;
            }

            // The brand's own calendar. Yesterday is the last day that has FINISHED — today is
            // partial by definition, and a partial day stored as a full one is a wrong number.
            $tz    = $brand->timezone ?: 'UTC';
            $now   = CarbonImmutable::now($tz);
            $since = CarbonImmutable::parse($sinceOpt !== '' ? $sinceOpt : $now->subDays(90)->toDateString(), $tz)->startOfDay();
            $until = CarbonImmutable::parse($untilOpt !== '' ? $untilOpt : $now->subDay()->toDateString(), $tz)->startOfDay();

            if ($until->lessThan($since)) {
                $this->warn("· {$brand->name}: --until is before --since — skipped.");
                continue;
            }

            $brandRows       = 0;
            $brandDays       = 0;
            $incompleteDays  = 0;

            // RESUME. This command costs ~5 ShopifyQL calls per brand-day, so a full year is
            // thousands of calls — the single most expensive backfill we run. Re-pulling days
            // already done is the whole cost with none of the benefit.
            if ((bool) $this->option('force')) {
                $coverage->forget($brand->id, self::DATASET, '', $since->toDateString(), $until->toDateString());
            }

            $pending = $coverage->pendingDays(
                $brand->id, self::DATASET, '', $since->toDateString(), $until->toDateString(),
            );

            $windowDays = (int) $since->diffInDays($until) + 1;
            $skipped    = $windowDays - count($pending);

            if ($pending === []) {
                $this->line("· {$brand->name}: all {$windowDays} day(s) already backfilled — skipped (--force to re-pull).");
                continue;
            }
            if ($skipped > 0) {
                $this->line("· {$brand->name}: resuming — {$skipped} of {$windowDays} day(s) already done.");
            }

            foreach ($pending as $dayStr) {
                $written = $sync->syncDay($conn, $dayStr);

                $brandRows += $written;
                $brandDays++;

                if ($written === 0) {
                    $incompleteDays++;
                }

                // Mark AFTER the write. A day marked done but not written is a hole no future run
                // will ever fill. `$written = 0` is still DONE — the store genuinely had no
                // sessions to report, and asking again tomorrow will not change that.
                $coverage->mark($brand->id, self::DATASET, '', $dayStr, $dayStr, $written);

                usleep(self::SLEEP_MICROSECONDS);
            }

            // Count the days we stored but could NOT reconcile — these render "—", and the
            // operator deserves to know how many there are rather than discovering it in the UI.
            $unreconciled = SessionTrafficDaily::query()
                ->where('brand_id', $brand->id)
                ->whereBetween('date', [$since->toDateString(), $until->toDateString()])
                ->where('is_complete', false)
                ->distinct()
                ->count('date');

            $notes = [];
            if ($incompleteDays > 0) {
                $notes[] = "{$incompleteDays} day(s) returned nothing (re-run to fill; idempotent)";
            }
            if ($unreconciled > 0) {
                $notes[] = "{$unreconciled} day(s) did NOT reconcile to Shopify's store total and will render '—'";
            }
            $note = $notes === [] ? '' : ' — ' . implode('; ', $notes);

            $this->info("· {$brand->name}: {$brandRows} rows across {$brandDays} day(s) ({$since->toDateString()}..{$until->toDateString()}){$note}.");

            $totalRows += $brandRows;
            $totalDays += $brandDays;
        }

        $this->info("Done. {$totalRows} rows upserted across {$totalDays} brand-day(s).");

        return self::SUCCESS;
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
