<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Models\PlatformConnection;
use App\Models\SessionTrafficDaily;
use App\Platforms\Shopify\SessionTrafficFetcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes one brand-day of session/traffic-type rows (Bosco item B).
 *
 * ONE code path, shared by the daily sync job and the ranged backfill command, so the two can
 * never drift into writing different things. (The existing funnel sync duplicates its upsert in
 * both places; that is a bug waiting to happen, not a pattern to copy.)
 *
 * Best-effort by contract: a ShopifyQL hiccup here must never fail the day's main sync, which
 * has already succeeded by the time we're called.
 */
class SessionTrafficSync
{
    public function __construct(private readonly SessionTrafficFetcher $fetcher) {}

    /**
     * @return int the number of rows written (0 = nothing stored; the day stays as it was)
     */
    public function syncDay(PlatformConnection $conn, string $day): int
    {
        if ($conn->platform !== 'shopify') {
            return 0;
        }

        try {
            $result = $this->fetcher->fetchDay($conn, $day);
        } catch (Throwable $e) {
            Log::warning('sync.session_traffic.failed', [
                'brand_id' => $conn->brand_id,
                'date'     => $day,
                'error'    => $e->getMessage(),
            ]);

            return 0;
        }

        $rows = $result['rows'];
        if ($rows === []) {
            // No rows at all. This is NOT "zero sessions" — it is "we learned nothing".
            // Writing zeroes here would paint a real trading day as a flatline, so we
            // write nothing and the read surface shows "—" for a day it has no row for.
            return 0;
        }

        $now     = now();
        $records = [];

        foreach ($rows as $r) {
            $records[] = [
                'brand_id'     => (int) $conn->brand_id,
                'workspace_id' => null,   // D-022 seam; populated when tenancy lands
                'date'         => $day,
                'entity_type'  => (string) $r['entity_type'],
                'entity_key'   => mb_substr((string) $r['entity_key'], 0, 191),
                'traffic_type' => (string) $r['traffic_type'],
                'sessions'     => (int) $r['sessions'],
                'is_complete'  => (bool) $r['is_complete'],
                'pulled_at'    => $now,
            ];
        }

        // ATOMIC REPLACE of the day, not an upsert.
        //
        // A re-sync can legitimately produce FEWER rows than the last one — a product that had
        // traffic yesterday's pull and none in today's. A plain upsert would leave that stale
        // row standing and the day would keep reporting sessions Shopify no longer reports.
        //
        // Deleting only the rows we "didn't touch this run" via a pulled_at comparison does NOT
        // work: pulled_at is second-precision, so a re-run inside the same second would touch
        // nothing and delete everything, or delete nothing at all. Replacing the day inside a
        // transaction is deterministic and has no such race. Scoped to one brand-day, so the
        // blast radius is the day we just re-fetched in full.
        DB::transaction(function () use ($conn, $day, $records): void {
            SessionTrafficDaily::query()
                ->where('brand_id', $conn->brand_id)
                ->where('date', $day)
                ->delete();

            foreach (array_chunk($records, 500) as $chunk) {
                SessionTrafficDaily::insert($chunk);
            }
        });

        return count($records);
    }
}
