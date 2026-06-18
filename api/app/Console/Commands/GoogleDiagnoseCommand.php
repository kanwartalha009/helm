<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platforms\Google\GoogleAdsClient;
use App\Services\PlatformCredentialService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only Google Ads diagnostic. The Settings "Test connection" only shows
 * "health check returned false" because the adapter's healthCheck() swallows the
 * real error. This runs the same call (listAccessibleCustomers) and prints the
 * actual exception — developer-token, OAuth/grant, permission, or transport — so
 * we can see exactly why it fails.
 *
 *   php artisan google:diagnose
 */
class GoogleDiagnoseCommand extends Command
{
    protected $signature = 'google:diagnose';
    protected $description = 'Surface the real error behind a failing Google Ads connection (Settings hides it as "health check returned false").';

    public function handle(GoogleAdsClient $client, PlatformCredentialService $creds): int
    {
        $this->info('Google Ads connection diagnostic');
        $this->newLine();

        // Which credentials are present (presence only, never the values).
        foreach (['client_id', 'client_secret', 'refresh_token', 'developer_token', 'login_customer_id'] as $k) {
            $this->line(sprintf('  %-18s %s', $k, $creds->has('google', $k) ? 'set' : 'MISSING'));
        }
        $this->newLine();

        $this->line('Calling listAccessibleCustomers() (the same call the health check makes)...');
        try {
            $ids = $client->listAccessibleCustomers();
            $this->info('  OK — ' . count($ids) . ' accessible customer(s): ' . implode(', ', array_slice($ids, 0, 30)));
            $this->newLine();
            $this->line('If this succeeds but Settings still shows Failed, redeploy so the');
            $this->line('REST-transport build is live, then Test connection again.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('  FAILED: ' . get_class($e));
            $this->line('  ' . $e->getMessage());

            // The SDK wraps the underlying Google/transport error as the previous
            // exception — that's where the actionable detail lives.
            $prev = $e->getPrevious();
            while ($prev !== null) {
                $this->newLine();
                $this->line('  caused by: ' . get_class($prev));
                $this->line('  ' . $prev->getMessage());
                $prev = $prev->getPrevious();
            }

            return self::FAILURE;
        }
    }
}
