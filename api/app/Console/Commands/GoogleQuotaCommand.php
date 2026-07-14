<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platforms\Google\GoogleAdsClient;
use Illuminate\Console\Command;

/**
 * Is Google Ads locked out, and for how long?
 *
 * The quota that bit us is scoped to the DEVELOPER TOKEN, not to a brand or a customer account:
 * Basic access allows 15,000 operations PER DAY across EVERY brand, every backfill and every manual
 * "sync now". Exhaust it and Google locks the token out — for us, 5,067 seconds — at which point
 * ALL 200+ brands fail, and every retry just walks into the same wall.
 *
 * The client now trips a breaker on the first RESOURCE_EXHAUSTED and fails fast until it clears.
 * This is how you see that state instead of guessing at it.
 *
 *   php artisan google:quota            # how long until Google Ads works again
 *   php artisan google:quota --clear    # force it open (e.g. Standard access just granted)
 */
class GoogleQuotaCommand extends Command
{
    protected $signature = 'google:quota {--clear : force the breaker open}';

    protected $description = 'Show (or clear) the Google Ads developer-token quota breaker.';

    public function handle(): int
    {
        if ((bool) $this->option('clear')) {
            GoogleAdsClient::clearQuotaBreaker();
            $this->info('Breaker cleared. Google Ads calls will be attempted again immediately.');
            $this->warn('If the token is still exhausted, the next call will simply re-trip it — that is correct.');

            return self::SUCCESS;
        }

        $seconds = GoogleAdsClient::quotaBlockedForSeconds();

        if ($seconds === 0) {
            $this->info('Google Ads quota: OK — not blocked.');

            return self::SUCCESS;
        }

        $this->error(sprintf(
            'Google Ads quota EXHAUSTED. Blocked for another %d minute(s) (%d seconds).',
            (int) ceil($seconds / 60),
            $seconds,
        ));
        $this->newLine();
        $this->line('This is a DEVELOPER-TOKEN limit, shared across all brands — not a per-brand one.');
        $this->line('Basic access = 15,000 operations/day. With 200+ brands that is not enough.');
        $this->newLine();
        $this->line('THE FIX: apply for Standard access in the Google Ads API Center.');
        $this->line('  Google Ads UI → Tools → API Center → "Apply for Standard access".');
        $this->line('  Standard access lifts the cap. Approval is typically 1–3 business days.');

        return self::FAILURE;
    }
}
