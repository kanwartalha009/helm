<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DailyMetric;
use App\Services\Currency\FxService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One-time / re-runnable correction of fx_rate_to_usd on EXISTING
 * daily_metrics rows.
 *
 * Before USD was wired into sync, every row was written with
 * fx_rate_to_usd = 1.0 (currency conversion was off in Phase 1). For
 * non-USD brands that 1.0 is wrong — EUR revenue would read as USD in the
 * dashboard's USD view. This command recomputes the correct native->USD
 * rate per (currency, date) from currency_rates and stamps it.
 *
 * Intended run order (rates first, then a dry run, then apply):
 *   php artisan fx:backfill --since=2024-01-01   # fill currency_rates
 *   php artisan fx:apply --dry                   # preview, writes nothing
 *   php artisan fx:apply                          # apply for real
 *
 * Safe + idempotent: only fx_rate_to_usd is written and the fx_pending /
 * fx_error metadata flags are cleared. Native revenue / orders / refunds are
 * never touched, and rows already holding the correct rate are skipped, so
 * re-running converges.
 */
class FxApplyCommand extends Command
{
    protected $signature = 'fx:apply
        {--dry : Report what would change without writing a single row}
        {--currencies= : Comma-separated currencies to limit to (default: every non-target currency present)}
        {--chunk=500 : Rows per batch}';

    protected $description = 'Recompute fx_rate_to_usd on existing daily_metrics rows from cached currency_rates.';

    public function handle(FxService $fx): int
    {
        $target = strtoupper((string) config('sync.fx.target', 'USD'));
        $dry    = (bool) $this->option('dry');
        $chunk  = max(1, (int) $this->option('chunk'));

        $query = DailyMetric::query()
            ->whereNotNull('currency')
            ->whereRaw('UPPER(currency) <> ?', [$target]);

        if ($explicit = $this->option('currencies')) {
            $list = array_values(array_filter(array_map(
                fn ($c) => strtoupper(trim($c)),
                explode(',', $explicit)
            )));
            if ($list !== []) {
                $placeholders = implode(',', array_fill(0, count($list), '?'));
                $query->whereRaw("UPPER(currency) IN ({$placeholders})", $list);
            }
        }

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->info("No non-{$target} rows to process. Nothing to do.");
            return self::SUCCESS;
        }

        $this->line(($dry ? '[DRY RUN] ' : '') . "Scanning {$total} non-{$target} row(s)…");

        $updated     = 0;
        $unchanged   = 0;
        $unresolved  = 0;
        $perCurrency = [];

        // chunkById (not chunk) so updating rows mid-iteration can't shift the
        // cursor — we only ever advance past ids we've already handled.
        $query->orderBy('id')->chunkById($chunk, function ($batch) use (
            $fx, $dry, &$updated, &$unchanged, &$unresolved, &$perCurrency
        ): void {
            foreach ($batch as $row) {
                $currency = strtoupper((string) $row->currency);

                try {
                    // toUsd reads currency_rates first and only falls back to
                    // the provider for a genuinely missing date, so after a
                    // fx:backfill this loop is almost entirely DB reads.
                    $rate = $fx->toUsd($currency, $row->date->toImmutable());
                } catch (Throwable $e) {
                    $unresolved++;
                    $perCurrency[$currency]['unresolved'] = ($perCurrency[$currency]['unresolved'] ?? 0) + 1;
                    continue;
                }

                $current = $row->fx_rate_to_usd !== null ? (float) $row->fx_rate_to_usd : null;
                if ($current !== null && abs($current - $rate) < 0.0000005) {
                    $unchanged++;
                    continue;
                }

                $perCurrency[$currency]['updated'] = ($perCurrency[$currency]['updated'] ?? 0) + 1;
                $updated++;

                if ($dry) {
                    continue;
                }

                $meta = $row->metadata ?? [];
                unset($meta['fx_pending'], $meta['fx_error']);

                // Direct update — no model events, no cast roundtrip. Only the
                // FX columns change; native facts are left exactly as synced.
                DB::table('daily_metrics')
                    ->where('id', $row->id)
                    ->update([
                        'fx_rate_to_usd' => $rate,
                        'metadata'       => $meta === [] ? null : json_encode($meta),
                    ]);
            }
        });

        $this->newLine();
        if ($perCurrency !== []) {
            $this->table(
                ['Currency', $dry ? 'Would update' : 'Updated', 'Unresolved'],
                collect($perCurrency)
                    ->map(fn (array $v, string $k) => [$k, $v['updated'] ?? 0, $v['unresolved'] ?? 0])
                    ->values()
                    ->all()
            );
        }

        $verb = $dry ? 'Would update' : 'Updated';
        $this->info("{$verb} {$updated} row(s). {$unchanged} already correct. {$unresolved} unresolved (no cached rate — run fx:backfill for those dates).");

        if ($dry) {
            $this->warn('Dry run — no rows were written. Re-run without --dry to apply.');
        }

        return $unresolved > 0 ? self::FAILURE : self::SUCCESS;
    }
}
