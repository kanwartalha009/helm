<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Recommendation;
use App\Models\User;
use App\Services\Ledger\Ledger;
use App\Services\Ledger\OutcomeMeasurer;
use App\Services\Ledger\TrackRecord;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GO-3.3 — Helm grades itself.
 *
 * The tests that matter are the ones where Helm LOSES. A measurement function that can
 * only produce "improved" generates a win-rate that means nothing — a lie with a decimal
 * point. So: an accepted pause that was never actually carried out must NOT be a win; a
 * scale that tanked ROAS must be recorded as worsened; a vanished subject must be
 * 'unmeasurable' and must stay in the denominator.
 */
final class TrackRecordTest extends TestCase
{
    use RefreshDatabase;

    private const DECIDED = '2026-05-01';   // the day the operator decided
    private const NOW     = '2026-06-15';   // >30 days later, so both windows are readable

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::NOW . ' 09:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function brand(): Brand
    {
        return Brand::factory()->create(['base_currency' => 'USD', 'timezone' => 'UTC', 'status' => 'active']);
    }

    /** Meta spend/value on a day. */
    private function metaDay(Brand $b, string $date, float $spend, float $value = 0): void
    {
        DB::table('daily_metrics')->insert([
            'brand_id' => $b->id, 'platform' => 'meta', 'date' => $date,
            'spend' => $spend, 'conversion_value' => $value, 'conversions' => 1,
            'currency' => 'USD', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
        ]);
    }

    private function run(Brand $b, string $range, float $spend, float $value = 0): void
    {
        [$from, $days] = explode('|', $range);
        $d = CarbonImmutable::parse($from);
        for ($i = 0; $i < (int) $days; $i++) {
            $this->metaDay($b, $d->addDays($i)->toDateString(), $spend, $value);
        }
    }

    /** An ACCEPTED recommendation, decided on DECIDED. */
    private function accepted(Brand $b, string $kind, string $metric, ?float $baseline): Recommendation
    {
        $ledger = app(Ledger::class);
        $r = $ledger->record(
            $b, 'ad_audit', $kind, 'brand', 'meta', ucfirst($kind) . ' meta',
            ['rule' => 'test'], 'solid', outcomeMetric: $metric, baselineValue: $baseline,
        );
        $ledger->transition($r, 'accepted', User::factory()->create());
        // Backdate the decision so the 14/30d windows are already readable.
        DB::table('recommendations')->where('id', $r->id)->update(['status_at' => self::DECIDED . ' 09:00:00']);

        return $r->fresh();
    }

    // ── Where Helm WINS ──────────────────────────────────────────────────────────

    public function test_an_accepted_pause_that_was_actually_carried_out_is_a_win(): void
    {
        $b = $this->brand();
        // 14 days of €100/day BEFORE the decision …
        $this->run($b, '2026-04-17|14', 100);
        // … and the spend stops after it. The waste was avoided.
        $this->accepted($b, 'pause', 'spend_waste', 1400);

        app(OutcomeMeasurer::class)->run();

        $this->assertSame('improved', Recommendation::first()->outcome);
    }

    // ── Where Helm LOSES (the tests that make the number worth anything) ─────────

    public function test_an_accepted_pause_that_was_never_actually_paused_is_NOT_a_win(): void
    {
        // The operator clicked Accept and then never paused it. Spend kept flowing.
        // The waste was NOT avoided, and Helm does not get to book a win for advice
        // nobody carried out.
        $b = $this->brand();
        $this->run($b, '2026-04-17|14', 100);           // before: €100/day
        $this->run($b, self::DECIDED . '|14', 100);     // after:  €100/day — unchanged

        $this->accepted($b, 'pause', 'spend_waste', 1400);
        app(OutcomeMeasurer::class)->run();

        $this->assertSame('worsened', Recommendation::first()->outcome);
    }

    public function test_a_scale_that_tanked_roas_is_recorded_as_worsened(): void
    {
        $b = $this->brand();
        // Baseline ROAS 3.0×. After scaling, ROAS collapses to 1.0× — we were wrong.
        $this->run($b, self::DECIDED . '|30', 200, 200);   // value/spend = 1.0×

        $this->accepted($b, 'scale', 'roas', 3.0);
        app(OutcomeMeasurer::class)->run();

        $this->assertSame('worsened', Recommendation::first()->outcome);
    }

    public function test_a_scale_that_held_roas_is_recorded_as_improved(): void
    {
        $b = $this->brand();
        $this->run($b, self::DECIDED . '|30', 200, 800);   // 4.0× vs a 3.0× baseline

        $this->accepted($b, 'scale', 'roas', 3.0);
        app(OutcomeMeasurer::class)->run();

        $this->assertSame('improved', Recommendation::first()->outcome);
    }

    public function test_a_small_move_is_honestly_flat(): void
    {
        // 3.15× vs a 3.0× baseline = +5%, inside the 10% material band. Claiming that as
        // a win would be noise dressed up as skill.
        $b = $this->brand();
        $this->run($b, self::DECIDED . '|30', 100, 315);

        $this->accepted($b, 'scale', 'roas', 3.0);
        app(OutcomeMeasurer::class)->run();

        $this->assertSame('flat', Recommendation::first()->outcome);
    }

    public function test_a_vanished_subject_is_unmeasurable_and_stays_in_the_denominator(): void
    {
        // No rows at all after the decision — the campaign was deleted. We cannot prove
        // we helped. Recording it honestly costs win-rate; dropping the row would inflate it.
        $b = $this->brand();
        $this->accepted($b, 'fix', 'roas', 2.0);

        app(OutcomeMeasurer::class)->run();
        $this->assertSame('unmeasurable', Recommendation::first()->outcome);

        $t = app(TrackRecord::class)->compute([$b->id]);
        $this->assertSame(1, $t['measured']);          // still counted
        $this->assertSame(1, $t['unmeasurable']);
        $this->assertEqualsWithDelta(0.0, $t['improvedPct'], 0.01);   // 0 of 1 improved
    }

    // ── Expiry + the headline maths ──────────────────────────────────────────────

    public function test_undecided_advice_expires_and_still_counts_in_the_total(): void
    {
        $b = $this->brand();
        $r = app(Ledger::class)->record($b, 'ad_audit', 'fix', 'brand', 'meta', 'Old advice', ['rule' => 't']);
        DB::table('recommendations')->where('id', $r->id)->update(['created_at' => '2026-04-01 09:00:00']);

        app(OutcomeMeasurer::class)->run();

        $this->assertSame('expired', $r->fresh()->status);

        // Pretending it was never made would flatter the acceptance rate.
        $t = app(TrackRecord::class)->compute([$b->id]);
        $this->assertSame(1, $t['total']);
        $this->assertSame(1, $t['expired']);
        $this->assertNull($t['acceptedPct']);   // nothing was DECIDED, so no rate to state
    }

    public function test_track_record_maths_hand_verified(): void
    {
        // 4 recommendations: 2 accepted (1 improved, 1 worsened), 1 dismissed, 1 open.
        // acceptedPct = 2 accepted / 3 decided = 66.7%   (open is not "decided")
        // improvedPct = 1 improved / 2 measured = 50.0%
        $b = $this->brand();
        $ledger = app(Ledger::class);
        $user = User::factory()->create();

        $win = $this->accepted($b, 'scale', 'roas', 3.0);
        $this->run($b, self::DECIDED . '|30', 100, 600);   // 6.0× → improved
        $lose = $this->accepted($b, 'fix', 'roas', 3.0);
        $lose->update(['subject_id' => 'google']);          // no google rows → unmeasurable

        $d = $ledger->record($b, 'ad_audit', 'fix', 'brand', 'tiktok', 'x', ['rule' => 't']);
        $ledger->transition($d, 'dismissed', $user, 'Not a real problem.');

        $ledger->record($b, 'ad_audit', 'pause', 'product', 'p1', 'y', ['rule' => 't']);  // stays open

        app(OutcomeMeasurer::class)->run();

        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));
        $t = $this->getJson("/api/brands/{$b->slug}/track-record")->assertOk()->json();

        $this->assertSame(4, $t['total']);
        $this->assertSame(2, $t['accepted']);
        $this->assertSame(1, $t['dismissed']);
        $this->assertSame(1, $t['open']);
        $this->assertEqualsWithDelta(66.7, $t['acceptedPct'], 0.1);   // 2 of 3 decided
        $this->assertSame(2, $t['measured']);
        $this->assertSame(1, $t['improved']);
        $this->assertEqualsWithDelta(50.0, $t['improvedPct'], 0.1);   // 1 of 2 measured
        $this->assertStringContainsString('never cached', $t['note']);
    }

    public function test_improved_pct_is_null_not_zero_before_anything_is_measured(): void
    {
        // "No data yet" and "0% success" are very different claims.
        $b = $this->brand();
        app(Ledger::class)->record($b, 'ad_audit', 'fix', 'brand', 'meta', 'x', ['rule' => 't']);

        $t = app(TrackRecord::class)->compute([$b->id]);
        $this->assertNull($t['improvedPct']);
        $this->assertSame(0, $t['measured']);
    }

    public function test_an_outcome_is_never_regraded_on_a_second_run(): void
    {
        $b = $this->brand();
        $this->run($b, '2026-04-17|14', 100);
        $this->accepted($b, 'pause', 'spend_waste', 1400);

        app(OutcomeMeasurer::class)->run();
        $first = Recommendation::first()->outcome;

        // Re-running must not re-grade. A loss can never quietly become a win.
        app(OutcomeMeasurer::class)->run();
        $this->assertSame($first, Recommendation::first()->outcome);
    }
}
