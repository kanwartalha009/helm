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
 * H1/H3 quantifier for the Bruna amboise incident (Kanwar, 2026-07-22). The
 * client filtered Ads Manager by ad NAME ("amboise-stud"), 1–20 Jul, one account,
 * and saw €33,421.55; Helm's Inventory (attributed by landing page) showed
 * €26,475. This measures the gap ad-by-ad, and — the important part — classifies
 * each ad TWICE:
 *
 *   LEGACY = extractUrl WITHOUT effective_object_story_spec (the pre-fix path)
 *   FIXED  = extractUrl WITH it (the H1 fix)
 *
 * so the € the fix RECOVERS is a measured number, not a claim. Per ad it reports
 * the class under each path: on-product / elsewhere / NO-URL. The delta between
 * LEGACY-on-product and FIXED-on-product is H1's magnitude; FIXED "elsewhere" is
 * H3's definitional gap (named amboise, lands on a collection/home); FIXED NO-URL
 * is what genuinely stays unattributed (counted in __other, never dropped).
 *
 * Read-only — no writes. Run it on prod, paste the table:
 *   php artisan meta:diagnose-partnership-spend bruna-jewellery \
 *       --account=act_1690557571077141 --name=amboise-stud --handle=amboise-stud \
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

    protected $description = 'Ad-by-ad new-vs-old classification of name-matched spend (H1 partnership URLs + H3 definitional gap). Read-only.';

    /** Superset creative fields — includes effective_object_story_spec so both paths can be computed. */
    private const CREATIVE_FIELDS = 'id,creative{'
        . 'object_story_spec{link_data{link,call_to_action},video_data{call_to_action},template_data{link}},'
        . 'effective_object_story_spec{link_data{link,call_to_action},video_data{call_to_action},template_data{link}},'
        . 'asset_feed_spec{link_urls},link_url,template_url}';

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

        $name   = strtolower(trim((string) ($this->option('name') ?? '')));
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

        // Classify each matched ad under LEGACY (no effective_object_story_spec)
        // and FIXED (with it), via the REAL extractor.
        $class = [
            'legacy' => ['product' => 0.0, 'elsewhere' => 0.0, 'nourl' => 0.0],
            'fixed'  => ['product' => 0.0, 'elsewhere' => 0.0, 'nourl' => 0.0],
        ];
        $recovered = []; // ads that were NO-URL/elsewhere under legacy but on-product under fixed

        foreach (array_chunk(array_keys($matched), 45) as $chunk) {
            $batch = $this->creatives($client, $chunk);
            foreach ($chunk as $adId) {
                $creative = is_array($batch[$adId] ?? null) ? (array) ($batch[$adId]['creative'] ?? []) : [];
                $spend    = (float) $matched[$adId]['spend'];

                // FIXED = full creative. LEGACY = same creative with eoss removed.
                $legacyCreative = $creative;
                unset($legacyCreative['effective_object_story_spec']);

                $fixedClass  = $this->bucket(AdProductFetcher::classify(AdProductFetcher::extractUrl($creative)), $handle);
                $legacyClass = $this->bucket(AdProductFetcher::classify(AdProductFetcher::extractUrl($legacyCreative)), $handle);

                $class['fixed'][$fixedClass]   += $spend;
                $class['legacy'][$legacyClass] += $spend;

                if ($legacyClass !== 'product' && $fixedClass === 'product') {
                    $recovered[$adId] = ['name' => $matched[$adId]['name'], 'spend' => $spend, 'was' => $legacyClass];
                }
            }
        }

        $adsMatched = count($matched);
        $matchedSpend = array_sum(array_map(static fn ($m) => (float) $m['spend'], $matched));

        $this->line('NAME-MATCHED population (what the client sees when filtering by ad name):');
        $this->line(sprintf('  %d ads · %s total spend', $adsMatched, number_format($matchedSpend, 2)));
        $this->newLine();

        $this->line('CLASSIFICATION — spend by landing class, LEGACY (pre-fix) vs FIXED (effective_object_story_spec):');
        $this->table(
            ['Class', 'LEGACY €', 'FIXED €', 'Δ (recovered)'],
            [
                ["on-product ({$handle})", number_format($class['legacy']['product'], 2), number_format($class['fixed']['product'], 2), number_format($class['fixed']['product'] - $class['legacy']['product'], 2)],
                ['elsewhere (collection/home/other handle)', number_format($class['legacy']['elsewhere'], 2), number_format($class['fixed']['elsewhere'], 2), number_format($class['fixed']['elsewhere'] - $class['legacy']['elsewhere'], 2)],
                ['NO-URL (→ __other, unattributed)', number_format($class['legacy']['nourl'], 2), number_format($class['fixed']['nourl'], 2), number_format($class['fixed']['nourl'] - $class['legacy']['nourl'], 2)],
            ],
        );
        $this->line('  H1 magnitude = Δ on-product = ' . number_format($class['fixed']['product'] - $class['legacy']['product'], 2) . ' € recovered by the fix.');
        $this->line('  H3 definitional gap = FIXED "elsewhere" = ' . number_format($class['fixed']['elsewhere'], 2) . ' € (named ' . $name . ', lands off-product).');
        $this->newLine();

        if ($recovered !== []) {
            uasort($recovered, static fn ($a, $b) => $b['spend'] <=> $a['spend']);
            $this->line('Ads the fix RECOVERS to the product (top 15):');
            foreach (array_slice($recovered, 0, 15, true) as $adId => $r) {
                $this->line(sprintf('   +%-9s  was %-9s  %s', number_format($r['spend'], 2), $r['was'], mb_strimwidth($r['name'], 0, 60, '…')));
            }
            $this->newLine();
        }

        // What Helm has STORED for the handle in this window (for the reconcile line).
        $stored = (float) AdProductDaily::query()
            ->where('brand_id', $brand->id)
            ->where('product_key', $handle)
            ->whereBetween('date', [$since->toDateString(), $until->toDateString()])
            ->sum('spend');

        $this->line('RECONCILE:');
        $this->line('  stored ad_product_daily[' . $handle . '] (Inventory shows this): ' . number_format($stored, 2));
        $this->line('  FIXED on-product (what it SHOULD be after re-backfill):        ' . number_format($class['fixed']['product'], 2));
        $this->line('  NOTE: the client\'s Ads-Manager number is by ad NAME, so compare it to the NAME-MATCHED total');
        $this->line('  (' . number_format($matchedSpend, 2) . '), not to on-product — the difference is H3, not a data loss.');

        return self::SUCCESS;
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
