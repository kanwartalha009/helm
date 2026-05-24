<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\PlatformConnection;
use App\Models\PlatformCredential;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;

/**
 * Walks every encrypted column in the database and reports rows whose
 * ciphertext can't be decrypted with the current APP_KEY. In practice
 * this only happens when APP_KEY has been rotated (or .env has drifted
 * between processes) — Laravel's MAC check fires before the AES decrypt,
 * so the error surfaces as "The MAC is invalid."
 *
 * Run after any of:
 *   - "Couldn't load brands / credentials: 500"
 *   - sync_logs showing "The MAC is invalid."
 *   - moving the app between machines / containers
 *
 * Usage: php artisan helm:encryption:check
 */
class HelmEncryptionCheckCommand extends Command
{
    protected $signature = 'helm:encryption:check';
    protected $description = 'Audit every encrypted column for APP_KEY drift / decrypt failures.';

    public function handle(): int
    {
        $appKey = config('app.key');
        $this->line('APP_KEY in use: ' . (is_string($appKey)
            ? substr($appKey, 0, 12) . '… (length ' . strlen($appKey) . ')'
            : '(none)'));
        $this->newLine();

        $totalCorrupt = 0;
        $totalChecked = 0;

        // ── brands.shopify_app (encrypted:array) ────────────────────────
        $this->line('<info>brands.shopify_app</info>');
        $corruptBrands = [];
        Brand::query()
            ->whereNotNull('shopify_app')
            ->where('shopify_app', '!=', '')
            ->select(['id', 'slug', 'shopify_app'])
            ->orderBy('id')
            ->chunk(200, function ($rows) use (&$corruptBrands, &$totalChecked) {
                foreach ($rows as $b) {
                    $totalChecked++;
                    try {
                        // Force the cast through the model.
                        $b->shopify_app;
                    } catch (DecryptException) {
                        $corruptBrands[] = "  • brand id={$b->id} slug={$b->slug}";
                    }
                }
            });
        if ($corruptBrands === []) {
            $this->line('  ok — all rows decryptable');
        } else {
            $this->error('  ' . count($corruptBrands) . ' row(s) FAILED decrypt:');
            foreach ($corruptBrands as $line) {
                $this->line($line);
            }
            $totalCorrupt += count($corruptBrands);
        }
        $this->newLine();

        // ── platform_connections.credentials (encrypted:array) ──────────
        $this->line('<info>platform_connections.credentials</info>');
        $corruptConns = [];
        PlatformConnection::query()
            ->whereNotNull('credentials')
            ->select(['id', 'brand_id', 'platform', 'external_id', 'credentials'])
            ->orderBy('id')
            ->chunk(200, function ($rows) use (&$corruptConns, &$totalChecked) {
                foreach ($rows as $c) {
                    $totalChecked++;
                    try {
                        $c->credentials;
                    } catch (DecryptException) {
                        $corruptConns[] = "  • connection id={$c->id} brand_id={$c->brand_id} platform={$c->platform} external_id={$c->external_id}";
                    }
                }
            });
        if ($corruptConns === []) {
            $this->line('  ok — all rows decryptable');
        } else {
            $this->error('  ' . count($corruptConns) . ' row(s) FAILED decrypt:');
            foreach ($corruptConns as $line) {
                $this->line($line);
            }
            $totalCorrupt += count($corruptConns);
        }
        $this->newLine();

        // ── platform_credentials.value (encrypted scalar) ───────────────
        $this->line('<info>platform_credentials.value</info>');
        $corruptCreds = [];
        PlatformCredential::query()
            ->whereNotNull('value')
            ->select(['id', 'platform', 'key', 'status', 'value'])
            ->orderBy('id')
            ->chunk(200, function ($rows) use (&$corruptCreds, &$totalChecked) {
                foreach ($rows as $pc) {
                    $totalChecked++;
                    try {
                        $pc->value;
                    } catch (DecryptException) {
                        $corruptCreds[] = "  • credential id={$pc->id} platform={$pc->platform} key={$pc->key} status={$pc->status}";
                    }
                }
            });
        if ($corruptCreds === []) {
            $this->line('  ok — all rows decryptable');
        } else {
            $this->error('  ' . count($corruptCreds) . ' row(s) FAILED decrypt:');
            foreach ($corruptCreds as $line) {
                $this->line($line);
            }
            $totalCorrupt += count($corruptCreds);
        }
        $this->newLine();

        $this->line("Checked: {$totalChecked} encrypted column(s) across 3 tables.");
        if ($totalCorrupt === 0) {
            $this->info("All encrypted data is decryptable. APP_KEY is consistent.");
            return self::SUCCESS;
        }

        $this->error("Found {$totalCorrupt} undecryptable row(s).");
        $this->line('');
        $this->line('Cause: APP_KEY in your current .env does not match the key used to encrypt these rows.');
        $this->line('Recover by one of:');
        $this->line('  1) Restore the previous APP_KEY (check git history / a .env backup) and restart the app + Horizon.');
        $this->line('  2) Wipe the corrupt rows and re-enter credentials:');
        $this->line('       php artisan helm:encryption:purge   # interactive; only deletes corrupt rows');
        return self::FAILURE;
    }
}
