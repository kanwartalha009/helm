<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Brand;
use App\Platforms\Shopify\ShopifyClient;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * WHERE do the missing sessions go?
 *
 * `session_traffic_daily` reconciles a day two ways: the paged `GROUP BY landing_page_path,
 * traffic_type` sum must equal the cheap `GROUP BY traffic_type` store total. In production the
 * paged sum comes back SHORT — never over — and the shortfall clusters suspiciously tightly
 * around ~500 sessions across brands of wildly different sizes:
 *
 *     brand 4  2025-11-27   store 5,978    paged 5,481     short 497
 *     brand 4  2026-07-07   store 5,709    paged 5,208     short 501
 *     brand 8  2026-07-11   store   826    paged   324     short 502   ← 61% of the day
 *     brand 9  2026-07-12   store 2,204    paged 1,706     short 498
 *     brand 76 2025-11-30   store 307,579  paged 251,266   short 56,313
 *
 * Brand 8 is the key: 826 sessions cannot produce more than 826 rows, so that day NEVER PAGED —
 * one call, and 502 sessions still vanished. Whatever this is, it drops rows inside a single
 * response, so it is not an OFFSET/paging fault.
 *
 * Rather than keep guessing, this reconciles the SAME day BOTH ways and prints the delta BROKEN
 * DOWN BY TRAFFIC TYPE, plus the row-level things that could be eating sessions: blank landing
 * paths, blank traffic types, and rows returned twice across page boundaries. The bucket that is
 * short is the bug.
 *
 *   php artisan shopify:reconcile-session-day 8 --date=2026-07-11
 */
class ShopifyReconcileSessionDayCommand extends Command
{
    protected $signature = 'shopify:reconcile-session-day '
        . '{brand : slug or id} '
        . '{--date= : the day to reconcile (Y-m-d); defaults to yesterday in the brand timezone} '
        . '{--dump=0 : print the N largest rows the paged query returned}';

    protected $description = 'Reconcile one brand-day of session traffic BY TRAFFIC TYPE and name the bucket that is short.';

    /** Must match SessionTrafficFetcher. */
    private const SHOPIFYQL_API_VERSION = '2026-04';
    private const PAGE_SIZE = 1000;
    private const MAX_PAGES = 25;

    public function handle(): int
    {
        $brand = $this->resolveBrand();
        if (! $brand) {
            $this->error('Brand not found.');

            return self::FAILURE;
        }

        $conn = $brand->connections()->where('platform', 'shopify')->where('status', 'active')->first();
        if (! $conn) {
            $this->error("{$brand->name} has no active Shopify connection.");

            return self::FAILURE;
        }

        $tz  = $brand->timezone ?: 'UTC';
        $day = (string) ($this->option('date') ?: CarbonImmutable::now($tz)->subDay()->toDateString());

        $client = new ShopifyClient((string) $conn->external_id, (string) ($conn->credentials['access_token'] ?? ''));

        $this->line("Brand: {$brand->name} (id {$brand->id})   Day: {$day}   tz {$tz}");
        $this->newLine();

        /* ── 1. The store's own truth: GROUP BY traffic_type ─────────────────────────── */

        $storeRows = $this->run($client, "FROM sessions SHOW sessions GROUP BY traffic_type SINCE {$day} UNTIL {$day} LIMIT 50");
        if ($storeRows === null) {
            $this->error('STORE TOTAL QUERY FAILED — this is the `store_total: null` case in the logs.');
            $this->line('That call is 4 rows and cheap, so a failure here is a throttle or a transient. It is worth retrying.');

            return self::FAILURE;
        }

        $storeByType = [];
        foreach ($storeRows as $r) {
            $t = strtolower(trim((string) ($r['traffic_type'] ?? '')));
            $storeByType[$t === '' ? '(blank)' : $t] = (int) round((float) ($r['sessions'] ?? 0));
        }
        $storeTotal = array_sum($storeByType);

        /* ── 2. The paged query, exactly as the fetcher issues it ────────────────────── */

        $pagedByType  = [];
        $blankPathSes = 0;
        $blankPathRow = 0;
        $blankTypeSes = 0;
        $rowCount     = 0;
        $pages        = 0;
        $seen         = [];   // (path\0type) → times returned; catches cross-page duplicates
        $dupSessions  = 0;

        for ($p = 0; $p < self::MAX_PAGES; $p++) {
            $offset = $p * self::PAGE_SIZE;
            $rows   = $this->run(
                $client,
                'FROM sessions SHOW sessions GROUP BY landing_page_path, traffic_type '
                . "SINCE {$day} UNTIL {$day} ORDER BY sessions DESC "
                . 'LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . $offset,
            );

            if ($rows === null) {
                $this->error("PAGE {$p} (offset {$offset}) FAILED — the day cannot be established.");

                return self::FAILURE;
            }

            $pages++;

            foreach ($rows as $r) {
                $rowCount++;

                $path = trim((string) ($r['landing_page_path'] ?? ''));
                $type = strtolower(trim((string) ($r['traffic_type'] ?? '')));
                $ses  = (int) round((float) ($r['sessions'] ?? 0));

                $k = $path . "\0" . $type;
                if (isset($seen[$k])) {
                    $dupSessions += $ses;   // returned on two pages → we'd DOUBLE-count, not lose
                }
                $seen[$k] = true;

                if ($path === '') {
                    $blankPathRow++;
                    $blankPathSes += $ses;   // ← the rows the fetcher used to `continue` past
                }
                if ($type === '') {
                    $blankTypeSes += $ses;
                }

                $key                = $type === '' ? '(blank)' : $type;
                $pagedByType[$key] = ($pagedByType[$key] ?? 0) + $ses;
            }

            if (count($rows) < self::PAGE_SIZE) {
                break;
            }
        }

        $pagedTotal = array_sum($pagedByType);

        /* ── 3. The answer: which BUCKET is short? ───────────────────────────────────── */

        $types = array_unique(array_merge(array_keys($storeByType), array_keys($pagedByType)));
        sort($types);

        $table = [];
        foreach ($types as $t) {
            $s = $storeByType[$t] ?? 0;
            $g = $pagedByType[$t] ?? 0;
            $table[] = [
                $t,
                number_format($s),
                number_format($g),
                ($g - $s > 0 ? '+' : '') . number_format($g - $s),
                $s > 0 ? round(($g - $s) / $s * 100, 1) . '%' : '—',
            ];
        }
        $table[] = ['─────', '─────', '─────', '─────', '─────'];
        $table[] = [
            'TOTAL',
            number_format($storeTotal),
            number_format($pagedTotal),
            ($pagedTotal - $storeTotal > 0 ? '+' : '') . number_format($pagedTotal - $storeTotal),
            $storeTotal > 0 ? round(($pagedTotal - $storeTotal) / $storeTotal * 100, 1) . '%' : '—',
        ];

        $this->table(['traffic_type', 'store (truth)', 'paged', 'delta', 'delta %'], $table);

        $this->newLine();
        $this->line("rows returned          : {$rowCount} across {$pages} page(s)");
        $this->line("blank landing_page_path: {$blankPathRow} row(s), {$blankPathSes} session(s)  ← the fetcher USED to drop these");
        $this->line("blank traffic_type     : {$blankTypeSes} session(s)");
        $this->line("duplicated across pages: {$dupSessions} session(s)  (>0 means OFFSET paging is unstable)");
        $this->newLine();

        /* ── 4. Say what it means, so nobody has to interpret it ─────────────────────── */

        $short = $storeTotal - $pagedTotal;

        if ($short === 0) {
            $this->info('RECONCILED. store total === paged total. This day is clean.');
        } elseif ($short === $blankPathSes) {
            $this->warn("EXPLAINED: short by exactly the blank-landing-path sessions ({$blankPathSes}).");
            $this->line('→ The blank-path fix in SessionTrafficFetcher::page() closes this. Re-pull with --force.');
        } elseif ($short > 0) {
            $this->error("UNEXPLAINED SHORTFALL: {$short} session(s) missing, of which only {$blankPathSes} are blank-path.");
            $this->line('→ The paged GROUP BY is returning fewer sessions than the store total, and it is NOT');
            $this->line('  the blank-path rows. Look at the per-type table above: the type that is short is the');
            $this->line('  bug. A type short by ~100% means that bucket is absent from the two-dimension');
            $this->line('  GROUP BY entirely — i.e. Shopify cannot break it down by landing page, and the');
            $this->line('  paged query can never sum to the store total no matter what we fix in our code.');
        } else {
            $this->error('OVER-COUNT: the paged sum EXCEEDS the store total — OFFSET paging is returning rows twice.');
        }

        $dump = (int) $this->option('dump');
        if ($dump > 0) {
            $this->newLine();
            $this->line("Top {$dump} rows by sessions:");
            $flat = [];
            foreach ($seen as $k => $_) {
                [$path, $type] = explode("\0", (string) $k, 2);
                $flat[] = [$path === '' ? '(BLANK)' : $path, $type];
            }
            foreach (array_slice($flat, 0, $dump) as $row) {
                $this->line('  ' . $row[1] . '  ' . $row[0]);
            }
        }

        return self::SUCCESS;
    }

    /** @return array<int, array<string, mixed>>|null */
    private function run(ShopifyClient $client, string $ql): ?array
    {
        $gql = <<<'GQL'
query ($q: String!) {
  shopifyqlQuery(query: $q) {
    tableData { columns { name } rows }
    parseErrors
  }
}
GQL;

        try {
            $data = $client->graphql($gql, ['q' => $ql], self::SHOPIFYQL_API_VERSION);
        } catch (Throwable $e) {
            $this->error('  request failed: ' . $e->getMessage());

            return null;
        }

        $resp = $data['shopifyqlQuery'] ?? null;
        if (! is_array($resp)) {
            return null;
        }
        if (! empty($resp['parseErrors'])) {
            $this->error('  parseErrors: ' . json_encode($resp['parseErrors']));

            return null;
        }

        $rows = $resp['tableData']['rows'] ?? [];

        return is_array($rows) ? $rows : null;
    }

    private function resolveBrand(): ?Brand
    {
        $arg = (string) $this->argument('brand');

        return is_numeric($arg)
            ? Brand::query()->with('connections')->find((int) $arg)
            : Brand::query()->with('connections')
                ->whereRaw('LOWER(slug) = ?', [strtolower(trim($arg))])
                ->orWhereRaw('LOWER(name) = ?', [strtolower(trim($arg))])
                ->first();
    }
}
