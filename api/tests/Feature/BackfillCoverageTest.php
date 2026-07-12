<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Brand;
use App\Support\BackfillCoverage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Day-level backfill resume.
 *
 * The stakes: get this wrong in one direction and an interrupted 18-hour backfill repeats itself
 * from scratch. Get it wrong in the other and a day is marked done that was never pulled — a
 * permanent hole that no future run will ever look at again, invisible in the UI because a missing
 * row and a genuinely-zero day look identical.
 */
final class BackfillCoverageTest extends TestCase
{
    use RefreshDatabase;

    private function brand(): Brand
    {
        return Brand::factory()->create(['status' => 'active', 'timezone' => 'UTC']);
    }

    private function coverage(): BackfillCoverage
    {
        return app(BackfillCoverage::class);
    }

    public function test_pending_days_are_everything_not_yet_marked(): void
    {
        $b = $this->brand();
        $c = $this->coverage();

        $this->assertCount(10, $c->pendingDays($b->id, 'ad_spend', 'meta', '2026-01-01', '2026-01-10'));

        $c->mark($b->id, 'ad_spend', 'meta', '2026-01-01', '2026-01-04', 42);

        $pending = $c->pendingDays($b->id, 'ad_spend', 'meta', '2026-01-01', '2026-01-10');
        $this->assertSame(['2026-01-05', '2026-01-06', '2026-01-07', '2026-01-08', '2026-01-09', '2026-01-10'], $pending);
    }

    public function test_a_day_that_returned_no_data_is_DONE_not_missing(): void
    {
        // THE reason this ledger exists. Every backfill writes no rows for a day the platform had
        // nothing for (a paused ad account, a quiet Sunday). If "done" were inferred from the
        // presence of rows, those days would be re-pulled on every run for the rest of time —
        // and they are exactly the days that cost an API call and return nothing.
        $b = $this->brand();
        $c = $this->coverage();

        $c->mark($b->id, 'ad_spend', 'meta', '2026-01-01', '2026-01-03', rowsWritten: 0);

        $this->assertSame([], $c->pendingDays($b->id, 'ad_spend', 'meta', '2026-01-01', '2026-01-03'));
        $this->assertSame(3, $c->doneCount($b->id, 'ad_spend', 'meta', '2026-01-01', '2026-01-03'));
    }

    public function test_scopes_and_datasets_do_not_bleed_into_each_other(): void
    {
        // Marking Meta done must not make Google look done — that would silently skip an entire
        // platform's history.
        $b = $this->brand();
        $c = $this->coverage();

        $c->mark($b->id, 'ad_spend', 'meta', '2026-01-01', '2026-01-05', 10);

        $this->assertSame([], $c->pendingDays($b->id, 'ad_spend', 'meta', '2026-01-01', '2026-01-05'));
        $this->assertCount(5, $c->pendingDays($b->id, 'ad_spend', 'google', '2026-01-01', '2026-01-05'));
        $this->assertCount(5, $c->pendingDays($b->id, 'campaigns', 'meta', '2026-01-01', '2026-01-05'));
    }

    public function test_brands_do_not_bleed_into_each_other(): void
    {
        $a = $this->brand();
        $b = $this->brand();
        $c = $this->coverage();

        $c->mark($a->id, 'creatives', 'meta', '2026-01-01', '2026-01-05', 3);

        $this->assertSame([], $c->pendingDays($a->id, 'creatives', 'meta', '2026-01-01', '2026-01-05'));
        $this->assertCount(5, $c->pendingDays($b->id, 'creatives', 'meta', '2026-01-01', '2026-01-05'));
    }

    public function test_pending_chunks_rebuild_contiguous_windows_around_the_gaps(): void
    {
        // A run that died mid-way leaves a hole. The resumed run must re-chunk around it and pull
        // ONLY the hole — not restart, and not skip the brand wholesale the way `--missing` does.
        $b = $this->brand();
        $c = $this->coverage();

        // Days 1-3 and 8-10 are done; 4-7 are the hole.
        $c->mark($b->id, 'campaigns', 'meta', '2026-01-01', '2026-01-03', 5);
        $c->mark($b->id, 'campaigns', 'meta', '2026-01-08', '2026-01-10', 5);

        $chunks = $c->pendingChunks($b->id, 'campaigns', 'meta', '2026-01-01', '2026-01-10', 31);

        $this->assertSame([['2026-01-04', '2026-01-07']], $chunks);
    }

    public function test_pending_chunks_respect_the_max_window_size(): void
    {
        // --chunk-days exists because Meta rejects long windows on busy accounts. Resuming must
        // not quietly widen the window and reintroduce that failure.
        $b = $this->brand();
        $c = $this->coverage();

        $chunks = $c->pendingChunks($b->id, 'creatives', 'meta', '2026-01-01', '2026-01-10', 7);

        $this->assertSame([['2026-01-01', '2026-01-07'], ['2026-01-08', '2026-01-10']], $chunks);
        foreach ($chunks as [$from, $to]) {
            $days = (int) \Carbon\CarbonImmutable::parse($from)->diffInDays(\Carbon\CarbonImmutable::parse($to)) + 1;
            $this->assertLessThanOrEqual(7, $days);
        }
    }

    public function test_nothing_pending_when_the_whole_window_is_covered(): void
    {
        $b = $this->brand();
        $c = $this->coverage();

        $c->mark($b->id, 'session_traffic', '', '2026-01-01', '2026-01-31', 100);

        $this->assertSame([], $c->pendingChunks($b->id, 'session_traffic', '', '2026-01-01', '2026-01-31', 31));
    }

    public function test_force_forgets_a_window_so_it_is_pulled_again(): void
    {
        $b = $this->brand();
        $c = $this->coverage();

        $c->mark($b->id, 'creatives', 'meta', '2026-01-01', '2026-01-10', 20);
        $this->assertSame([], $c->pendingDays($b->id, 'creatives', 'meta', '2026-01-01', '2026-01-10'));

        // --force only clears the window asked for; days outside it stay done.
        $c->forget($b->id, 'creatives', 'meta', '2026-01-04', '2026-01-06');

        $this->assertSame(
            ['2026-01-04', '2026-01-05', '2026-01-06'],
            $c->pendingDays($b->id, 'creatives', 'meta', '2026-01-01', '2026-01-10'),
        );
    }

    public function test_marking_the_same_day_twice_updates_rather_than_duplicating(): void
    {
        // A re-run must not grow the table without bound.
        $b = $this->brand();
        $c = $this->coverage();

        $c->mark($b->id, 'commerce', 'product', '2026-01-01', '2026-01-01', 5);
        $c->mark($b->id, 'commerce', 'product', '2026-01-01', '2026-01-01', 9);

        $this->assertSame(1, DB::table('backfill_coverage')->count());
        $this->assertSame(9, (int) DB::table('backfill_coverage')->value('rows_written'));
    }

    public function test_the_empty_scope_is_a_real_scope_and_stays_unique(): void
    {
        // `scope` is NOT NULL default '' precisely because MySQL treats NULLs as DISTINCT in a
        // unique index — a nullable scope would let the same brand-dataset-day be recorded many
        // times, and the resume check would silently stop working.
        $b = $this->brand();
        $c = $this->coverage();

        $c->mark($b->id, 'session_traffic', '', '2026-01-01', '2026-01-01', 1);
        $c->mark($b->id, 'session_traffic', '', '2026-01-01', '2026-01-01', 1);

        $this->assertSame(1, DB::table('backfill_coverage')->where('scope', '')->count());
    }
}
