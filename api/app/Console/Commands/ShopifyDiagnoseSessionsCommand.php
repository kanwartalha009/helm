<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\PlatformConnection;
use App\Platforms\Shopify\ShopifyClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only probe: does ShopifyQL expose a web funnel — sessions → add to cart →
 * reached checkout → purchase — split by COUNTRY and by LANDING PATH? This gates
 * the monthly report's two web-funnel sections (§10 country, §11 landing path),
 * which have no data in Helm today (Kanwar chose ShopifyQL over GA4 as the source
 * but it was never verified). Same shape as shopify:diagnose-customer-type: fire
 * candidate queries, print each one's columns / first rows / parseErrors.
 *
 * The winners are the candidates with EMPTY parseErrors that carry (a) the funnel
 * metrics and (b) a country dimension AND a landing-path dimension. If the
 * `sessions` dataset doesn't exist or has no funnel/geo/landing fields, ShopifyQL
 * can't drive these sections and they need GA4 instead — which is exactly the
 * decision this probe informs before any build.
 *
 * shopifyqlQuery reads analytics data — the token needs read_reports. An "access
 * denied" below is a SCOPE problem, not bad syntax.
 *
 *   php artisan shopify:diagnose-sessions flabelus
 *   php artisan shopify:diagnose-sessions "Nude Project" --days=60
 */
class ShopifyDiagnoseSessionsCommand extends Command
{
    protected $signature = 'shopify:diagnose-sessions {brand : brand slug or id} {--days=30 : window size in days}';
    protected $description = 'Probe ShopifyQL for a web funnel (sessions→cart→checkout→purchase) by country + landing path (gates the monthly report web-funnel sections). Read-only.';

    public function handle(): int
    {
        $brand = $this->resolveBrand((string) $this->argument('brand'));
        if (! $brand) {
            return self::FAILURE;
        }

        $conn = PlatformConnection::query()
            ->where('brand_id', $brand->id)
            ->where('platform', 'shopify')
            ->first();

        $token = (string) ($conn->credentials['access_token'] ?? '');
        if (! $conn || $token === '') {
            $this->error("No usable Shopify connection for {$brand->name}.");

            return self::FAILURE;
        }

        $client = new ShopifyClient((string) $conn->external_id, $token);

        $days  = max(1, (int) $this->option('days'));
        $since = "-{$days}d";

        $this->info("Probing ShopifyQL web funnel — {$brand->name}, last {$days} days");
        $this->line('shopifyqlQuery reads analytics data: the token needs read_reports.');
        $this->line('An "access denied" below is a SCOPE problem, not bad syntax.');
        $this->newLine();

        // A — the FULL funnel metrics (corrected names from Shopify's schema).
        // Expected to pass; the columns list confirms the exact stage names.
        $this->probe($client, 'A. Full funnel metrics',
            "FROM sessions SHOW sessions, sessions_with_cart_additions, sessions_that_reached_checkout, sessions_that_completed_checkout, conversion_rate SINCE {$since} UNTIL today");

        // B — §11 landing path: confirmed dim (landing_page_path) × the funnel.
        $this->probe($client, 'B. §11 — by LANDING PATH (landing_page_path)',
            "FROM sessions SHOW landing_page_path, sessions, sessions_with_cart_additions, sessions_that_reached_checkout, sessions_that_completed_checkout GROUP BY landing_page_path SINCE {$since} UNTIL today");

        $this->probe($client, 'C. §11 alt — by landing_page_type',
            "FROM sessions SHOW landing_page_type, sessions, sessions_that_completed_checkout GROUP BY landing_page_type SINCE {$since} UNTIL today");

        // D–H — §10 COUNTRY dimension: the one still unknown. Candidate names;
        // the one with empty parseErrors is what the country funnel groups by.
        // If NONE pass, the sessions dataset has no geo → §10 needs GA4 (§11 is
        // unaffected — landing path works).
        $this->probe($client, 'D. §10 country (cand 1) — visitor_location',
            "FROM sessions SHOW visitor_location, sessions, sessions_that_completed_checkout GROUP BY visitor_location SINCE {$since} UNTIL today");

        $this->probe($client, 'E. §10 country (cand 2) — location',
            "FROM sessions SHOW location, sessions GROUP BY location SINCE {$since} UNTIL today");

        $this->probe($client, 'F. §10 country (cand 3) — country_code',
            "FROM sessions SHOW country_code, sessions GROUP BY country_code SINCE {$since} UNTIL today");

        $this->probe($client, 'G. §10 country (cand 4) — session_country',
            "FROM sessions SHOW session_country, sessions GROUP BY session_country SINCE {$since} UNTIL today");

        $this->probe($client, 'H. §10 country (cand 5) — visitor_country',
            "FROM sessions SHOW visitor_country, sessions GROUP BY visitor_country SINCE {$since} UNTIL today");

        $this->newLine();
        $this->line('Verdict: A confirms the funnel stage names; B confirms §11 (landing path) — both');
        $this->line('expected to pass. The open question is §10: which of D–H returns a COUNTRY');
        $this->line('dimension with empty parseErrors. If none pass, ShopifyQL sessions has no geo →');
        $this->line('§10 needs GA4; §11 (landing funnel) can be built regardless.');

        return self::SUCCESS;
    }

    private function probe(ShopifyClient $client, string $label, string $query): void
    {
        $this->line("── {$label}");
        $this->line("   {$query}");

        try {
            $sqlGql = <<<'GQL'
query ($q: String!) {
  shopifyqlQuery(query: $q) {
    tableData { columns { name } rows }
    parseErrors
  }
}
GQL;
            $res  = $client->graphql($sqlGql, ['q' => $query], '2026-04');
            $resp = $res['shopifyqlQuery'] ?? null;

            if (! $resp) {
                $this->warn('   → no response');
            } elseif (! empty($resp['parseErrors'])) {
                $pe = $resp['parseErrors'];
                $this->warn('   → parseErrors: ' . (is_array($pe) ? json_encode($pe) : (string) $pe));
            } else {
                $cols = array_map(static fn ($c) => $c['name'] ?? '?', $resp['tableData']['columns'] ?? []);
                $rows = $resp['tableData']['rows'] ?? [];
                $this->info('   → OK · columns: ' . implode(', ', $cols));
                if ($rows === []) {
                    $this->line('     (valid query, but no rows in this window)');
                }
                foreach (array_slice($rows, 0, 6) as $r) {
                    $this->line('     ' . json_encode($r));
                }
            }
        } catch (Throwable $e) {
            $this->warn('   → error: ' . $e->getMessage());
        }

        $this->newLine();
    }

    private function resolveBrand(string $arg): ?Brand
    {
        $lower = strtolower(trim($arg));

        $brand = is_numeric($arg)
            ? Brand::find((int) $arg)
            : (Brand::query()
                ->whereRaw('LOWER(slug) = ?', [$lower])
                ->orWhereRaw('LOWER(name) = ?', [$lower])
                ->first()
                ?: Brand::query()
                    ->where('name', 'like', '%' . $arg . '%')
                    ->orWhere('slug', 'like', '%' . $arg . '%')
                    ->first());

        if (! $brand) {
            $this->error("Brand not found: {$arg}");
            $hints = Brand::query()
                ->where('name', 'like', '%' . $arg . '%')
                ->orWhere('slug', 'like', '%' . $arg . '%')
                ->limit(10)
                ->get(['name', 'slug']);
            foreach ($hints as $h) {
                $this->line("  - {$h->name}  (slug: {$h->slug})");
            }
        }

        return $brand;
    }
}
