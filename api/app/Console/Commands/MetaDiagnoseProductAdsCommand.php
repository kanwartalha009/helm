<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AdProductDaily;
use App\Models\Brand;
use App\Platforms\Meta\AdProductFetcher;
use App\Platforms\Meta\MetaClient;
use App\Support\LandingPathMapper;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * "Helm shows 0 active ads / no spend for product X, but Meta clearly has active ads on it."
 *
 * (Real case: Flabelus / "pip" — Meta had 3 active ads pointing at /products/pip spending real money,
 * Helm's Inventory report showed 0 ads and "—" spend.)
 *
 * Inventory attributes Meta spend to a product by the ad's LANDING URL (AdProductFetcher): each
 * spending ad's creative is read, a URL is extracted, and it is classified to a product handle or to
 * the `__other` bucket. If a product shows 0/—, its spend went somewhere OTHER than its handle. There
 * are only three ways that happens, and this tells you WHICH:
 *
 *   1. NOT SYNCED    — ad_product_daily simply has no rows for the window (the sync didn't run).
 *   2. MISATTRIBUTED — the ad spent, but our extractor put its URL in `__other`/collection, or the
 *                      creative-batch read failed (rate limit) and everything fell back to `__other`.
 *   3. FIELD GAP     — the ad's link IS in the creative, but in a field extractUrl() does not read.
 *
 * It drives the REAL AdProductFetcher::extractUrl()/classify() — not a copy — so what it reports is
 * exactly what the sync does. For any ad whose raw creative mentions the handle but which we fail to
 * classify to it, the raw creative is dumped so the missing field path is visible.
 *
 *   php artisan meta:diagnose-product-ads flabelus pip
 *   php artisan meta:diagnose-product-ads flabelus pip --days=7
 */
class MetaDiagnoseProductAdsCommand extends Command
{
    protected $signature = 'meta:diagnose-product-ads '
        . '{brand : slug or id} '
        . '{handle : the Shopify product handle, e.g. pip} '
        . '{--days=7 : window length; ends yesterday in the brand tz}';

    protected $description = 'Explain why a product shows 0 ads / no spend in Inventory: not-synced vs misattributed vs field-gap.';

    /** Rich creative field set — a superset of the fetcher's, so a field-gap is visible in the raw. */
    private const CREATIVE_FIELDS = 'id,name,effective_status,'
        . 'creative{object_story_spec,asset_feed_spec,link_url,template_url,object_type,effective_object_story_id}';

    public function handle(MetaClient $client): int
    {
        $brand = $this->resolveBrand();
        if ($brand === null) {
            $this->error('Brand not found.');

            return self::FAILURE;
        }

        $wantHandle = LandingPathMapper::productHandle('/products/' . strtolower(trim((string) $this->argument('handle'))))
            ?? strtolower(trim((string) $this->argument('handle')));

        $conn = $brand->connections->firstWhere('platform', 'meta');
        if (! $conn || $conn->status !== 'active') {
            $this->error("{$brand->name} has no active Meta connection.");

            return self::FAILURE;
        }

        $tz   = $brand->timezone ?: 'UTC';
        $days = max(1, (int) $this->option('days'));
        $to   = CarbonImmutable::now($tz)->subDay()->startOfDay();
        $from = $to->subDays($days - 1);

        $this->info("Product-ads probe · {$brand->name} · handle '{$wantHandle}' · {$from->toDateString()}..{$to->toDateString()}");
        $this->newLine();

        /* ── 1. WHAT IS STORED ─────────────────────────────────────────────────────────── */

        $stored = AdProductDaily::query()
            ->where('brand_id', $brand->id)
            ->where('product_key', $wantHandle)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            // `rows` is a RESERVED WORD in MariaDB (ROW/ROWS), so the alias must be quoted or it is a
            // 1064 syntax error. `row_count` sidesteps the whole question.
            ->selectRaw('COALESCE(SUM(spend),0) AS spend, COALESCE(MAX(ads_count),0) AS ads, COUNT(*) AS row_count')
            ->first();

        $storedSpend = (float) ($stored->spend ?? 0);
        $storedAds   = (int) ($stored->ads ?? 0);
        $storedRows  = (int) ($stored->row_count ?? 0);

        $this->line('STORED in ad_product_daily (what Inventory reads):');
        $this->line(sprintf('  handle %-20s spend %-10s ads %-4s (%d row(s))', $wantHandle, number_format($storedSpend, 2), $storedAds, $storedRows));

        $otherRow = AdProductDaily::query()
            ->where('brand_id', $brand->id)
            ->whereIn('product_key', [AdProductFetcher::RESERVED_OTHER, AdProductFetcher::RESERVED_COLLECTION])
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('COALESCE(SUM(spend),0) AS spend')
            ->value('spend');
        $this->line('  (unattributed __other/__collection spend in window: ' . number_format((float) $otherRow, 2) . ')');
        $this->newLine();

        /* ── 2. WHAT META ACTUALLY HAS, RUN THROUGH THE REAL EXTRACTOR ──────────────────── */

        $accountIds = $this->accountIds($conn);
        if ($accountIds === []) {
            $this->error('No Meta ad accounts selected on this connection.');

            return self::FAILURE;
        }

        // adId => ['spend'=>float,'status'=>string,'name'=>string,'url'=>string,'field'=>string,'class'=>string,'raw'=>array]
        $matches   = [];   // ads whose RAW creative mentions the handle (should attribute to it)
        $liveSpend = 0.0;  // live spend our extractor DOES attribute to the handle
        $liveAds   = [];
        $adsSeen   = 0;    // total distinct spending ads we actually examined
        $truncated = [];   // days where the insights page hit the limit or had a next cursor

        $this->line('ACCOUNTS queried: ' . implode(', ', $accountIds));
        $this->newLine();

        foreach ($accountIds as $accountId) {
            $spendByAd = $this->spendByAd($client, $accountId, $from, $to, $truncated);
            $adsSeen  += count($spendByAd);
            if ($spendByAd === []) {
                continue;
            }

            foreach (array_chunk(array_keys($spendByAd), 45) as $chunk) {
                $batch = $this->creatives($client, $chunk);

                foreach ($chunk as $adId) {
                    $ad       = is_array($batch[$adId] ?? null) ? $batch[$adId] : [];
                    $creative = (array) ($ad['creative'] ?? []);

                    // The EXACT production logic — not a copy.
                    $url   = AdProductFetcher::extractUrl($creative);
                    $class = AdProductFetcher::classify($url);

                    if ($class === $wantHandle) {
                        $liveSpend           += $spendByAd[$adId];
                        $liveAds[$adId]       = true;
                    }

                    // Does the RAW creative mention this handle anywhere, regardless of what we
                    // extracted? If yes, this ad SHOULD attribute to the handle — and if `class`
                    // isn't the handle, we've found the bug and the raw shows the field.
                    $rawJson = json_encode($creative);
                    if ($rawJson !== false && stripos($rawJson, '/products/' . $wantHandle) !== false) {
                        $matches[$adId] = [
                            'spend'  => $spendByAd[$adId],
                            'status' => (string) ($ad['effective_status'] ?? '?'),
                            'name'   => (string) ($ad['name'] ?? ''),
                            'url'    => $url,
                            'class'  => $class,
                            'raw'    => $creative,
                        ];
                    }
                }
            }
        }

        $this->line("LIVE from Meta, through AdProductFetcher's OWN extractor:");
        $this->line('  total spending ads examined: ' . number_format($adsSeen));
        $this->line(sprintf('  ads whose landing URL we resolve to \'%s\': %d, spend %s', $wantHandle, count($liveAds), number_format($liveSpend, 2)));
        $this->line('  ads whose RAW creative mentions /products/' . $wantHandle . ': ' . count($matches));

        // ══ THE TRUNCATION CHECK ══
        // The fetcher pulls ad-level insights with limit:500, ONE page per day, no pagination. On a
        // big account a single day has more than 500 spending ads, so the response is cut off — and
        // the dropped tail is the LOW-SPEND ads, which is exactly where a €4 product like pip lives.
        // A day that returns a full page (or a next cursor) is a day we did NOT see completely.
        if ($truncated !== []) {
            $this->newLine();
            $this->error('  ⚠ TRUNCATED: ' . count($truncated) . ' day(s) returned a FULL page (limit hit) — '
                . 'the insights call is paginated and we only read page 1:');
            $this->line('     ' . implode(', ', $truncated));
            $this->line('     Low-spend ads (like this product\'s) sit in the dropped tail and are never seen.');
        }
        $this->newLine();

        if ($matches !== []) {
            $this->line('  Every ad that points at this product, and what we did with it:');
            foreach ($matches as $adId => $m) {
                $ok = $m['class'] === $wantHandle;
                $this->line(sprintf(
                    '   %s  spend %-8s  %-14s  extracted: %s',
                    $ok ? '✓' : '✗',
                    number_format($m['spend'], 2),
                    $m['status'],
                    $m['url'] !== '' ? mb_strimwidth($m['url'], 0, 50, '…') : 'MISS (empty) → ' . $m['class'],
                ));
                if (! $ok) {
                    // The raw creative is where the URL is hiding. Show it so the field is visible.
                    $this->line('       RAW creative: ' . mb_strimwidth((string) json_encode($m['raw']), 0, 400, '…'));
                }
            }
            $this->newLine();
        }

        /* ── 3. THE VERDICT ────────────────────────────────────────────────────────────── */

        $this->line(str_repeat('─', 60));
        if (count($matches) === 0 && $truncated !== []) {
            $this->error('VERDICT: TRUNCATED INSIGHTS. We did not see this product\'s ads at all — the day-level');
            $this->line('  insights read stops at 500 ads (no pagination), and this account has more than that.');
            $this->line('  The fix is in AdProductFetcher: follow Graph pagination instead of reading one page.');
            $this->line('  Until then, low-spend products on big accounts show 0/— even while actively advertised.');
        } elseif (count($matches) === 0) {
            $this->warn('VERDICT: no live Meta ad in this window points at /products/' . $wantHandle . '.');
            $this->line('  We examined ' . number_format($adsSeen) . ' ad(s) and none pointed here, with no truncation.');
            $this->line('  Either the ads ran OUTSIDE this window (try --days=30), the product is not being');
            $this->line('  advertised right now (Helm showing 0 is then CORRECT), or its ads live in an ad');
            $this->line('  account this connection has not selected (check ACCOUNTS queried, above).');
        } elseif (count($liveAds) === count($matches) && $storedRows === 0) {
            $this->warn('VERDICT: NOT SYNCED. We attribute these ads correctly, but ad_product_daily has no');
            $this->line('  rows for this handle in the window — the sync has not written them yet. Fix:');
            $this->line("      php artisan meta:backfill-ad-products {$brand->slug} --since={$from->toDateString()}");
        } elseif (count($liveAds) < count($matches)) {
            $this->error('VERDICT: MISATTRIBUTED (field gap). ' . (count($matches) - count($liveAds)) . ' ad(s) point at this');
            $this->line('  product but our extractor did NOT resolve them — see the ✗ rows and their RAW creative');
            $this->line('  above. The URL is in a creative field extractUrl() does not read yet. Add that field');
            $this->line('  path to AdProductFetcher::extractUrl(), then re-backfill the window.');
        } else {
            $this->info('VERDICT: extractor and store agree. If Inventory still shows 0, the window or the');
            $this->line('  currency/date rollup is the next place to look — not the attribution.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $truncated  populated with "account day (N)" for any day that hit the page limit
     * @return array<string,float> adId => spend, over the window, day by day (mirrors the fetcher EXACTLY,
     *                              including its limit:500 single-page read, so truncation is reproduced not hidden)
     */
    private function spendByAd(MetaClient $client, string $accountId, CarbonImmutable $from, CarbonImmutable $to, array &$truncated): array
    {
        $limit = 500;
        $out   = [];

        for ($d = $from; $d->lessThanOrEqualTo($to); $d = $d->addDay()) {
            $day = $d->toDateString();
            try {
                $body = $client->get($accountId . '/insights', [
                    'level'      => 'ad',
                    'fields'     => 'ad_id,spend',
                    'time_range' => json_encode(['since' => $day, 'until' => $day]),
                    'limit'      => $limit,
                ]);
            } catch (Throwable $e) {
                $this->warn("  {$accountId} {$day}: insights failed — {$e->getMessage()}");
                usleep(400_000);
                continue;
            }

            $data = $body['data'] ?? [];
            // A full page, or a next cursor, means Meta had MORE rows than we read. The fetcher
            // stops here — so those extra ads (the low-spend tail) never get attributed.
            if (count($data) >= $limit || ! empty($body['paging']['next'])) {
                $truncated[] = "{$accountId} {$day} (" . count($data) . '+ ads)';
            }

            foreach ($data as $r) {
                $adId = (string) ($r['ad_id'] ?? '');
                $s    = (float) ($r['spend'] ?? 0);
                if ($adId !== '' && $s > 0) {
                    $out[$adId] = ($out[$adId] ?? 0.0) + $s;
                }
            }
            usleep(120_000);
        }

        return $out;
    }

    /** @param array<int,string> $ids @return array<string,mixed> */
    private function creatives(MetaClient $client, array $ids): array
    {
        try {
            return $client->get('', ['ids' => implode(',', $ids), 'fields' => self::CREATIVE_FIELDS]);
        } catch (Throwable $e) {
            // This is itself a cause of misattribution — say so loudly rather than silently blanking.
            $this->error('  creative batch FAILED (' . count($ids) . " ads) — {$e->getMessage()}");
            $this->line('  In the real sync these ads would ALL fall back to __other. That is a misattribution');
            $this->line('  cause in its own right (rate limit). Re-run; the backfill retries.');

            return [];
        }
    }

    /** @return array<int,string> */
    private function accountIds($conn): array
    {
        $ids = $conn->metadata['ad_account_ids'] ?? null;
        $ids = is_array($ids) && $ids !== []
            ? array_values(array_map(static fn ($i) => (string) $i, $ids))
            : ($conn->external_id ? [(string) $conn->external_id] : []);

        return array_map(static fn ($id) => str_starts_with($id, 'act_') ? $id : 'act_' . $id, $ids);
    }

    private function resolveBrand(): ?Brand
    {
        $arg   = (string) $this->argument('brand');
        $lower = strtolower(trim($arg));

        return is_numeric($arg)
            ? Brand::query()->with('connections')->find((int) $arg)
            : (Brand::query()->with('connections')
                ->whereRaw('LOWER(slug) = ?', [$lower])
                ->orWhereRaw('LOWER(name) = ?', [$lower])
                ->first()
                ?: Brand::query()->with('connections')->where('name', 'like', '%' . $arg . '%')->first());
    }
}
