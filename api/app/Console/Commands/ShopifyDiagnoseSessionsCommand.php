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

        // A — does the sessions dataset exist + return at all?
        $this->probe($client, 'A. Sanity — sessions + visitors, no breakdown',
            "FROM sessions SHOW sessions, visitors SINCE {$since} UNTIL today");

        // B–C — the funnel metrics: candidate names for add-to-cart / checkout /
        // converted sessions. The one with empty parseErrors names the fields.
        $this->probe($client, 'B. Funnel metrics (candidate 1)',
            "FROM sessions SHOW sessions, sessions_that_added_to_cart, sessions_that_reached_checkout, sessions_converted SINCE {$since} UNTIL today");

        $this->probe($client, 'C. Funnel metrics (candidate 2)',
            "FROM sessions SHOW sessions, added_to_cart_sessions, reached_checkout_sessions, converted_sessions SINCE {$since} UNTIL today");

        $this->probe($client, 'D. Conversion metrics — rate + converted',
            "FROM sessions SHOW sessions, session_conversion_rate, sessions_converted SINCE {$since} UNTIL today");

        // E–F — the COUNTRY dimension (§10). Candidate dimension names.
        $this->probe($client, 'E. By COUNTRY (candidate 1) — visitor_location_country',
            "FROM sessions SHOW visitor_location_country, sessions, sessions_converted GROUP BY visitor_location_country SINCE {$since} UNTIL today");

        $this->probe($client, 'F. By COUNTRY (candidate 2) — country',
            "FROM sessions SHOW country, sessions GROUP BY country SINCE {$since} UNTIL today");

        // G–H — the LANDING PATH dimension (§11). Candidate dimension names.
        $this->probe($client, 'G. By LANDING PATH (candidate 1) — landing_page_path',
            "FROM sessions SHOW landing_page_path, sessions, sessions_converted GROUP BY landing_page_path SINCE {$since} UNTIL today");

        $this->probe($client, 'H. By LANDING PATH (candidate 2) — landing_page',
            "FROM sessions SHOW landing_page, sessions GROUP BY landing_page SINCE {$since} UNTIL today");

        $this->newLine();
        $this->line('Verdict: §10 needs one COUNTRY candidate (E/F) + the funnel metrics (B/C/D) to pass;');
        $this->line('§11 needs one LANDING-PATH candidate (G/H) + the funnel metrics. Note which field');
        $this->line('names come back OK — those are what the sync will pull. If A errors or no funnel');
        $this->line('metric candidate passes, ShopifyQL can\'t drive the funnels → GA4 is required instead.');

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
