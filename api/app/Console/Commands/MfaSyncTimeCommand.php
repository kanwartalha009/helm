<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\NtpTime;
use Illuminate\Console\Command;

/**
 * Refresh (and report) the NTP clock offset used for TOTP verification.
 *
 * NtpTime already caches the offset for an hour, so at most one live request
 * per hour pays the ≤2s NTP lookup. Running this from cron keeps even that off
 * the request path and gives ops a one-liner to SEE the drift on a host we
 * can't NTP-sync at the OS level:
 *
 *   php artisan mfa:sync-time
 *   # cron (hourly):  0 * * * *  cd /path/api && php artisan mfa:sync-time
 */
final class MfaSyncTimeCommand extends Command
{
    protected $signature = 'mfa:sync-time';

    protected $description = 'Refresh the NTP clock offset used for 2FA (TOTP) verification and report the drift.';

    public function handle(NtpTime $ntp): int
    {
        if (! (bool) config('helm.mfa.ntp.enabled', true)) {
            $this->warn('NTP correction is disabled (HELM_MFA_NTP=false) — TOTP uses the raw system clock.');

            return self::SUCCESS;
        }

        $offset = $ntp->refresh();

        $this->info(sprintf(
            'NTP offset refreshed: %+ds (system clock is %s true time by %ds).',
            $offset,
            $offset >= 0 ? 'behind' : 'ahead of',
            abs($offset),
        ));

        if (abs($offset) >= 15) {
            $this->warn('Drift ≥ 15s — TOTP would be unreliable WITHOUT this correction. The app-level offset now compensates.');
        }

        return self::SUCCESS;
    }
}
