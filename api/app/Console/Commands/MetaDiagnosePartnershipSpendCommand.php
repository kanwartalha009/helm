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
 * H1/H3 quantifier for the Bruna amboise incident (Kanwar, 2026-07-22, revised
 * after the live run). The client filtered Ads Manager by ad NAME ("amboise-stud")
 * and saw €33,421.55 / 127 ads; Helm attributes by landing page. This classifies
 * every NAME-matched, SPENDING ad with the REAL production extractor into
 * on-product / elsewhere / no-url, and — because the live recon showed the true
 * gap is ad-level spend COMPLETENESS, not URL parsing — it also surfaces:
 *
 *   • the count/€ this ad-level pull found vs the client's ad-name total (the
 *     ~35% level=ad shortfall shows up here as "found N ads, client said 127");
 *   • for the no-url ads, how many are partnership/whitelisted (they carry an
 *     `effective_object_story_id` pointing at a creator page) — that quantifies
 *     whether partnership ads are the ones going unattributed, WITHOUT requesting
 *     the invalid `effective_object_story_spec` field that 400s the batch.
 *
 * Read-only. Run on prod:
 *   php artisan meta:diagnose-partnership-spend bruna-jewellery \
 *       --account=act_1690557571077141 --name=amboise-stud --handle=amboise-studs \
 *       --since=2026-07-01 --until=2026-07-20
 */
final class MetaDiagnosePartnershipSpendCommand extends Command
{
    protected $signature = 'meta:diagnose-partnership-spend '
        . '{brand : slug or id} '
        . '{--account= : restrict to ONE act_… account (default: all selected on the connection)} '
        . '{--name= : ad-name substring to match, e.g. amboise-stud (case-insensitive)} '
        . '{--handle= : the product handle that counts as on-product (default: derived from --name)} '
        . '{--since= : start Y-m-d} {--until= : end Y-m-d} {--days=20 : used when --since/--until omitted}';

    protected $description = 'Ad-by-ad classification of name-matched spend (on-product / elsewhere / no-url) + partnership + completeness. Read-only.';

    /**
     * VALID creative field set only (the invalid effective_object_story_spec was
     * withdrawn — it 400s the whole batch). effective_object_story_id IS valid and
     * flags partnership/whitelisted ads (their story lives on a creator page).
     */
    private const CREATIVE_FIELDS = 'id,creative{'
        . 'object_story_spec{link_data{link,call_to_action},video_data{call_to_action},template_data{link}},'
        . 'asset_feed_spec{link_urls},link_url,template_url,effective_object_story_id}';

    public function handle(MetaClient $client): int
    {
        $brand = $this->resolveBrand();
        if ($brand === null) {
            $this->error('Brand not found.');

            return self::FAILURE;
        }

        $conn = $brand->connections->firstWhere('platform', 'meta');
        if (! $conn || $conn->status !== 'active') {
            $this->error("{$brand->name} has no active Meta connection.");

            return self::FAILURE;
        }

        $name = strtolower(trim((string) ($this->option('name') ?? '')));
        if ($name === '') {
            $this->error('Pass --name (the ad-name substring the client filtered by, e.g. amboise-stud).');

            return self::FAILURE;
        }
        $handle = strtolower(trim((string) ($this->option('handle') ?? $name)));
        $handle = LandingPathMapper::productHandle('/products/' . $handle) ?? $handle;

        $tz    = $brand->timezone ?: 'UTC';
        $until = $this->option('until') ? CarbonImmutable::parse((string) $this->option('until'), $tz) : CarbonImmutable::now($tz)->subDay();
        $since = $this->option('since') ? CarbonImmutable::parse((string) $this->option('since'), $tz) : $until->subDays(max(1, (int) $this->option('days')) - 1);

        $accounts = $this->accountIds($conn);
        if ($only = $this->option('account')) {
            $only = str_starts_with((string) $only, 'act_') ? (string) $only : 'act_' . $only;
            $accounts = array_values(array_filter($accounts, static fn (string $a): bool => $a === $only));
        }
        if ($accounts === []) {
            $this->error('No matching Meta accounts on this connection.');

            return self::FAILURE;
        }

        $this->info("Partnership-spend probe · {$brand->name} · name~'{$name}' · handle '{$handle}' · {$since->toDateString()}..{$until->toDateString()}");
        $this->line('Accounts: ' . implode(', ', $accounts));
        $this->newLine();

        // adId => ['name'=>, 'spend'=>]
        $matched = [];
        foreach ($accounts as $accountId) {
            for ($d = $since; $d->lessThanOrEqualTo($until); $d = $d->addDay()) {
                $day = $d->toDateString();
                try {
                    $rows = $client->paged($accountId . '/insights', [
                        'level'      => 'ad',
                        'fields'     => 'ad_id,ad_name,spend',
                        'time_range' => json_encode(['since' => $day, 'until' => $day]),
                        'limit'      => 500,
                    ]);
                } catch (Throwable $e) {
                    $this->warn("  {$accountId} {$day}: insights failed — {$e->getMessage()}");
                    usleep(400_000);
                    continue;
                }
                foreach ($rows as $r) {
                    $adId = (string) ($r['ad_id'] ?? '');
                    $nm   = (string) ($r['ad_name'] ?? '');
                    $s    = (float) ($r['spend'] ?? 0);
                    if ($adId === '' || $s <= 0 || stripos($nm, $name) === false) {
                        continue;
                    }
                    $matched[$adId]['name']  = $nm;
                    $matched[$adId]['spend'] = ($matched[$adId]['spend'] ?? 0.0) + $s;
                }
                usleep(120_000);
            }
        }

        if ($matched === []) {
            $this->warn("No spending ads whose name contains '{$name}' in this window/account.");

            return self::SUCCESS;
        }

        // Classify each matched ad with the REAL extractor. Track partnership ads
        // (effective_object_story_id present) and whether they resolve.
        $byClass       = ['product' => 0.0, 'elsewhere' => 0.0, 'nourl' => 0.0];
        $partnership   = ['count' => 0, 'spend' => 0.0, 'nourl_spend' => 0.0];
        $unresolved    = []; // no-url ads, biggest spend first, for the tail dump

        foreach (array_chunk(array_keys($matched), 45) as $chunk) {
            $batch = $this->creatives($client, $chunk);
            foreach ($chunk as $adId) {
                $creative = is_array($batch[$adId] ?? null) ? (array) ($batch[$adId]['creative'] ?? []) : [];
                $spend    = (float) $matched[$adId]['spend'];

                $bucket = $this->bucket(AdProductFetcher::classify(AdProductFetcher::extractUrl($creative)), $handle);
                $byClass[$bucket] += $spend;

                $isPartnership = ! empty($creative['effective_object_story_id']);
                if ($isPartnership) {
                    $partnership['count']++;
                    $partnership['spend'] += $spend;
                    if ($bucket === 'nourl') {
                        $partnership['nourl_spend'] += $spend;
                    }
                }
                if ($bucket === 'nourl') {
                    $unresolved[$adId] = ['name' => $matched[$adId]['name'], 'spend' => $spend, 'partnership' => $isPartnership];
                }
            }
        }

        $adsMatched   = count($matched);
        $matchedSpend = array_sum(array_map(static fn ($m) => (float) $m['spend'], $matched));

        $this->line('NAME-MATCHED population found by this level=ad pull:');
        $this->line(sprintf('  %d ads · %s total spend', $adsMatched, number_format($matchedSpend, 2)));
        $this->line('  (client filtered by ad name and saw 127 ads / €33,421.55 on this account — the shortfall');
        $this->line('   here is the ~35% level=ad completeness gap recon:ads-spend measured, NOT URL parsing.)');
        $this->newLine();

        $this->line('CLASSIFICATION of the found ads (REAL extractor):');
        $this->table(
            ['Landing class', 'Spend €', '% of found'],
            [
                ["on-product ({$handle})", number_format($byClass['product'], 2), $this->pct($byClass['product'], $matchedSpend)],
                ['elsewhere (collection / home / other handle)', number_format($byClass['elsewhere'], 2), $this->pct($byClass['elsewhere'], $matchedSpend)],
                ['no-url (→ __other, unattributed)', number_format($byClass['nourl'], 2), $this->pct($byClass['nourl'], $matchedSpend)],
            ],
        );
        $this->line('  H3 definitional gap = "elsewhere" = ' . number_format($byClass['elsewhere'], 2) . ' € (named ' . $name . ', lands off-product).');
        $this->line('  Partnership/whitelisted ads (effective_object_story_id present): '
            . $partnership['count'] . ' ads · ' . number_format($partnership['spend'], 2) . ' €'
            . ' — of which ' . number_format($partnership['nourl_spend'], 2) . ' € went no-url.');
        $this->line('  → If that no-url partnership € is large, THEN a story-resolution fix (via');
        $this->line('    effective_object_story_id, not the invalid _spec field) is worth building. If small,');
        $this->line('    the gap is the completeness bug, not partnership URLs.');
        $this->newLine();

        if ($unresolved !== []) {
            uasort($unresolved, static fn ($a, $b) => $b['spend'] <=> $a['spend']);
            $this->line('Top unattributed (no-url) ads:');
            foreach (array_slice($unresolved, 0, 15, true) as $r) {
                $this->line(sprintf('   %-9s  %s  %s', number_format($r['spend'], 2), $r['partnership'] ? '[partnership]' : '[direct]    ', mb_strimwidth($r['name'], 0, 55, '…')));
            }
            $this->newLine();
        }

        $stored = (float) AdProductDaily::query()
            ->where('brand_id', $brand->id)
            ->where('product_key', $handle)
            ->whereBetween('date', [$since->toDateString(), $until->toDateString()])
            ->sum('spend');

        $this->line('RECONCILE:');
        $this->line('  stored ad_product_daily[' . $handle . ']: ' . number_format($stored, 2) . ' (if 0, check the handle — client product may be "amboise-studs")');
        $this->line('  on-product from this run:                 ' . number_format($byClass['product'], 2));

        return self::SUCCESS;
    }

    private function pct(float $part, float $whole): string
    {
        return $whole <= 0 ? '—' : number_format($part / $whole * 100, 1) . '%';
    }

    /** Map a classify() result to the 3 report buckets relative to the target handle. */
    private function bucket(string $class, string $handle): string
    {
        if ($class === $handle) {
            return 'product';
        }
        if ($class === AdProductFetcher::RESERVED_OTHER) {
            return 'nourl';
        }

        return 'elsewhere'; // another handle, or __collection
    }

    /** @param array<int,string> $ids @return array<string,mixed> */
    private function creatives(MetaClient $client, array $ids): array
    {
        try {
            return $client->get('', ['ids' => implode(',', $ids), 'fields' => self::CREATIVE_FIELDS]);
        } catch (Throwable $e) {
            $this->error('  creative batch FAILED (' . count($ids) . " ads) — {$e->getMessage()} — these would fall to __other in the real sync.");

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
