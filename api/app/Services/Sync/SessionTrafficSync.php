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
     * ══ WHY THIS RETURNS ?int AND NOT int ══
     * "We pulled the day and the store had no sessions" and "the pull FAILED" are different facts,
     * and collapsing both to 0 caused a real incident: a malformed ShopifyQL query returned an
     * empty table (ShopifyQL reports a parse error as empty data, not as an exception), every
     * brand-day came back 0, and the backfill dutifully recorded all 90 days × 88 brands as
     * DONE-with-no-data. A later re-run would have skipped them all and the gap would have been
     * permanent and invisible.
     *
     *   null → we learned NOTHING (transport error, parse error, failed reconciliation).
     *          The caller must NOT mark the day as covered. Nothing is written.
     *   0    → we asked, and the store genuinely had no sessions. The day IS done.
     *   >0   → rows written.
     *
     * @return int|null rows written, or null when the day could not be established at all
     */
    public function syncDay(PlatformConnection $conn, string $day): ?int
    {
        if ($conn->platform !== 'shopify') {
            return null;
        }

        try {
            $result = $this->fetcher->fetchDay($conn, $day);
        } catch (Throwable $e) {
            Log::warning('sync.session_traffic.failed', [
                'brand_id' => $conn->brand_id,
                'date'     => $day,
                'error'    => $e->getMessage(),
            ]);

            return null;
        }

        $rows = $result['rows'];
        if ($rows === []) {
            // Empty AND reconciled = a real zero-session day (storeTotal 0 = pagedTotal 0). Done.
            // Empty AND NOT reconciled = the query or the transport failed, and we know nothing.
            // Writing zeroes in the second case would paint a real trading day as a flatline; and
            // reporting it as "done" would bury the failure for good.
            if ($result['isComplete'] === true) {
                return 0;
            }

            Log::warning('sync.session_traffic.unusable_day', [
                'brand_id'    => $conn->brand_id,
                'date'        => $day,
                'store_total' => $result['storeTotal'],
                'paged_total' => $result['pagedTotal'],
            ]);

            return null;
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
