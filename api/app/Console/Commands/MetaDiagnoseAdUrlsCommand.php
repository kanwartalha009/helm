<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Brand;
use App\Platforms\Meta\MetaClient;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only probe for the Inventory Intelligence report's Meta spend → product
 * attribution (docs/feature-specs/brand-inventory-and-customer-mix-reports.md §3).
 *
 * Our Meta sync is account + campaign level only, with no ad creative / landing
 * URL. To attribute spend to a Shopify product we need each ad's destination URL
 * — but that lives in different creative fields depending on the ad type (link ad
 * vs Advantage+/catalog vs video), and can't be verified offline. This pulls a
 * brand's real ads for the last 30 days, extracts the best URL candidate per ad,
 * classifies it (product handle / collection / dynamic-or-other), and tallies how
 * much SPEND lands on a product URL — i.e. the attribution coverage we'd get.
 *
 *   php artisan meta:diagnose-ad-urls ganzitos
 *   php artisan meta:diagnose-ad-urls meller --days=30 --show=30
 */
class MetaDiagnoseAdUrlsCommand extends Command
{
    protected $signature = 'meta:diagnose-ad-urls {brand : slug or id} {--days=30} {--show=25 : how many top-spend ads to list}';
    protected $description = 'Probe Meta ad creatives for the landing URL + estimate product-attribution coverage (Inventory report).';

    public function handle(MetaClient $client): int
    {
        $arg   = (string) $this->argument('brand');
        $lower = strtolower(trim($arg));
        $brand = is_numeric($arg)
            ? Brand::query()->with('connections')->find((int) $arg)
            : (Brand::query()->with('connections')
                ->whereRaw('LOWER(slug) = ?', [$lower])
                ->orWhereRaw('LOWER(name) = ?', [$lower])
                ->first()
                ?: Brand::query()->with('connections')->where('name', 'like', '%' . $arg . '%')->first());

        if ($brand === null) {
            $this->error("No brand matched '{$arg}'.");

            return self::FAILURE;
        }

        $conn = $brand->connections->firstWhere('platform', 'meta');
        if (! $conn || $conn->status !== 'active') {
            $this->error("{$brand->name} has no active Meta connection.");

            return self::FAILURE;
        }

        $ids = $conn->metadata['ad_account_ids'] ?? null;
        $accountIds = is_array($ids) && $ids !== []
            ? array_values(array_map(static fn ($i) => (string) $i, $ids))
            : ($conn->external_id ? [(string) $conn->external_id] : []);
        $accountIds = array_map(static fn ($id) => str_starts_with($id, 'act_') ? $id : 'act_' . $id, $accountIds);

        if ($accountIds === []) {
            $this->error("{$brand->name}'s Meta connection has no ad accounts selected.");

            return self::FAILURE;
        }

        $tz    = $brand->timezone ?: 'UTC';
        $days  = max(1, (int) $this->option('days'));
        $to    = CarbonImmutable::now($tz)->subDay()->startOfDay();
        $from  = $to->subDays($days - 1);
        $range = json_encode(['since' => $from->toDateString(), 'until' => $to->toDateString()]);
        $show  = max(1, (int) $this->option('show'));

        $this->info("Meta ad-URL probe · {$brand->name} · {$from->toDateString()}..{$to->toDateString()}");
        $this->newLine();

        $grand = ['spend' => 0.0, 'product' => 0.0, 'collection' => 0.0, 'other' => 0.0];
        $handles = [];
        $rows = [];   // [spend, name, status, url, field, class]

        foreach ($accountIds as $accountId) {
            // Spend per ad.
            $spendByAd = [];
            try {
                foreach ($client->paged("{$accountId}/insights", [
                    'level'       => 'ad',
                    'fields'      => 'ad_id,ad_name,spend',
                    'time_range'  => $range,
                    'limit'       => 500,
                ]) as $r) {
                    $spendByAd[(string) ($r['ad_id'] ?? '')] = (float) ($r['spend'] ?? 0);
                }
            } catch (Throwable $e) {
                $this->warn("  {$accountId}: insights failed — {$e->getMessage()}");
                continue;
            }

            // Ads + creative URL fields.
            try {
                $ads = $client->paged("{$accountId}/ads", [
                    'fields' => 'id,name,effective_status,creative{object_story_spec,asset_feed_spec,link_url,template_url,url_tags}',
                    'limit'  => 200,
                ]);
            } catch (Throwable $e) {
                $this->warn("  {$accountId}: ads fetch failed — {$e->getMessage()}");
                continue;
            }

            foreach ($ads as $ad) {
                $adId  = (string) ($ad['id'] ?? '');
                $spend = $spendByAd[$adId] ?? 0.0;
                if ($spend <= 0) {
                    continue; // only ads that actually spent in the window matter
                }
                [$url, $field] = $this->extractUrl((array) ($ad['creative'] ?? []));
                $handle = $url !== '' ? $this->productHandle($url) : null;
                $class  = $handle !== null ? 'product'
                    : ($url !== '' && stripos($url, '/collections/') !== false ? 'collection'
                    : 'other');

                $grand['spend']  += $spend;
                $grand[$class]   += $spend;
                if ($handle !== null) {
                    $handles[$handle] = ($handles[$handle] ?? 0) + $spend;
                }

                $rows[] = [$spend, (string) ($ad['name'] ?? ''), (string) ($ad['effective_status'] ?? ''), $url, $field, $class];
            }
        }

        if ($rows === []) {
            $this->warn('No ads with spend in this window. Try a longer --days, or the account had no delivery.');

            return self::SUCCESS;
        }

        usort($rows, static fn ($a, $b) => $b[0] <=> $a[0]);

        $this->line(sprintf('  %-9s %-8s %-46s %-28s %s', 'spend', 'class', 'url', 'field', 'ad'));
        foreach (array_slice($rows, 0, $show) as [$spend, $name, $status, $url, $field, $class]) {
            $this->line(sprintf(
                '  %-9s %-8s %-46s %-28s %s',
                number_format($spend, 0),
                $class,
                $url !== '' ? mb_strimwidth($url, 0, 45, '…') : '—',
                $field ?: '—',
                mb_strimwidth($name, 0, 40, '…')
            ));
        }

        $t = $grand['spend'] ?: 1;
        $this->newLine();
        $this->info('Attribution coverage (by spend):');
        $this->line(sprintf('  product URLs:    %s  (%.1f%%)  ← attributable to a model', number_format($grand['product'], 0), $grand['product'] / $t * 100));
        $this->line(sprintf('  collection URLs: %s  (%.1f%%)  ← attributable only if slug maps to a model', number_format($grand['collection'], 0), $grand['collection'] / $t * 100));
        $this->line(sprintf('  other/dynamic:   %s  (%.1f%%)  ← unattributed banner', number_format($grand['other'], 0), $grand['other'] / $t * 100));
        $this->line(sprintf('  total spend:     %s', number_format($grand['spend'], 0)));
        arsort($handles);
        $this->newLine();
        $this->line('Distinct product handles found: ' . count($handles) . ' · top: ' . implode(', ', array_slice(array_keys($handles), 0, 8)));
        $this->newLine();
        $this->line('If a product line above shows "—" URL with a field that IS populated in the raw');
        $this->line('creative, add that field path to extractUrl(). The winning `field` column tells us');
        $this->line('exactly what to read in the real fetcher.');

        return self::SUCCESS;
    }

    /**
     * Best-effort landing URL from a creative, trying the fields that carry it
     * across ad types. Returns [url, field-path] or ['', ''].
     *
     * @param array<string, mixed> $c
     * @return array{0: string, 1: string}
     */
    private function extractUrl(array $c): array
    {
        $oss  = (array) ($c['object_story_spec'] ?? []);
        $feed = (array) ($c['asset_feed_spec'] ?? []);

        $candidates = [
            'object_story_spec.link_data.link'            => $oss['link_data']['link'] ?? null,
            'object_story_spec.link_data.cta.value.link'  => $oss['link_data']['call_to_action']['value']['link'] ?? null,
            'object_story_spec.video_data.cta.value.link' => $oss['video_data']['call_to_action']['value']['link'] ?? null,
            'object_story_spec.template_data.link'        => $oss['template_data']['link'] ?? null,
            'asset_feed_spec.link_urls[0].website_url'    => $feed['link_urls'][0]['website_url'] ?? null,
            'creative.link_url'                           => $c['link_url'] ?? null,
            'creative.template_url'                       => $c['template_url'] ?? null,
        ];

        foreach ($candidates as $field => $val) {
            if (is_string($val) && $val !== '') {
                return [$val, $field];
            }
        }

        return ['', ''];
    }

    /** Extract a Shopify product handle from a landing URL, ignoring the market prefix (/it, /fr-fr, …). */
    private function productHandle(string $url): ?string
    {
        if (preg_match('~(?:/[a-z]{2}(?:-[a-z]{2})?)?/products/([^/?#]+)~i', $url, $m)) {
            return strtolower($m[1]);
        }

        return null;
    }
}
