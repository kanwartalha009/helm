<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platforms\TikTok\TikTokClient;
use App\Services\PlatformCredentialService;
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
        foreach ($bcs as $bc) {
            $bcId = (string) ($bc['bc_id'] ?? ($bc['bc_info']['bc_id'] ?? ''));
            if ($bcId === '') {
                continue;
            }
            try {
                $advs = $client->paged('bc/advertiser/get/', ['bc_id' => $bcId]);
                $this->line('  BC ' . $bcId . ': ' . count($advs) . ' advertiser(s)');
                foreach (array_slice($advs, 0, 15) as $a) {
                    $this->line(sprintf(
                        '    - %s  %s  %s',
                        (string) ($a['advertiser_name'] ?? $a['name'] ?? '?'),
                        (string) ($a['advertiser_id'] ?? '?'),
                        (string) ($a['currency'] ?? '')
                    ));
                }
            } catch (Throwable $e) {
                $this->warn('  BC ' . $bcId . ' advertiser list failed: ' . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
