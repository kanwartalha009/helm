<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platforms\Meta\MetaClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only Meta diagnostic. Uses the stored org System User token to show
 * exactly what it can see — identity, businesses, and ad accounts — so we can
 * tell "token is bad" apart from "no ad accounts assigned to the system user"
 * apart from "accounts are partner-shared (client_ad_accounts) not owned".
 *
 *   php artisan meta:diagnose
 */
class MetaDiagnoseCommand extends Command
{
    protected $signature = 'meta:diagnose';
    protected $description = 'Show what the Meta System User token can see (identity, businesses, ad accounts). Read-only.';

    public function handle(MetaClient $client): int
    {
        $this->info('Meta System User token diagnostic');
        $this->newLine();

        // 1. Identity — proves the token authenticates and shows who it is.
        try {
            $me = $client->get('me', ['fields' => 'id,name']);
            $this->line('me: ' . ($me['name'] ?? '?') . '  (id ' . ($me['id'] ?? '?') . ')');
        } catch (Throwable $e) {
            $this->error('me failed: ' . $e->getMessage());
            return self::FAILURE;
        }
        $this->newLine();

        // 2. Ad accounts directly visible to the system user — this is the exact
        //    edge the brand picker lists. Zero here = nothing to attach.
        try {
            $accts = $client->paged('me/adaccounts', [
                'fields' => 'account_id,name,currency,account_status',
                'limit'  => 200,
            ]);
            $this->line('me/adaccounts: ' . count($accts) . ' account(s) — this is what the brand picker shows');
            foreach ($accts as $a) {
                $this->line(sprintf(
                    '  - %s  act_%s  %s  status=%s',
                    $a['name'] ?? '?',
                    $a['account_id'] ?? '?',
                    $a['currency'] ?? '?',
                    $a['account_status'] ?? '?'
                ));
            }
            if (count($accts) === 0) {
                $this->warn('  → Zero. The token authenticates but no ad accounts are assigned to the Helm Sync system user.');
            }
        } catch (Throwable $e) {
            $this->error('me/adaccounts failed: ' . $e->getMessage());
        }
        $this->newLine();

        // 3. Businesses the system user belongs to — context for whether the
        //    accounts are owned by this BM or shared in from a client BM.
        try {
            $bizzes = $client->paged('me/businesses', ['fields' => 'id,name', 'limit' => 100]);
            $this->line('me/businesses: ' . count($bizzes));
            foreach ($bizzes as $b) {
                $this->line('  - ' . ($b['name'] ?? '?') . '  (id ' . ($b['id'] ?? '?') . ')');

                // 4. Per business: owned vs client (partner-shared) ad accounts.
                foreach (['owned_ad_accounts', 'client_ad_accounts'] as $edge) {
                    try {
                        $rows = $client->paged($b['id'] . '/' . $edge, [
                            'fields' => 'account_id,name,currency',
                            'limit'  => 200,
                        ]);
                        $this->line('      ' . $edge . ': ' . count($rows));
                    } catch (Throwable $e) {
                        $this->line('      ' . $edge . ': error — ' . $e->getMessage());
                    }
                }
            }
        } catch (Throwable $e) {
            $this->warn('me/businesses failed (needs business_management scope): ' . $e->getMessage());
        }
        $this->newLine();

        $this->line('If me/adaccounts is 0 but a business above lists owned/client accounts, the accounts exist');
        $this->line('but are not assigned to the system user — assign them: Business settings → Users →');
        $this->line('System users → Helm Sync → Add assets → Ad accounts → tick → Full control → Save.');

        return self::SUCCESS;
    }
}
