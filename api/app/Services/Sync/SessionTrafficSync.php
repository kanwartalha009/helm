<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Models\PlatformConnection;
use App\Models\SessionTrafficDaily;
use App\Models\SessionTrafficDay;
use App\Platforms\Shopify\SessionTrafficFetcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes one brand-day of session/traffic-type rows (Bosco item B).
 *
 * ONE code path, shared by the daily sync job, the ranged backfill command and the "Fill missing
 * days" button, so they can never drift into writing different things.
 *
 * Two tables, on purpose:
 *   session_traffic_daily → the BREAKDOWN (per product / collection / store-wide, per traffic type)
 *   session_traffic_days  → the VERDICT   (did this day reconcile? by how much was it short?)
 *
 * The verdict cannot be inferred from the breakdown, and trying to was the bug: a quiet day writes
 * no breakdown rows and is indistinguishable from a day we never pulled, while a FAILED day writes
 * plenty of rows and looks like a success. See SessionDayResult.
 *
 * Best-effort by contract: a ShopifyQL hiccup here must never fail the day's main sync, which has
 * already succeeded by the time we're called.
 */
class SessionTrafficSync
{
    public function __construct(private readonly SessionTrafficFetcher $fetcher) {}

    /**
     * Pull one brand-day, write both tables, and return the VERDICT.
     *
     * Callers must branch on `$result->complete` — NOT on a row count. A day can write 1,847 rows
     * and still be unusable; that is exactly the case that made the repair button report success
     * while the window stayed blank.
     */
    public function syncDay(PlatformConnection $conn, string $day): SessionDayResult
    {
        if ($conn->platform !== 'shopify') {
            return SessionDayResult::failed();
        }

        try {
            $fetched = $this->fetcher->fetchDay($conn, $day);
        } catch (Throwable $e) {
            Log::warning('sync.session_traffic.failed', [
                'brand_id' => $conn->brand_id,
                'date'     => $day,
                'error'    => $e->getMessage(),
            ]);

            // Nothing was established. Touch NEITHER table: overwriting a previously good day with
            // a failure because today's request timed out would be destroying data.
            return SessionDayResult::failed();
        }

        $rows       = $fetched['rows'];
        $isComplete = (bool) $fetched['isComplete'];
        $storeTotal = $fetched['storeTotal'];
        $pagedTotal = (int) $fetched['pagedTotal'];
        // WHY the day failed, straight from the fetcher. Inferring it downstream produced the
        // self-contradicting "adds up to 152,621 (0 missing)" on a day reported as FAILED.
        $reasons    = $fetched['reasons'] ?? [];

        // ══ THE QUIET DAY ══
        // No rows AND reconciled (storeTotal 0 === pagedTotal 0) is a real zero-session day. It must
        // be RECORDED, not skipped. Under the old row-inferred model it wrote nothing, so the read
        // gate could never count it, and any window containing one quiet day was blank FOREVER — a
        // bug no backfill could fix, because the backfill was right: the day was done, and empty.
        //
        // No rows and NOT reconciled means the query or the transport failed and we know nothing.
        // Writing zeroes there would paint a real trading day as a flatline.
        if ($rows === [] && ! $isComplete) {
            Log::warning('sync.session_traffic.unusable_day', [
                'brand_id'    => $conn->brand_id,
                'date'        => $day,
                'store_total' => $storeTotal,
                'paged_total' => $pagedTotal,
            ]);

            $this->recordDay($conn, $day, false, 0, $storeTotal, $pagedTotal);

            return SessionDayResult::failed($storeTotal, $pagedTotal, $reasons);
        }

        $records = $this->dedupeTheWayMysqlWill($conn, $day, $rows);

        // Breakdown + verdict in ONE transaction. If the day row said "complete" while the
        // breakdown it describes was only half-written, every number downstream would be quietly
        // wrong — and it would look right.
        DB::transaction(function () use ($conn, $day, $records, $isComplete, $storeTotal, $pagedTotal): void {
            // ATOMIC REPLACE of the day, not an upsert. A re-sync can legitimately produce FEWER
            // rows than the last one — a product that had traffic yesterday and none today. A plain
            // upsert would leave that stale row standing and the day would keep reporting sessions
            // Shopify no longer reports.
            SessionTrafficDaily::query()
                ->where('brand_id', $conn->brand_id)
                ->where('date', $day)
                ->delete();

            foreach (array_chunk($records, 500) as $chunk) {
                SessionTrafficDaily::insert($chunk);
            }

            $this->recordDay($conn, $day, $isComplete, count($records), $storeTotal, $pagedTotal);
        });

        return SessionDayResult::pulled($isComplete, count($records), $storeTotal, $pagedTotal, $reasons);
    }

    /**
     * The day's verdict. Upserted on (brand_id, date), so a re-pull REPLACES it rather than stacking
     * a second one — a day that failed yesterday and reconciles today must end up complete, not
     * ambiguous.
     */
    private function recordDay(
        PlatformConnection $conn,
        string $day,
        bool $isComplete,
        int $rowsWritten,
        ?int $storeTotal,
        int $pagedTotal,
    ): void {
        SessionTrafficDay::query()->updateOrCreate(
            ['brand_id' => (int) $conn->brand_id, 'date' => $day],
            [
                'workspace_id' => null,   // D-022 seam; populated when tenancy lands
                'is_complete'  => $isComplete,
                'store_total'  => $storeTotal,
                'paged_total'  => $pagedTotal,
                'rows_written' => $rowsWritten,
                'pulled_at'    => now(),
            ],
        );
    }

    /**
     * ══ DEDUPE AGAINST THE DATABASE'S OWN NOTION OF EQUALITY ══
     * The fetcher buckets by (entity_type, entity_key, traffic_type). That is not enough, because
     * PHP and MySQL do not agree on what "the same key" means:
     *
     *   - MySQL's utf8mb4_unicode_ci collation is ACCENT- and CASE-insensitive, so `polo-piqué-x`
     *     and `polo-pique-x` are ONE key to it and two to PHP. That crashed a real backfill with a
     *     1062 duplicate-entry error mid-batch.
     *   - `entity_key` is truncated to 191 chars, so two long handles can converge here even if
     *     they were distinct upstream.
     *
     * LandingPathMapper folds handles to ASCII, fixing the accent case at the source. This is the
     * belt to that pair of braces: fold the key the way the DATABASE will, and SUM on collision.
     * Summing is right — a collision means two spellings of one product, and their sessions belong
     * together. Dropping one, or letting the insert blow up mid-run, both lose real data.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function dedupeTheWayMysqlWill(PlatformConnection $conn, string $day, array $rows): array
    {
        $now   = now();
        $byKey = [];

        foreach ($rows as $r) {
            $entityKey = mb_substr((string) $r['entity_key'], 0, 191);

            $dbKey = mb_strtolower(Str::ascii((string) $r['entity_type']))
                . "\0" . mb_strtolower(Str::ascii($entityKey))
                . "\0" . mb_strtolower(Str::ascii((string) $r['traffic_type']));

            if (isset($byKey[$dbKey])) {
                $byKey[$dbKey]['sessions'] += (int) $r['sessions'];
                continue;
            }

            $byKey[$dbKey] = [
                'brand_id'     => (int) $conn->brand_id,
                'workspace_id' => null,   // D-022 seam; populated when tenancy lands
                'date'         => $day,
                'entity_type'  => (string) $r['entity_type'],
                'entity_key'   => $entityKey,
                'traffic_type' => (string) $r['traffic_type'],
                'sessions'     => (int) $r['sessions'],
                'is_complete'  => (bool) $r['is_complete'],
                'pulled_at'    => $now,
            ];
        }

        return array_values($byKey);
    }
}
