<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\ShopifyFunnelDaily;
use App\Platforms\Shopify\RevenueFetcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Backfill the Shopify web funnel — sessions → cart → checkout → purchase — BY
 * COUNTRY and BY LANDING PATH into shopify_funnel_daily, for the monthly report's
 * two web-funnel sections (§10 / §11). Mirrors shopify:backfill-commerce: one
 * ShopifyQL `FROM sessions` call per dimension per month, additive upsert keyed
 * on (brand, date, dimension, segment). No currency — these are session counts.
 * The daily sync keeps it fresh; this fills history.
 *
 *   php artisan shopify:backfill-funnel                       # all active brands, since 2025-01-01
 *   php artisan shopify:backfill-funnel "Nude Project"        # one brand
 *   php artisan shopify:backfill-funnel --dimension=landing --since=2026-01-01
 *
 * Dimension names verified live via shopify:diagnose-sessions: session_country +
 * landing_page_path on `FROM sessions`. A wrong name logs a parseError + returns
 * empty (never a fake zero) and is a one-line fix in self::DIMENSIONS.
 */
class ShopifyBackfillFunnelCommand extends Command
{
    protected $signature = 'shopify:backfill-funnel '
        . '{brand? : slug or id; omit for all active brands} '
        . '{--since=2025-01-01 : first day to pull (Y-m-d)} '
        . '{--dimension= : limit to one of country|landing} '
        . '{--missing : only brands/dimensions with NO existing rows}';

    protected $description = 'Backfill the Shopify web funnel by country / landing path into shopify_funnel_daily for the monthly report.';

    /** @var array<string, string> dimension key => ShopifyQL `sessions` GROUP BY field */
    private const DIMENSIONS = [
        'country' => 'session_country',
        'landing' => 'landing_page_path',
    ];

    public function handle(RevenueFetcher $fetcher): int
    {
        $since = (string) $this->option('since');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) {
            $this->error('--since must be a Y-m-d date.');

            return self::FAILURE;
        }

        $dimensions = self::DIMENSIONS;
        $only = $this->option('dimension');
        if ($only !== null && $only !== '') {
            $only = strtolower((string) $only);
            if (! isset(self::DIMENSIONS[$only])) {
                $this->error('--dimension must be one of: ' . implode(', ', array_keys(self::DIMENSIONS)) . '.');

                return self::FAILURE;
            }
            $dimensions = [$only => self::DIMENSIONS[$only]];
        }

        $brands = $this->resolveBrands();
        if ($brands->isEmpty()) {
            $this->warn('No matching brands.');

            return self::SUCCESS;
        }

        $missing   = (bool) $this->option('missing');
        $totalRows = 0;

        foreach ($brands as $brand) {
            $conn = $brand->connections->firstWhere('platform', 'shopify');
            if (! $conn || $conn->status !== 'active') {
                $this->line("· {$brand->name}: no active Shopify connection — skipped.");
                continue;
            }

            $until  = CarbonImmutable::now($brand->timezone ?: 'UTC')->toDateString();
            $months = $this->monthWindows($since, $until);

            foreach ($dimensions as $type => $dim) {
                if ($missing && ShopifyFunnelDaily::query()->where('brand_id', $brand->id)->where('dimension', $type)->exists()) {
                    $this->line("· {$brand->name} [{$type}]: already has data — skipped (--missing).");
                    continue;
                }

                $dimRows = 0;
                $failed  = 0;

                foreach ($months as [$chunkStart, $chunkEnd]) {
                    try {
                        $funnel = $fetcher->funnelByDimensionRange($conn, $dim, $chunkStart, $chunkEnd);
                    } catch (Throwable $e) {
                        $this->error("· {$brand->name} [{$type}] {$chunkStart}: {$e->getMessage()}");
                        $failed++;
                        continue;
                    }

                    if ($funnel === []) {
                        continue;
                    }

                    $records = $this->records($brand->id, $type, $funnel);
                    foreach (array_chunk($records, 500) as $chunk) {
                        ShopifyFunnelDaily::upsert(
                            $chunk,
                            ['brand_id', 'date', 'dimension', 'segment_key'],
                            ['segment_label', 'sessions', 'cart_additions', 'reached_checkout', 'completed_checkout', 'is_complete', 'pulled_at'],
                        );
                    }

                    $dimRows += count($records);
                    usleep(150_000);
                }

                if ($dimRows === 0 && $failed === 0) {
                    $this->line("· {$brand->name} [{$type}]: no rows for {$since}..{$until} (empty or dimension '{$dim}' not recognised — check logs).");
                } else {
                    $note = $failed > 0 ? " — {$failed} month(s) errored, re-run to fill (idempotent)" : '';
                    $this->info("· {$brand->name} [{$type}]: {$dimRows} funnel rows backfilled ({$since}..{$until}){$note}.");
                }

                $totalRows += $dimRows;
            }
        }

        $this->info("Done. {$totalRows} funnel rows upserted across {$brands->count()} brand(s).");

        return self::SUCCESS;
    }

    /**
     * @param array<int, array<string, mixed>> $funnel
     * @return array<int, array<string, mixed>>
     */
    private function records(int $brandId, string $type, array $funnel): array
    {
        $records = [];
        foreach ($funnel as $r) {
            $seg = trim((string) ($r['segment_key'] ?? ''));
            if ($seg === '') {
                continue;
            }
            $records[] = [
                'brand_id'           => $brandId,
                'date'               => (string) $r['date'],
                'dimension'          => $type,
                'segment_key'        => mb_substr($seg, 0, 191),
                'segment_label'      => mb_substr((string) ($r['segment_label'] ?? $seg), 0, 191),
                'sessions'           => (int) ($r['sessions'] ?? 0),
                'cart_additions'     => (int) ($r['cart_additions'] ?? 0),
                'reached_checkout'   => (int) ($r['reached_checkout'] ?? 0),
                'completed_checkout' => (int) ($r['completed_checkout'] ?? 0),
                'is_complete'        => true,
                'pulled_at'          => now(),
            ];
        }

        return $records;
    }

    /** @return array<int, array{0: string, 1: string}> */
    private function monthWindows(string $since, string $until): array
    {
        $cursor = CarbonImmutable::parse($since)->startOfDay();
        $end    = CarbonImmutable::parse($until)->startOfDay();

        $out = [];
        while ($cursor <= $end) {
            $monthEnd = $cursor->endOfMonth()->startOfDay();
            $chunkEnd = $monthEnd > $end ? $end : $monthEnd;
            $out[]    = [$cursor->toDateString(), $chunkEnd->toDateString()];
            $cursor   = $chunkEnd->addDay();
        }

        return $out;
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
