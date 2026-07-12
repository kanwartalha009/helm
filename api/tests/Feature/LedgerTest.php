<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Anomaly;
use App\Models\Brand;
use App\Models\Recommendation;
use App\Models\User;
use App\Services\Ledger\Ledger;
use App\Services\Ledger\LedgerRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * GO-2.5 — THE LEDGER. The tests that matter here are the ones that protect the
 * ledger's central promise: it cannot be curated after the fact.
 *
 * A comment saying "insert-only" is a suggestion. These tests are the rule. If any of
 * them ever go red, the track record has stopped being evidence and become marketing.
 */
final class LedgerTest extends TestCase
{
    use RefreshDatabase;

    private function brand(): Brand
    {
        return Brand::factory()->create(['base_currency' => 'USD', 'timezone' => 'UTC', 'status' => 'active']);
    }

    private function rec(Brand $b): Recommendation
    {
        return app(Ledger::class)->record(
            $b, 'ad_audit', 'pause', 'brand', 'meta',
            'Meta: Pause 3 losing campaigns',
            ['rule' => 'ad_audit.stop', 'wasteUsd' => 1200, 'threshold' => 1.0],
            'solid',
            outcomeMetric: 'spend_waste',
            baselineValue: 1200,
        );
    }

    // ── The sacred guarantees ────────────────────────────────────────────────────

    public function test_evidence_cannot_be_rewritten_after_the_fact(): void
    {
        $r = $this->rec($this->brand());

        // Rewriting the evidence would let a bad call be dressed up as a good one once
        // the outcome is known. The model throws.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/insert-only/');
        $r->update(['evidence' => ['rule' => 'totally different', 'wasteUsd' => 0]]);
    }

    public function test_the_facts_of_a_recommendation_are_frozen(): void
    {
        $r = $this->rec($this->brand());

        foreach (['source' => 'x', 'kind' => 'scale', 'title' => 'nicer title', 'confidence' => 'early', 'baseline_value' => 1] as $col => $val) {
            try {
                $r->update([$col => $val]);
                $this->fail("Expected {$col} to be immutable.");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('insert-only', $e->getMessage());
                $r->refresh();
            }
        }
    }

    public function test_recommendations_cannot_be_deleted(): void
    {
        $r = $this->rec($this->brand());

        // A deletable ledger is a ledger that can be tidied. It cannot.
        $this->expectException(RuntimeException::class);
        $r->delete();
    }

    public function test_an_outcome_is_measured_once_and_cannot_be_regraded(): void
    {
        $ledger = app(Ledger::class);
        $r = $this->rec($this->brand());
        $ledger->transition($r, 'accepted', User::factory()->create());

        $ledger->measure($r, 'worsened', value14d: 0.8);
        $this->assertSame('worsened', $r->fresh()->outcome);

        // Re-grading a loss into a win is precisely what this table exists to prevent.
        $this->expectException(RuntimeException::class);
        $ledger->measure($r->fresh(), 'improved', value14d: 3.0);
    }

    public function test_unmeasurable_is_an_honest_outcome_not_a_dropped_row(): void
    {
        // The campaign vanished. Recording 'unmeasurable' keeps the row in the
        // denominator; silently dropping it would flatter the win-rate.
        $ledger = app(Ledger::class);
        $r = $this->rec($this->brand());
        $ledger->transition($r, 'accepted', User::factory()->create());
        $ledger->measure($r, 'unmeasurable');

        $this->assertSame('unmeasurable', $r->fresh()->outcome);
        $this->assertSame(1, Recommendation::count());   // still counted, not deleted
    }

    // ── The state machine ────────────────────────────────────────────────────────

    public function test_illegal_transitions_are_rejected(): void
    {
        $ledger = app(Ledger::class);
        $user   = User::factory()->create();
        $r      = $this->rec($this->brand());

        $ledger->transition($r, 'accepted', $user);
        $this->assertSame('accepted', $r->fresh()->status);

        // Terminal is terminal: you cannot reopen a decision once you know the result.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Illegal ledger transition/');
        $ledger->transition($r->fresh(), 'open', $user);
    }

    public function test_dismissal_requires_a_reason(): void
    {
        $ledger = app(Ledger::class);
        $r = $this->rec($this->brand());

        // A dismissal with no stated reason is how an engine buries its misses.
        try {
            $ledger->transition($r, 'dismissed', User::factory()->create(), reason: '   ');
            $this->fail('Expected a dismissal without a reason to be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('requires a reason', $e->getMessage());
        }

        $ledger->transition($r->fresh(), 'dismissed', User::factory()->create(), reason: 'Seasonal — we expect this.');
        $this->assertSame('dismissed', $r->fresh()->status);
    }

    public function test_a_correction_is_a_new_row_not_an_edit(): void
    {
        $ledger = app(Ledger::class);
        $b = $this->brand();
        $old = $this->rec($b);
        $ledger->transition($old, 'dismissed', User::factory()->create(), reason: 'Wrong call.');

        $new = $ledger->supersede($old->fresh(), 'Meta: actually, scale these', ['rule' => 'ad_audit.scale', 'note' => 'corrected']);

        $this->assertSame($old->id, $new->supersedes_id);
        $this->assertSame(2, Recommendation::count());
        // The original still says exactly what it said when it was written.
        $this->assertSame('Meta: Pause 3 losing campaigns', $old->fresh()->title);
    }

    public function test_evidence_is_mandatory(): void
    {
        // A recommendation nobody can check is a recommendation nobody should trust.
        $this->expectException(InvalidArgumentException::class);
        app(Ledger::class)->record($this->brand(), 'ad_audit', 'pause', 'brand', 'meta', 'No evidence', []);
    }

    // ── The silent writers ───────────────────────────────────────────────────────

    public function test_recorder_logs_open_anomalies_with_their_evidence(): void
    {
        $b = $this->brand();

        Anomaly::create([
            'brand_id' => $b->id, 'date' => CarbonImmutable::now()->subDay()->toDateString(),
            'kind' => 'roas_drop', 'subject' => '', 'severity' => 'warn',
            'evidence' => ['rule' => 'roas_drop', 'actual' => 1.5, 'median28d' => 3.0, 'deltaPct' => -50, 'thresholdPct' => 35],
        ]);

        $written = app(LedgerRecorder::class)->recordForBrand($b->fresh());
        $this->assertGreaterThanOrEqual(1, $written);

        $r = Recommendation::where('brand_id', $b->id)->where('source', 'anomaly')->firstOrFail();
        $this->assertSame('open', $r->status);
        // The anomaly's numbers ARE the recommendation's evidence — nothing re-derived.
        $this->assertEqualsWithDelta(1.5, $r->evidence['actual'], 0.01);
        $this->assertEqualsWithDelta(3.0, $r->evidence['median28d'], 0.01);
        $this->assertSame(35, (int) $r->evidence['thresholdPct']);
    }

    public function test_recording_is_idempotent_while_the_advice_is_still_open(): void
    {
        $b = $this->brand();
        Anomaly::create([
            'brand_id' => $b->id, 'date' => CarbonImmutable::now()->subDay()->toDateString(),
            'kind' => 'roas_drop', 'subject' => '', 'severity' => 'warn',
            'evidence' => ['rule' => 'roas_drop', 'actual' => 1.5, 'median28d' => 3.0],
        ]);

        $recorder = app(LedgerRecorder::class);
        $recorder->recordForBrand($b->fresh());
        $recorder->recordForBrand($b->fresh());
        $recorder->recordForBrand($b->fresh());

        // Saying the same thing nightly would dilute the acceptance rate with hundreds
        // of duplicate rows and make the track record meaningless.
        $this->assertSame(1, Recommendation::where('brand_id', $b->id)->where('source', 'anomaly')->count());
    }

    public function test_ledger_grows_after_a_scan_on_seeded_data(): void
    {
        // The master plan's proof: row count grows after a daily scan.
        $b = $this->brand();
        DB::table('platform_connections')->insert([
            'brand_id' => $b->id, 'platform' => 'meta', 'external_id' => 'a', 'credentials' => '{}',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        Anomaly::create([
            'brand_id' => $b->id, 'date' => CarbonImmutable::now()->subDay()->toDateString(),
            'kind' => 'zero_delivery', 'subject' => 'meta', 'severity' => 'critical',
            'evidence' => ['rule' => 'zero_delivery', 'todaySpend' => 0],
        ]);

        $this->assertSame(0, Recommendation::count());
        app(LedgerRecorder::class)->recordForBrand($b->fresh());
        $this->assertGreaterThan(0, Recommendation::count());
    }
}
