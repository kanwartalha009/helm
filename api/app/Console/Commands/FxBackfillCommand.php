<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Brand;
use App\Services\Currency\FxProvider;
use App\Services\Currency\FxService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Backfill FX rates over a date range. One HTTP call per (base → target)
 * pair thanks to Frankfurter's /{from}..{to} range endpoint.
 *
 * Run before the first Sync now of a brand whose history reaches back
 * months — otherwise FxService falls back to on-demand single-day fetches
 * inside the sync loop, which works but is N×slower.
 *
 *   php artisan fx:backfill --since=2024-01-01
 *   php artisan fx:backfill --since=2025-01-01 --currencies=EUR,GBP
 *   php artisan fx:backfill --since=2025-01-01 --until=2026-05-22
 */
class FxBackfillCommand extends Command
{
    protected $signature = 'fx:backfill
        {--since= : YYYY-MM-DD start of range (required)}
        {--until= : YYYY-MM-DD end of range (defaults to yesterday UTC)}
        {--currencies= : Comma-separated base currencies; defaults to every active brand currency + config(sync.fx.currencies)}
        {--target= : Target currency (defaults to config sync.fx.target, usually USD)}';

    protected $description = 'Backfill historical FX rates from the configured provider into currency_rates.';

    public function handle(FxService $fx, FxProvider $provider): int
    {
        $since = $this->option('since');
        if (! $since) {
            $this->error('--since=YYYY-MM-DD is required.');
            return self::INVALID;
        }

        try {
            $from = CarbonImmutable::parse($since)->startOfDay();
            $to   = $this->option('until')
                ? CarbonImmutable::parse($this->option('until'))->startOfDay()
                : CarbonImmutable::yesterday('UTC');
        } catch (Throwable $e) {
            $this->error('Invalid date: ' . $e->getMessage());
            return self::INVALID;
        }

        if ($from->greaterThan($to)) {
            $this->error("--since ({$from->toDateString()}) is after --until ({$to->toDateString()}).");
            return self::INVALID;
        }

        $target = strtoupper((string) ($this->option('target') ?: config('sync.fx.target', 'USD')));

        $explicit = $this->option('currencies');
        if ($explicit) {
            $bases = array_map(
                fn ($c) => strtoupper(trim($c)),
                explode(',', $explicit)
            );
        } else {
            $brandCurrencies = Brand::query()
                ->where('status', 'active')
                ->pluck('base_currency')
                ->map(fn ($c) => strtoupper((string) $c))
                ->filter()
                ->unique()
                ->all();
            $seeded = array_map('strtoupper', (array) config('sync.fx.currencies', []));
            $bases = array_values(array_unique(array_merge($brandCurrencies, $seeded)));
        }

        $bases = array_values(array_filter($bases, fn ($b) => $b !== '' && $b !== $target));

        // Pegged currencies (AED, SAR, ...) are resolved from a fixed peg by
        // FxService and have no provider feed — skip them so we don't 404.
        $pegged      = array_map('strtoupper', array_keys((array) config('sync.fx.pegs', [])));
        $skippedPegs = array_values(array_intersect($bases, $pegged));
        $bases       = array_values(array_diff($bases, $pegged));
        if ($skippedPegs !== []) {
            $this->line('Skipping pegged currencies (resolved via fixed peg): ' . implode(', ', $skippedPegs));
        }

        if ($bases === []) {
            $this->warn('No base currencies to backfill (every active brand is already in ' . $target . ' or pegged).');
            return self::SUCCESS;
        }

        $this->line("Backfilling {$from->toDateString()} → {$to->toDateString()} into {$target} for: " . implode(', ', $bases));

        $totalRows = 0;
        $errors    = [];
        foreach ($bases as $base) {
            $this->line("  · {$base} → {$target}");
            try {
                $rates = $provider->fetchRange($base, $target, $from, $to);
                $written = $fx->storeRange($base, $target, $rates);
                $totalRows += $written;
                $this->line("    {$written} row(s) written");
            } catch (Throwable $e) {
                $errors[$base] = $e->getMessage();
                $this->error("    failed: " . $e->getMessage());
            }
        }

        $this->line('');
        $this->info("Done. {$totalRows} row(s) written across " . count($bases) . " currency pair(s).");
        if ($errors !== []) {
            $this->warn(count($errors) . " currency(ies) had errors:");
            foreach ($errors as $base => $msg) {
                $this->line("  {$base}: {$msg}");
            }
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
