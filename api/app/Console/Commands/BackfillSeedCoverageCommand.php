<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Brand;
use App\Support\BackfillCoverage;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Back-populate `backfill_coverage` from the rows that ALREADY exist.
 *
 * Run this ONCE, before the first resumed backfill. Without it the coverage ledger is empty, the
 * resume logic concludes nothing has been done, and the hours already spent are repeated in full.
 *
 * ══ HOW A COMPLETED DAY IS INFERRED — AND WHY NOT MIN..MAX ══
 * The obvious approach, marking the whole span MIN(date)..MAX(date), is WRONG here and was fixed
 * on 2026-07-12 after it shipped. It assumes the rows on file are one contiguous block written by
 * the backfill. They are not: the DAILY SYNC writes to most of these same tables
 * (daily_metrics, commerce_daily_metrics, shopify_funnel_daily, ad_set_daily_metrics,
 * ad_product_daily, session_traffic_daily). So a brand whose history backfill died partway has
 * TWO islands of rows —
 *
 *     [ backfilled prefix 2025-01-01 .. 2025-06-30 ]  … HOLE …  [ daily sync 2026-06-01 .. now ]
 *
 * — and MIN..MAX bridges straight over the hole, marking eleven months that were never pulled as
 * DONE. Permanently, and invisibly, because a missing row and a genuinely-zero day look identical.
 *
 * So we mark ISLANDS instead: contiguous runs of days that have rows, merged across gaps of at
 * most `--max-gap` days. A short gap inside a pulled stretch is a real empty day (a quiet Sunday,
 * a brief pause) and is bridged. A long gap is treated as NOT PULLED and left pending.
 *
 * The trade is deliberately asymmetric: bridging a gap we shouldn't loses data forever; refusing
 * to bridge one we could costs a single re-fetch. So the default is conservative.
 *
 *   php artisan backfill:seed-coverage --dry-run          # show what WOULD be marked
 *   php artisan backfill:seed-coverage --reset            # wipe a bad ledger and re-seed
 *   php artisan backfill:seed-coverage --max-gap=14       # bridge longer quiet stretches
 *   php artisan backfill:seed-coverage --strict           # only days with rows; never bridges
 */
class BackfillSeedCoverageCommand extends Command
{
    protected $signature = 'backfill:seed-coverage '
        . '{brand? : slug or id; omit for all brands} '
        . '{--dataset= : limit to one dataset key} '
        . '{--max-gap=7 : bridge gaps of at most N missing days inside a pulled stretch; longer gaps stay PENDING} '
        . '{--strict : mark ONLY days that have rows (never bridges; re-fetches every empty day once)} '
        . '{--reset : delete the existing coverage ledger first — use after a bad seed} '
        . '{--dry-run : print what would be marked, write nothing}';

    protected $description = 'Record already-backfilled days in backfill_coverage so resumed backfills skip them.';

    /**
     * dataset key => [table, scope column (null = single scope ''), scope values to enumerate]
     *
     * `scope` mirrors what the command itself iterates, so a resumed command asks the same
     * question the seeder answered.
     *
     * @var array<string, array{table: string, scopeColumn: ?string}>
     */
    private const DATASETS = [
        // shopify:backfill-sales — daily_metrics rows for the shopify platform.
        'sales'           => ['table' => 'daily_metrics',             'scopeColumn' => null,             'platform' => 'shopify'],
        // ads:backfill-spend — daily_metrics rows per ad platform.
        'ad_spend'        => ['table' => 'daily_metrics',             'scopeColumn' => 'platform',       'exclude'  => 'shopify'],
        'commerce'        => ['table' => 'commerce_daily_metrics',    'scopeColumn' => 'dimension_type'],
        'funnel'          => ['table' => 'shopify_funnel_daily',      'scopeColumn' => 'dimension'],
        'campaigns'       => ['table' => 'ad_campaign_daily_metrics', 'scopeColumn' => 'platform'],
        'adsets'          => ['table' => 'ad_set_daily_metrics',      'scopeColumn' => 'platform'],
        'ad_products'     => ['table' => 'ad_product_daily',          'scopeColumn' => 'platform'],
        'creatives'       => ['table' => 'ad_creative_daily',         'scopeColumn' => 'platform'],
        'breakdowns'      => ['table' => 'meta_breakdown_daily',      'scopeColumn' => 'platform'],
        'email'           => ['table' => 'email_daily_metrics',       'scopeColumn' => null],
        'session_traffic' => ['table' => 'session_traffic_daily',     'scopeColumn' => null],
    ];

    public function handle(BackfillCoverage $coverage): int
    {
        $only = (string) ($this->option('dataset') ?? '');
        if ($only !== '' && ! isset(self::DATASETS[$only])) {
            $this->error('--dataset must be one of: ' . implode(', ', array_keys(self::DATASETS)));

            return self::FAILURE;
        }

        $strict = (bool) $this->option('strict');
        $dry    = (bool) $this->option('dry-run');
        $reset  = (bool) $this->option('reset');
        $maxGap = max(0, (int) $this->option('max-gap'));

        $brands = $this->resolveBrands();
        if ($brands->isEmpty()) {
            $this->warn('No matching brands.');

            return self::SUCCESS;
        }

        if ($reset && ! $dry) {
            $deleted = DB::table('backfill_coverage')->delete();
            $this->warn("Reset: deleted {$deleted} existing coverage row(s).");
        }

        $datasets = $only !== '' ? [$only => self::DATASETS[$only]] : self::DATASETS;

        $this->line($dry ? 'DRY RUN — nothing will be written.' : '');
        $this->line($strict
            ? 'Strict mode: only days that have rows. Nothing is bridged.'
            : "Island mode: contiguous runs of pulled days, bridging gaps of at most {$maxGap} day(s).");
        $this->line('A LONGER gap is treated as never-pulled and left PENDING — that is the point.');
        $this->newLine();

        $totalDays = 0;
        /** @var array<int, string> $holes */
        $holes = [];

        foreach ($datasets as $key => $meta) {
            $table       = (string) $meta['table'];
            $scopeColumn = $meta['scopeColumn'] ?? null;
            $datasetDays = 0;

            foreach ($brands as $brand) {
                $base = DB::table($table)->where('brand_id', $brand->id);

                // `sales` and `ad_spend` share daily_metrics — separate them by platform, or the
                // two datasets would each claim the other's days.
                if (isset($meta['platform'])) {
                    $base->where('platform', $meta['platform']);
                }
                if (isset($meta['exclude'])) {
                    $base->where('platform', '!=', $meta['exclude']);
                }

                // Every scope value this brand actually has rows for.
                $scopes = $scopeColumn === null
                    ? ['']
                    : (clone $base)->distinct()->pluck($scopeColumn)
                        ->filter(static fn ($s): bool => $s !== null && (string) $s !== '')
                        ->map(static fn ($s): string => (string) $s)
                        ->all();

                foreach ($scopes as $scope) {
                    $q = clone $base;
                    if ($scopeColumn !== null && $scope !== '') {
                        $q->where($scopeColumn, $scope);
                    }

                    // The days this brand actually HAS rows for.
                    $days = (clone $q)->distinct()->orderBy('date')->pluck('date')
                        ->map(static fn ($d): string => CarbonImmutable::parse((string) $d)->toDateString())
                        ->unique()
                        ->values()
                        ->all();

                    if ($days === []) {
                        continue;   // no rows at all → claim nothing
                    }

                    // Islands of contiguous coverage. `--strict` bridges nothing (maxGap 0), so
                    // only days with rows are claimed and every empty day is re-fetched once.
                    $islands = $this->islands($days, $strict ? 0 : $maxGap);

                    foreach ($islands as [$lo, $hi]) {
                        $n = (int) CarbonImmutable::parse($lo)->diffInDays(CarbonImmutable::parse($hi)) + 1;

                        if (! $dry) {
                            $coverage->mark($brand->id, $key, $scope, $lo, $hi, 1);
                        }
                        $datasetDays += $n;
                    }

                    // More than one island means a genuine HOLE was found and left pending —
                    // exactly the months a naive MIN..MAX would have claimed and lost.
                    if (count($islands) > 1) {
                        $holes[] = sprintf(
                            '%s [%s]: %d islands, %s',
                            $brand->name,
                            $scope === '' ? $key : "{$key}/{$scope}",
                            count($islands),
                            implode(' + ', array_map(static fn (array $i): string => "{$i[0]}..{$i[1]}", $islands)),
                        );
                    }

                    if ($this->output->isVerbose()) {
                        $label = $scope === '' ? $key : "{$key}/{$scope}";
                        foreach ($islands as [$lo, $hi]) {
                            $this->line("  · {$brand->name} [{$label}]: {$lo}..{$hi}");
                        }
                    }
                }
            }

            if ($datasetDays > 0) {
                $this->info(sprintf('· %-16s %s brand-days marked', $key, number_format($datasetDays)));
            } else {
                $this->line(sprintf('· %-16s no rows yet — nothing to mark', $key));
            }

            $totalDays += $datasetDays;
        }

        $this->newLine();
        $this->info(($dry ? 'Would mark ' : 'Marked ') . number_format($totalDays) . ' brand-days across ' . $brands->count() . ' brand(s).');

        if ($holes !== []) {
            $this->newLine();
            $this->warn(count($holes) . ' brand/dataset(s) have a REAL GAP in their history — left pending, so the next');
            $this->warn('backfill run will fill exactly those windows and nothing else:');
            foreach (array_slice($holes, 0, 30) as $h) {
                $this->line("  · {$h}");
            }
            if (count($holes) > 30) {
                $this->line('  … and ' . (count($holes) - 30) . ' more (re-run with -v for the full list).');
            }
        }

        if (! $dry) {
            $this->newLine();
            $this->line('Resumed backfills will now skip the marked days. Re-pull a window with --force.');
        }

        return self::SUCCESS;
    }

    /**
     * Group days-with-rows into contiguous islands, bridging gaps of at most $maxGap MISSING days.
     *
     * A short gap inside a stretch we clearly pulled is a genuinely empty day — a quiet Sunday, a
     * brief ad pause — and re-fetching it forever is pure waste. A long gap is the signature of a
     * backfill that DIED, with the daily sync later depositing a second island of recent rows far
     * to the right of it. Bridging that would mark months we never pulled as done, permanently.
     *
     * @param array<int, string> $days ascending, unique, Y-m-d
     * @return array<int, array{0: string, 1: string}>
     */
    private function islands(array $days, int $maxGap): array
    {
        $islands = [];
        $start   = $days[0];
        $prev    = $days[0];

        for ($i = 1, $n = count($days); $i < $n; $i++) {
            $cur     = $days[$i];
            $missing = (int) CarbonImmutable::parse($prev)->diffInDays(CarbonImmutable::parse($cur)) - 1;

            if ($missing <= $maxGap) {
                $prev = $cur;   // close enough — same island
                continue;
            }

            $islands[] = [$start, $prev];   // the gap is too big to vouch for
            $start     = $cur;
            $prev      = $cur;
        }

        $islands[] = [$start, $prev];

        return $islands;
    }

    /** @return \Illuminate\Support\Collection<int, Brand> */
    private function resolveBrands(): \Illuminate\Support\Collection
    {
        $arg = $this->argument('brand');
        if ($arg === null) {
            return Brand::query()->orderBy('name')->get();
        }

        $argStr = (string) $arg;
        $lower  = strtolower(trim($argStr));

        $brand = is_numeric($argStr)
            ? Brand::query()->find((int) $argStr)
            : (Brand::query()->whereRaw('LOWER(slug) = ?', [$lower])->orWhereRaw('LOWER(name) = ?', [$lower])->first()
                ?: Brand::query()->where('name', 'like', '%' . $argStr . '%')->orWhere('slug', 'like', '%' . $argStr . '%')->first());

        return collect($brand ? [$brand] : []);
    }
}
