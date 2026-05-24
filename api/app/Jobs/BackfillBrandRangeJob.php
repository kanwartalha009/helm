<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Brand;
use App\Models\PlatformConnection;
use App\Services\DateRange\DateRangeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatches one SyncBrandDayJob per day in the range for every active
 * connection on the brand. Used by `php artisan brand:backfill` and by the
 * API endpoint POST /api/brands/{id}/backfill.
 */
class BackfillBrandRangeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Brand $brand,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
    ) {
        // Light fan-out job — sits on the default queue.
        $this->onQueue('default');
    }

    public function handle(DateRangeResolver $dates): void
    {
        $connections = PlatformConnection::query()
            ->where('brand_id', $this->brand->id)
            ->where('status', 'active')
            ->get();

        foreach ($connections as $conn) {
            foreach ($dates->eachDay($this->from, $this->to) as $day) {
                SyncBrandDayJob::dispatch($this->brand, $conn, $day);
            }
        }
    }
}
