<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\PlatformConnection;
use App\Models\PlatformCredential;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;

/**
 * Deletes only the encrypted rows that can't be decrypted with the current
 * APP_KEY. Healthy rows are untouched. Use after helm:encryption:check
 * confirms drift and you've decided you don't have the old APP_KEY to
 * restore.
 *
 *  - brands.shopify_app → NULLed (the brand row itself is kept)
 *  - platform_connections → deleted (re-do OAuth to recreate)
 *  - platform_credentials → deleted (re-enter from Settings → Platform keys)
 *
 * Usage:
 *   php artisan helm:encryption:purge          # interactive, prompts to confirm
 *   php artisan helm:encryption:purge --force  # skip prompt (for scripts)
 */
class HelmEncryptionPurgeCommand extends Command
{
    protected $signature = 'helm:encryption:purge {--force : Skip confirmation prompt}';
    protected $description = 'Delete encrypted rows that can no longer be decrypted with the current APP_KEY.';

    public function handle(): int
    {
        $brandIds = [];
        $connIds  = [];
        $credIds  = [];

        Brand::query()
            ->whereNotNull('shopify_app')->where('shopify_app', '!=', '')
            ->select(['id', 'slug', 'shopify_app'])
            ->orderBy('id')
            ->chunk(200, function ($rows) use (&$brandIds) {
                foreach ($rows as $b) {
                    try { $b->shopify_app; } catch (DecryptException) { $brandIds[] = $b->id; }
                }
            });

        PlatformConnection::query()
            ->whereNotNull('credentials')
            ->select(['id', 'credentials'])
            ->orderBy('id')
            ->chunk(200, function ($rows) use (&$connIds) {
                foreach ($rows as $c) {
                    try { $c->credentials; } catch (DecryptException) { $connIds[] = $c->id; }
                }
            });

        PlatformCredential::query()
            ->whereNotNull('value')
            ->select(['id', 'value'])
            ->orderBy('id')
            ->chunk(200, function ($rows) use (&$credIds) {
                foreach ($rows as $pc) {
                    try { $pc->value; } catch (DecryptException) { $credIds[] = $pc->id; }
                }
            });

        $totalAffected = count($brandIds) + count($connIds) + count($credIds);
        if ($totalAffected === 0) {
            $this->info('Nothing to purge — every encrypted column is decryptable.');
            return self::SUCCESS;
        }

        $this->line('Will wipe:');
        $this->line('  • ' . count($brandIds) . ' brand(s) shopify_app (column set to NULL)');
        $this->line('  • ' . count($connIds)  . ' platform_connection row(s) (full delete)');
        $this->line('  • ' . count($credIds)  . ' platform_credential row(s) (full delete)');

        if (! $this->option('force') && ! $this->confirm('Proceed?', false)) {
            $this->warn('Aborted.');
            return self::FAILURE;
        }

        DB::transaction(function () use ($brandIds, $connIds, $credIds) {
            if ($brandIds !== []) {
                Brand::whereIn('id', $brandIds)->update(['shopify_app' => null]);
            }
            if ($connIds !== []) {
                PlatformConnection::whereIn('id', $connIds)->delete();
            }
            if ($credIds !== []) {
                PlatformCredential::whereIn('id', $credIds)->delete();
            }
        });

        $this->info('Purged. Re-enter Platform keys in Settings and re-do the Shopify OAuth on each brand.');
        return self::SUCCESS;
    }
}
