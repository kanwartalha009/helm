<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platforms\TikTok\TikTokClient;
use App\Services\PlatformCredentialService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only TikTok diagnostic. The Settings "Test connection" only reports
 * "health check returned false"; this runs the same /bc/get/ call (plus the
 * advertiser listing) and prints the actual TikTok error so we can see exactly
 * why it fails — token, Business Center access, or rate limit.
 *
 *   php artisan tiktok:diagnose
 */
class TikTokDiagnoseCommand extends Command
{
    protected $signature = 'tiktok:diagnose';
    protected $description = 'Surface the real error behind a failing TikTok connection (Settings hides it as "health check returned false").';

    public function handle(TikTokClient $client, PlatformCredentialService $creds): int
    {
        $this->info('TikTok Ads connection diagnostic');
        $this->newLine();

        $this->line('  bc_token  ' . ($creds->has('tiktok', 'bc_token') ? 'set' : 'MISSING'));
        $this->newLine();

        $this->line('Calling /bc/get/ (list Business Centers — the same call the health check makes)...');
        try {
            $bcs = $client->paged('bc/get/');
            $this->info('  OK — ' . count($bcs) . ' Business Center(s)');
        } catch (Throwable $e) {
            $this->error('  FAILED: ' . get_class($e));
            $this->line('  ' . $e->getMessage());
            return self::FAILURE;
        }

        // For each BC, list advertisers — the set the brand picker offers.
        $firstAdv = null;
        foreach ($bcs as $bc) {
            $bcId = (string) ($bc['bc_id'] ?? ($bc['bc_info']['bc_id'] ?? ''));
            if ($bcId === '') {
                continue;
            }
            try {
                // Advertisers are BC assets — bc/asset/get/ (there is no
                // bc/advertiser/get/ endpoint; it 404s).
                $advs = $client->paged('bc/asset/get/', ['bc_id' => $bcId, 'asset_type' => 'ADVERTISER']);
                $this->line('  BC ' . $bcId . ': ' . count($advs) . ' advertiser(s)');
                foreach (array_slice($advs, 0, 15) as $a) {
                    $id       = (string) ($a['asset_id'] ?? $a['advertiser_id'] ?? '?');
                    $firstAdv ??= ($id !== '?' ? $id : null);
                    $this->line(sprintf('    - %s  %s', $id, (string) ($a['asset_name'] ?? $a['advertiser_name'] ?? $a['name'] ?? '')));
                }
            } catch (Throwable $e) {
                $this->warn('  BC ' . $bcId . ' asset list failed: ' . $e->getMessage());
            }
        }

        if ($firstAdv !== null) {
            $this->probeMetrics($client, $firstAdv);
            $this->probeDimensions($client, $firstAdv);
            $this->probeCreatives($client, $firstAdv);
        }

        return self::SUCCESS;
    }

    /**
     * Dump the RAW shape of TikTok's creative-asset endpoints for one real ad, so
     * the thumbnail parser (CreativeFetcher::resolveCreatives) can be pointed at
     * the exact field names this account returns. The blank previews in the grid
     * mean /ad/get or /file/video/ad/info isn't giving us the field we expect —
     * this prints the actual JSON keys instead of us guessing.
     */
    private function probeCreatives(TikTokClient $client, string $advertiserId): void
    {
        $end   = CarbonImmutable::now()->subDay();
        $start = $end->subDays(6);
        $this->newLine();
        $this->line('Probing creative assets (thumbnail/video resolution):');

        // 1. Grab a real ad_id from the last 7 days of ad-level reporting.
        $adId = '';
        try {
            $rep = $client->get('report/integrated/get/', [
                'advertiser_id' => $advertiserId,
                'report_type'   => 'BASIC',
                'data_level'    => 'AUCTION_AD',
                'dimensions'    => json_encode(['ad_id']),
                'metrics'       => json_encode(['spend']),
                'start_date'    => $start->toDateString(),
                'end_date'      => $end->toDateString(),
                'page'          => 1,
                'page_size'     => 5,
            ]);
            foreach (($rep['list'] ?? []) as $r) {
                $adId = (string) ($r['dimensions']['ad_id'] ?? '');
                if ($adId !== '') {
                    break;
                }
            }
        } catch (Throwable $e) {
            $this->line('  ad-level report failed: ' . $e->getMessage());
            return;
        }
        if ($adId === '') {
            $this->line('  no ads with spend in the last 7 days — nothing to probe.');
            return;
        }
        $this->info("  sample ad_id: {$adId}");

        // 2. /ad/get — print the raw object so we can see the real asset field names
        //    (video_id / image_ids vs something else) and their nesting.
        $videoId = '';
        try {
            $ad = $client->get('ad/get/', [
                'advertiser_id' => $advertiserId,
                'ad_ids'        => json_encode([$adId]),
            ]);
            $first = $ad['list'][0] ?? null;
            if (is_array($first)) {
                $this->line('  /ad/get keys: ' . implode(', ', array_keys($first)));
                foreach (['video_id', 'image_ids', 'image_id', 'creative_id', 'material_id', 'identity_id'] as $k) {
                    if (array_key_exists($k, $first)) {
                        $v = is_array($first[$k]) ? json_encode($first[$k]) : (string) $first[$k];
                        $this->line(sprintf('    %-12s = %s', $k, $v));
                        if ($k === 'video_id' && $videoId === '') {
                            $videoId = (string) $first[$k];
                        }
                    }
                }
            } else {
                $this->line('  /ad/get returned no list rows. Raw: ' . substr((string) json_encode($ad), 0, 400));
            }
        } catch (Throwable $e) {
            $this->line('  /ad/get failed: ' . $e->getMessage());
        }

        // 3. /file/video/ad/info — print the raw object so we can see which field is
        //    the poster/cover image URL and which is the playable preview URL.
        if ($videoId !== '') {
            try {
                $v     = $client->get('file/video/ad/info/', [
                    'advertiser_id' => $advertiserId,
                    'video_ids'     => json_encode([$videoId]),
                ]);
                $first = $v['list'][0] ?? null;
                if (is_array($first)) {
                    $this->line('  /file/video/ad/info keys: ' . implode(', ', array_keys($first)));
                    foreach (['poster_url', 'video_cover_url', 'cover_url', 'preview_url', 'url'] as $k) {
                        if (array_key_exists($k, $first)) {
                            $this->line(sprintf('    %-16s = %s', $k, substr((string) $first[$k], 0, 90)));
                        }
                    }
                } else {
                    $this->line('  /file/video/ad/info returned no list rows. Raw: ' . substr((string) json_encode($v), 0, 400));
                }
            } catch (Throwable $e) {
                $this->line('  /file/video/ad/info failed: ' . $e->getMessage());
            }
        } else {
            $this->line('  (no video_id resolved from /ad/get — paste the /ad/get keys above so I can fix the field name)');
        }

        $this->newLine();
        $this->line('  -> Paste this whole block back; I map CreativeFetcher::resolveCreatives to the real field names,');
        $this->line('     then: php artisan tiktok:backfill-creatives "Nude Project" --since=<date>');
    }

    /**
     * Probe AUDIENCE breakdown dimension names (config/tiktok_breakdowns.php) — the
     * region/device/age/gender axes. Prints which return segments + a sample value
     * so the config can be corrected if a name is wrong for the account.
     */
    private function probeDimensions(TikTokClient $client, string $advertiserId): void
    {
        $end   = CarbonImmutable::now()->subDay();
        $start = $end->subDays(6);
        $this->newLine();
        $this->line('Probing AUDIENCE dimensions (breakdown axes):');

        foreach (['country_code', 'age', 'gender', 'platform', 'province_id', 'dma', 'placement'] as $dim) {
            try {
                $data = $client->get('report/integrated/get/', [
                    'advertiser_id' => $advertiserId,
                    'report_type'   => 'AUDIENCE',
                    'data_level'    => 'AUCTION_ADVERTISER',
                    'dimensions'    => json_encode([$dim]),
                    'metrics'       => json_encode(['spend']),
                    'start_date'    => $start->toDateString(),
                    'end_date'      => $end->toDateString(),
                    'page'          => 1,
                    'page_size'     => 10,
                ]);
                $list   = $data['list'] ?? [];
                $sample = $list !== [] ? (string) ($list[0]['dimensions'][$dim] ?? '') : '';
                $this->info(sprintf('  OK   %-14s %d segment(s)  e.g. "%s"', $dim, count($list), $sample));
            } catch (Throwable $e) {
                $this->line(sprintf('  bad  %-14s %s', $dim, $e->getMessage()));
            }
            usleep(200_000);
        }

        $this->newLine();
        $this->line('  -> Map the VALID dimensions in config/tiktok_breakdowns.php');
        $this->line('     (country=>[country_code], device=>[platform], age=>[age], gender=>[gender]), then:');
        $this->line('     php artisan tiktok:backfill-breakdown nude-project --type=all --since=<date>');
    }

    /**
     * Probe candidate report-metric names on one advertiser over the last 7 days.
     * TikTok fails the WHOLE call on an unknown metric, so each candidate is tried
     * ALONE (with spend). The ones that come back VALID with a non-zero 7-day sum
     * are the names to set in config/services.php — tiktok.value_metric for
     * revenue (the €-value one), tiktok.purchase_metric for the purchase count.
     */
    private function probeMetrics(TikTokClient $client, string $advertiserId): void
    {
        $end   = CarbonImmutable::now()->subDay();
        $start = $end->subDays(6);
        $this->newLine();
        $this->line("Probing report metrics on advertiser {$advertiserId} ({$start->toDateString()}..{$end->toDateString()}):");

        $candidates = [
            'reach', 'frequency', 'conversion',
            'complete_payment', 'total_complete_payment',
            'purchase', 'total_purchase', 'total_purchase_value',
            'onsite_shopping', 'total_onsite_shopping_value',
            'value_per_complete_payment', 'total_landing_page_view',
            // native engagement (metadata['tiktok']) — validate these too
            'video_play_actions', 'video_watched_2s', 'video_watched_6s',
            'video_views_p25', 'video_views_p50', 'video_views_p75', 'video_views_p100',
            'likes', 'comments', 'shares', 'follows', 'profile_visits',
            // creatives add-to-cart (CtATC) — pick the one that returns data
            'total_add_to_cart', 'add_to_cart', 'on_web_add_to_cart',
        ];

        foreach ($candidates as $metric) {
            try {
                $data = $client->get('report/integrated/get/', [
                    'advertiser_id' => $advertiserId,
                    'report_type'   => 'BASIC',
                    'data_level'    => 'AUCTION_ADVERTISER',
                    'dimensions'    => json_encode(['advertiser_id']),
                    'metrics'       => json_encode(['spend', $metric]),
                    'start_date'    => $start->toDateString(),
                    'end_date'      => $end->toDateString(),
                    'page'          => 1,
                    'page_size'     => 10,
                ]);
                $sum = 0.0;
                foreach (($data['list'] ?? []) as $row) {
                    $sum += (float) ($row['metrics'][$metric] ?? 0);
                }
                $this->info(sprintf('  OK   %-30s 7d sum = %s', $metric, number_format($sum, 2)));
            } catch (Throwable $e) {
                $this->line(sprintf('  bad  %-30s %s', $metric, $e->getMessage()));
            }
            usleep(200_000);
        }

        $this->newLine();
        $this->line('  -> Put the VALID €-value metric whose 7d sum matches your revenue in config/services.php:');
        $this->line("       'tiktok' => ['value_metric' => '<name>', 'purchase_metric' => 'complete_payment'],");
        $this->line('     then re-sync: php artisan ads:backfill-campaigns nude-project --platform=tiktok --since=<date>');
    }
}
