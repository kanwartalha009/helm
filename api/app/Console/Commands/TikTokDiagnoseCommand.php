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
        }

        return self::SUCCESS;
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
