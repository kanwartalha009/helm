<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Brand;
use App\Services\Sync\CreativeThumbnailRefresher;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Refresh creative thumbnails BEFORE they expire.
 *
 * `ad_creative_daily.thumbnail_url` is a short-lived signed CDN link on both Meta and TikTok. The
 * daily sync writes only today's rows, so an ad that ran three weeks ago — still visible and still
 * spending in the 30-day Creatives view — keeps a URL that quietly dies, and the card goes blank.
 *
 * This re-resolves the assets for every ad in the display window and writes the fresh URL onto ALL
 * of that ad's rows. Assets only: no insights call, so no reporting quota is spent. Ads whose URL
 * is still fresh are skipped, so a re-run is nearly free.
 *
 * Scheduled daily (bootstrap/app.php). Run by hand after a creative backfill to top the URLs up.
 *
 *   php artisan creatives:refresh-thumbnails                      # all active brands
 *   php artisan creatives:refresh-thumbnails nude-project
 *   php artisan creatives:refresh-thumbnails --days=90 --stale-hours=0   # force-refresh everything
 */
class CreativesRefreshThumbnailsCommand extends Command
{
    protected $signature = 'creatives:refresh-thumbnails '
        . '{brand? : slug or id; omit for all active brands} '
        . '{--days=35 : how far back the Creatives view can look (30D tab + headroom)} '
        . '{--stale-hours=20 : refresh an ad whose assets were last pulled longer ago than this; 0 = force all}';

    protected $description = 'Re-resolve Meta/TikTok creative thumbnails for ads in the display window, before the CDN links expire.';

    public function handle(CreativeThumbnailRefresher $refresher): int
    {
        $days  = max(1, (int) $this->option('days'));
        $stale = max(0, (int) $this->option('stale-hours'));

        $brands = $this->resolveBrands();
        if ($brands->isEmpty()) {
            $this->warn('No matching brands.');

            return self::SUCCESS;
        }

        $total = 0;
        foreach ($brands as $brand) {
            $n = $refresher->refresh($brand, $days, $stale);
            if ($n > 0) {
                $this->info("· {$brand->name}: {$n} creative row(s) re-thumbnailed.");
            }
            $total += $n;
        }

        $this->info("Done. {$total} row(s) refreshed across {$brands->count()} brand(s).");

        return self::SUCCESS;
    }

    /** @return Collection<int, Brand> */
    private function resolveBrands(): Collection
    {
        $arg = $this->argument('brand');

        if ($arg === null) {
            return Brand::query()->with('connections')->where('status', 'active')->orderBy('name')->get();
        }

        $argStr = (string) $arg;
        $lower  = strtolower(trim($argStr));

        $brand = is_numeric($argStr)
            ? Brand::query()->with('connections')->find((int) $argStr)
            : (Brand::query()->with('connections')
                ->whereRaw('LOWER(slug) = ?', [$lower])
                ->orWhereRaw('LOWER(name) = ?', [$lower])
                ->first()
                ?: Brand::query()->with('connections')
                    ->where('name', 'like', '%' . $argStr . '%')
                    ->orWhere('slug', 'like', '%' . $argStr . '%')
                    ->first());

        return collect($brand ? [$brand] : []);
    }
}
