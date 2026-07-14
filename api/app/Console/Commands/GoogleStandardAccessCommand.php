<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\PlatformCredentialService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Print the exact values the Google Ads API "Apply for Standard access" form asks for.
 *
 * Both live in the DB-backed credentials already (PlatformCredentialService), so there is no reason
 * to go hunting through Google Cloud Console for them — and every reason not to, because typing the
 * wrong one gets the application rejected and Basic access (15,000 ops/day across ALL 200+ brands)
 * stays in force.
 *
 *   php artisan google:standard-access
 */
class GoogleStandardAccessCommand extends Command
{
    protected $signature = 'google:standard-access';

    protected $description = 'Print the Google Cloud project number + MCC ID needed for the Standard access application.';

    public function handle(PlatformCredentialService $credentials): int
    {
        try {
            $clientId = (string) $credentials->get('google', 'client_id');
            $mcc      = (string) $credentials->get('google', 'login_customer_id');
        } catch (Throwable $e) {
            $this->error('Could not read Google credentials from the DB: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('Google Ads API — "Apply for Standard access" form');
        $this->line(str_repeat('─', 62));
        $this->newLine();

        /*
         * ══ THE PROJECT NUMBER IS INSIDE THE OAUTH CLIENT ID ══
         * A Google OAuth client id is literally:
         *
         *     <project-number>-<random>.apps.googleusercontent.com
         *      ^^^^^^^^^^^^^^
         *
         * The form is explicit that it wants the 11–12 digit NUMBER, not the alphanumeric project
         * ID — and those are different things that people mix up constantly. The leading digits of
         * the client id ARE the number, so we can hand it over exactly rather than approximately.
         */
        $projectNumber = preg_match('/^(\d{10,13})-/', $clientId, $m) === 1 ? $m[1] : null;

        $this->line('2. Google Cloud project number (11–12 digits)');
        if ($projectNumber !== null) {
            $this->info('   ' . $projectNumber);
            $this->line('   (taken from the leading digits of the OAuth client id we authenticate with —');
            $this->line('    a client id is <project-number>-<random>.apps.googleusercontent.com)');
        } else {
            $this->warn('   COULD NOT DERIVE IT.');
            $this->line('   The stored client_id does not look like a standard Google OAuth client id,');
            $this->line('   so I will not guess. Find it in Google Cloud Console → the project picker');
            $this->line('   at the top → the "Project number" column (NOT the Project ID).');
        }

        $this->newLine();

        // The MCC id must be the one the DEVELOPER TOKEN belongs to — which, for us, is the same
        // account we set as login-customer-id on every API call. Formatted with dashes because the
        // form validates that shape.
        $digits = preg_replace('/\D/', '', $mcc) ?? '';

        $this->line('3. Google Ads manager account (MCC) ID');
        if (strlen($digits) === 10) {
            $this->info('   ' . substr($digits, 0, 3) . '-' . substr($digits, 3, 3) . '-' . substr($digits, 6, 4));
            $this->line('   (this is the login-customer-id we send on every Google Ads call)');
        } elseif ($digits !== '') {
            $this->warn('   Stored value has ' . strlen($digits) . ' digits, not 10: ' . $digits);
            $this->line('   An MCC id is always 10 digits. Check the credential before submitting.');
        } else {
            $this->error('   NOT SET. google.login_customer_id is empty in platform credentials.');
        }

        $this->newLine();
        $this->line(str_repeat('─', 62));
        $this->line('Why this matters: Basic access caps the DEVELOPER TOKEN at 15,000 operations');
        $this->line('per day — shared across all 200+ brands, every backfill and every manual sync.');
        $this->line('That is the cap that took the syncs down. Standard access removes it.');

        return self::SUCCESS;
    }
}
