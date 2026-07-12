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
 * ══ HOW A COMPLETED DAY IS INFERRED ══
 * Every backfill walks its window in ASCENDING date order and writes as it goes, so for a given
 * (brand, dataset, scope) the rows it managed to write form a PREFIX: everything from the first
 * day it wrote up to the last. Days inside that range with no rows were fetched and legitimately
 * returned nothing (a paused ad account, a quiet Sunday) — they are DONE, not missing. So the
 * whole span MIN(date)..MAX(date) is marked covered.
 *
 * The honest caveat: if a single day inside that span failed with a logged error and the command
 * carried on (they all do — a hiccup must never kill an 18-month run), this marks it done and no
 * future run will retry it. That is the price of not re-pulling every genuinely-empty day forever.
 * `--strict` takes the other trade: it marks ONLY days that actually have rows, so nothing is ever
 * wrongly claimed — at the cost of re-fetching every empty day once more.
 *
 *   php artisan backfill:seed-coverage --dry-run    # show what WOULD be marked, write nothing
 *   php artisan backfill:seed-coverage              # span mode (recommended)
 *   php artisan backfill:seed-coverage --strict     # only days with actual rows
 */
class BackfillSeedCoverageCommand extends Command
{
    protected $signature = 'backfill:seed-coverage '
        . '{brand? : slug or id; omit for all brands} '
        . '{--dataset= : limit to one dataset key} '
        . '{--strict : mark ONLY days that have rows (re-fetches empty days once; never over-claims)} '
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

        $brands = $this->resolveBrands();
        if ($brands->isEmpty()) {
            $this->warn('No matching brands.');

            return self::SUCCESS;
        }

        $datasets = $only !== '' ? [$only => self::DATASETS[$only]] : self::DATASETS;
        $this->line($dry ? 'DRY RUN — nothing will be written.' : ($strict ? 'Strict mode: only days with rows.' : 'Span mode: MIN..MAX per brand/dataset/scope.'));
        $this->newLine();

        $totalDays = 0;

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

                    if ($strict) {
                        $days = (clone $q)->distinct()->pluck('date')
                            ->map(static fn ($d): string => CarbonImmutable::parse((string) $d)->toDateString())
                            ->all();
                        foreach ($days as $d) {
                            if (! $dry) {
                                $coverage->mark($brand->id, $key, $scope, $d, $d, 1);
                            }
                        }
                        $datasetDays += count($days);
                        continue;
                    }

                    $span = (clone $q)->selectRaw('MIN(date) AS lo, MAX(date) AS hi')->first();
                    if (! $span || ! $span->lo || ! $span->hi) {
                        continue;   // no rows at all → nothing to claim
                    }

                    $lo = CarbonImmutable::parse((string) $span->lo)->toDateString();
                    $hi = CarbonImmutable::parse((string) $span->hi)->toDateString();
                    $n  = (int) CarbonImmutable::parse($lo)->diffInDays(CarbonImmutable::parse($hi)) + 1;

                    if (! $dry) {
                        $coverage->mark($brand->id, $key, $scope, $lo, $hi, 1);
                    }
                    $datasetDays += $n;

                    if ($this->output->isVerbose()) {
                        $label = $scope === '' ? $key : "{$key}/{$scope}";
                        $this->line("  · {$brand->name} [{$label}]: {$lo}..{$hi} ({$n} days)");
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
        if (! $dry) {
            $this->line('Resumed backfills will now skip these days. Re-pull a window with --force.');
        }

        return self::SUCCESS;
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
