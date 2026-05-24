<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\DailyMetric;
use App\Models\PlatformConnection;
use App\Models\SyncLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Nuke one brand and everything attached to it — connections, daily metrics,
 * sync logs. Used to wipe broken state from the OAuth experiments before the
 * manual-token flow shipped. Idempotent: running twice on the same slug is
 * harmless.
 *
 *   php artisan helm:reset:brand roasdriven-ps5nm1le
 *   php artisan helm:reset:brand --all-broken    # clears everything with status=errored
 */
class HelmResetBrandCommand extends Command
{
    protected $signature = 'helm:reset:brand
                            {slug? : Brand slug to delete (omit when using --all-broken)}
                            {--all-broken : Delete every brand whose Shopify connection is errored}
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Delete a brand and its connections, metrics, and sync logs.';

    public function handle(): int
    {
        if ($this->option('all-broken')) {
            return $this->deleteAllBroken();
        }

        $slug = (string) $this->argument('slug');
        if ($slug === '') {
            $this->error('Pass a slug, or use --all-broken.');
            return self::INVALID;
        }

        $brand = Brand::withoutGlobalScopes()->where('slug', $slug)->first();
        if (! $brand) {
            $this->error("Brand '{$slug}' not found.");
            return self::FAILURE;
        }

        $this->deleteOne($brand);
        return self::SUCCESS;
    }

    private function deleteAllBroken(): int
    {
        $brands = Brand::withoutGlobalScopes()
            ->whereHas('connections', fn ($q) => $q->where('status', 'errored'))
            ->get();

        if ($brands->isEmpty()) {
            $this->info('No broken brands to delete.');
            return self::SUCCESS;
        }

        $this->warn("Found {$brands->count()} brand(s) with errored Shopify connections:");
        foreach ($brands as $b) {
            $this->line("  - {$b->slug} ({$b->name})");
        }

        if (! $this->option('force') && ! $this->confirm('Delete all of them and their metrics/logs?')) {
            $this->info('Cancelled.');
            return self::SUCCESS;
        }

        foreach ($brands as $brand) {
            $this->deleteOne($brand, silent: true);
        }
        $this->info("Deleted {$brands->count()} brand(s).");
        return self::SUCCESS;
    }

    private function deleteOne(Brand $brand, bool $silent = false): void
    {
        if (! $silent && ! $this->option('force')) {
            if (! $this->confirm("Delete brand '{$brand->slug}' and all related data?")) {
                $this->info('Cancelled.');
                return;
            }
        }

        DB::transaction(function () use ($brand) {
            DailyMetric::query()->where('brand_id', $brand->id)->delete();
            SyncLog::query()->where('brand_id', $brand->id)->delete();
            PlatformConnection::query()->where('brand_id', $brand->id)->delete();
            $brand->forceDelete();
        });

        if (! $silent) {
            $this->info("Deleted brand '{$brand->slug}' + metrics + sync logs + connections.");
        }
    }
}
