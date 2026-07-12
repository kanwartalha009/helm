<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\PlatformConnection;
use App\Platforms\Shopify\SessionTrafficFetcher;
use App\Platforms\Shopify\ShopifyClient;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Why is `shopify:backfill-session-traffic` returning 0 rows for every brand, every day?
 *
 * A ShopifyQL parse error comes back as an EMPTY TABLE, not an exception. So a malformed query is
 * indistinguishable, downstream, from "this store genuinely had no sessions" — which is how a
 * silent failure looked like a data problem across all 88 brands for 90 consecutive days.
 *
 * This runs a LADDER of queries through the app's own ShopifyClient and prints the raw
 * `parseErrors` for each. The first rung that fails is the answer.
 *
 * RESULT of the first real run (Flabelus, 2026-07-12): ALL SEVEN RUNGS PASS. `traffic_type`
 * exists, both dimensions combine, ORDER BY / LIMIT / OFFSET all work, and clause order does NOT
 * matter to this endpoint. So the ShopifyQL was never the bug — the fault is inside our own
 * fetcher, between the endpoint and the database. That is what `--trace` is for.
 *
 *   php artisan shopify:diagnose-session-traffic flabelus
 *   php artisan shopify:diagnose-session-traffic flabelus --date=2026-07-09
 */
class ShopifyDiagnoseSessionTrafficCommand extends Command
{
    protected $signature = 'shopify:diagnose-session-traffic '
        . '{brand : slug or id} '
        . '{--date= : the day to probe (Y-m-d); defaults to yesterday in the brand timezone} '
        . '{--trace : ALSO run the real SessionTrafficFetcher for this day and print its internals}';

    protected $description = 'Print the raw ShopifyQL response for each part of the session-traffic query, to find which clause the endpoint rejects.';

    /** Must match SessionTrafficFetcher. */
    private const SHOPIFYQL_API_VERSION = '2026-04';

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

        $tz   = $brand->timezone ?: 'UTC';
        $date = (string) ($this->option('date') ?? '');
        if ($date === '') {
            $date = CarbonImmutable::now($tz)->subDay()->toDateString();
        }

        $token = (string) ($conn->credentials['access_token'] ?? '');
        if ($token === '') {
            $this->error('Shopify connection has no access_token.');

            return self::FAILURE;
        }
        $client = new ShopifyClient((string) $conn->external_id, $token);

        $this->line("Brand: {$brand->name}  ·  day: {$date}  ·  ShopifyQL API " . self::SHOPIFYQL_API_VERSION);
        $this->newLine();

        // Each rung adds ONE thing. The first failure names the culprit.
        $ladder = [
            '1. baseline (does FROM sessions work at all?)'
                => "FROM sessions SHOW sessions SINCE {$date} UNTIL {$date}",

            '2. + GROUP BY traffic_type (does the dimension EXIST?)'
                => "FROM sessions SHOW sessions GROUP BY traffic_type SINCE {$date} UNTIL {$date}",

            '3. + GROUP BY landing_page_path (proven by the funnel sync)'
                => "FROM sessions SHOW sessions GROUP BY landing_page_path SINCE {$date} UNTIL {$date}",

            '4. + BOTH dimensions (do they COMBINE on this endpoint?)'
                => "FROM sessions SHOW sessions GROUP BY landing_page_path, traffic_type SINCE {$date} UNTIL {$date}",

            '5. + ORDER BY / LIMIT — clauses AFTER since/until (the fixed order)'
                => "FROM sessions SHOW sessions GROUP BY landing_page_path, traffic_type SINCE {$date} UNTIL {$date} ORDER BY sessions DESC LIMIT 5",

            '6. + OFFSET — PAGINATION DEPENDS ON THIS'
                => "FROM sessions SHOW sessions GROUP BY landing_page_path, traffic_type SINCE {$date} UNTIL {$date} ORDER BY sessions DESC LIMIT 5 OFFSET 5",

            '7. limit/offset BEFORE since (the original order — VERIFIED FINE 2026-07-12)'
                => "FROM sessions SHOW sessions GROUP BY landing_page_path, traffic_type ORDER BY sessions DESC LIMIT 5 OFFSET 0 SINCE {$date} UNTIL {$date}",
        ];

        foreach ($ladder as $label => $ql) {
            $this->line($label);
            $this->line('   ' . $ql);
            $this->probe($client, $ql);
            $this->newLine();
        }

        $this->line('──────────────────────────────────────────────');
        $this->info('Read it top-down: the FIRST rung that errors is the cause.');
        $this->info('If EVERY rung passes, the ShopifyQL is fine and the fault is in our own');
        $this->info('fetcher — re-run with --trace to drive it for real.');

        if ((bool) $this->option('trace')) {
            $this->newLine();
            $this->trace($conn, $date);
        }

        return self::SUCCESS;
    }

    /**
     * Drive the ACTUAL SessionTrafficFetcher for this brand-day and print what it saw.
     *
     * When every raw query above succeeds but the backfill still writes nothing, the fault is
     * between the endpoint and the database — and no amount of staring at the ShopifyQL will find
     * it. This runs the real code path and shows the three numbers that decide the outcome:
     * the store total we reconcile against, the sum of the paged rows, and how many aggregated
     * rows survived. One of them is zero for a reason.
     */
    private function trace(PlatformConnection $conn, string $date): void
    {
        $this->line('── TRACE: running SessionTrafficFetcher::fetchDay() for real ──');

        try {
            $result = app(SessionTrafficFetcher::class)->fetchDay($conn, $date);
        } catch (Throwable $e) {
            $this->error('   ✗ fetchDay() THREW: ' . $e::class . ' — ' . $e->getMessage());
            $this->line('     ' . $e->getFile() . ':' . $e->getLine());

            return;
        }

        $this->line('   storeTotal  : ' . var_export($result['storeTotal'], true)
            . ($result['storeTotal'] === null ? '   ← the reconciliation TOTALS call failed' : ''));
        $this->line('   pagedTotal  : ' . $result['pagedTotal']
            . ($result['pagedTotal'] === 0 ? '   ← the PAGE call returned no usable rows' : ''));
        $this->line('   isComplete  : ' . var_export($result['isComplete'], true));
        $this->line('   rows        : ' . count($result['rows']));

        foreach (array_slice($result['rows'], 0, 5) as $r) {
            $this->line('     ' . json_encode($r));
        }

        if ($result['rows'] === []) {
            $this->newLine();
            $this->error('   ✗ ZERO aggregated rows — this is the backfill bug, reproduced.');
            $this->warn('   Check the log for the exact cause:');
            $this->line('     tail -n 200 storage/logs/laravel.log | grep session_traffic');
        } else {
            $this->info('   ✓ the fetcher works for this brand-day.');
        }
    }

    private function probe(ShopifyClient $client, string $ql): void
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
            $this->error('   ✗ TRANSPORT FAILURE — ' . $e->getMessage());

            return;
        }

        $resp = $data['shopifyqlQuery'] ?? null;
        if (! is_array($resp)) {
            $this->error('   ✗ no shopifyqlQuery in the response: ' . json_encode($data));

            return;
        }

        if (! empty($resp['parseErrors'])) {
            $this->error('   ✗ PARSE ERROR: ' . json_encode($resp['parseErrors']));

            return;
        }

        $columns = $resp['tableData']['columns'] ?? [];
        $rows    = $resp['tableData']['rows'] ?? [];
        $names   = implode(', ', array_map(static fn ($c): string => (string) ($c['name'] ?? '?'), is_array($columns) ? $columns : []));
        $count   = is_array($rows) ? count($rows) : 0;

        // 0 rows with NO parse error is its own finding: the query is valid and the store really
        // has nothing. Say so explicitly — that distinction is the entire point of this command.
        if ($count === 0) {
            $this->warn("   ⚠ valid query, but 0 rows returned (columns: {$names}) — the store genuinely has no data for this day, OR the dimension is silently unsupported here.");

            return;
        }

        $this->info("   ✓ {$count} row(s) · columns: {$names}");
        foreach (array_slice(is_array($rows) ? $rows : [], 0, 3) as $r) {
            $this->line('     ' . json_encode($r));
        }
    }

    private function resolveBrand(): ?Brand
    {
        $arg = (string) $this->argument('brand');

        if (is_numeric($arg)) {
            return Brand::query()->with('connections')->find((int) $arg);
        }

        $lower = strtolower(trim($arg));

        return Brand::query()->with('connections')
            ->whereRaw('LOWER(slug) = ?', [$lower])
            ->orWhereRaw('LOWER(name) = ?', [$lower])
            ->first()
            ?: Brand::query()->with('connections')
                ->where('name', 'like', '%' . $arg . '%')
                ->orWhere('slug', 'like', '%' . $arg . '%')
                ->first();
    }
}
