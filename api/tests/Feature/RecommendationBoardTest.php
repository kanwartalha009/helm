<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Recommendation;
use App\Models\User;
use App\Services\Ledger\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GO-3.2 — the Stop/Scale/Fix board.
 *
 * The load-bearing test here is the LAST one: accepting a recommendation must make ZERO
 * outbound HTTP calls. Helm never touches a client's ad account (doctrine §2), and the
 * one way that promise silently dies is someone "helpfully" wiring Accept to the Meta
 * API one day. Http::fake() + assertNothingSent() is the tripwire.
 */
final class RecommendationBoardTest extends TestCase
{
    use RefreshDatabase;

    private function brand(): Brand
    {
        return Brand::factory()->create(['base_currency' => 'USD', 'timezone' => 'UTC', 'status' => 'active']);
    }

    private function rec(Brand $b, string $kind = 'pause'): Recommendation
    {
        return app(Ledger::class)->record(
            $b, 'ad_audit', $kind, 'brand', 'meta',
            'Meta: Pause 3 losing campaigns',
            ['rule' => 'ad_audit.stop', 'wasteUsd' => 1200, 'thresholdRoas' => 1.0],
            'solid',
            outcomeMetric: 'spend_waste',
            baselineValue: 1200,
        );
    }

    public function test_board_lists_open_recommendations_with_their_evidence(): void
    {
        $b = $this->brand();
        $this->rec($b);
        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));

        $res = $this->getJson("/api/brands/{$b->slug}/recommendations")->assertOk()->json();

        $this->assertCount(1, $res['rows']);
        $row = $res['rows'][0];
        $this->assertSame('pause', $row['kind']);
        $this->assertSame('open', $row['status']);
        // The operator agrees on the EVIDENCE, not on Helm's say-so.
        $this->assertEqualsWithDelta(1200.0, $row['evidence']['wasteUsd'], 0.01);
        $this->assertSame('ad_audit.stop', $row['evidence']['rule']);
        // And the board states, every render, that accepting changes nothing upstream.
        $this->assertStringContainsString('never touches your ad accounts', $res['executionNote']);
    }

    public function test_accept_records_intent_and_returns_the_operator_checklist(): void
    {
        $b = $this->brand();
        $r = $this->rec($b);
        $user = User::factory()->create(['role' => 'manager']);
        Sanctum::actingAs($user);

        $res = $this->postJson("/api/brands/{$b->slug}/recommendations/{$r->id}/accept")->assertOk()->json();

        $this->assertSame('accepted', $res['status']);
        $this->assertNotEmpty($res['checklist']);   // what the HUMAN must now go and do
        $this->assertStringContainsString('pause the campaign', strtolower(implode(' ', $res['checklist'])));

        $r->refresh();
        $this->assertSame('accepted', $r->status);
        $this->assertSame($user->id, $r->status_by_user_id);
        $this->assertNotNull($r->status_at);   // the timestamp the outcome is measured from
    }

    public function test_dismiss_requires_a_reason(): void
    {
        $b = $this->brand();
        $r = $this->rec($b);
        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));

        $this->postJson("/api/brands/{$b->slug}/recommendations/{$r->id}/dismiss", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->postJson("/api/brands/{$b->slug}/recommendations/{$r->id}/dismiss", ['reason' => 'Deliberate brand-awareness spend.'])
            ->assertOk();

        $this->assertSame('dismissed', $r->fresh()->status);
        $this->assertStringContainsString('awareness', $r->fresh()->status_reason);
    }

    public function test_a_decided_recommendation_cannot_be_re_decided(): void
    {
        $b = $this->brand();
        $r = $this->rec($b);
        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));

        $this->postJson("/api/brands/{$b->slug}/recommendations/{$r->id}/accept")->assertOk();

        // Terminal is terminal: you cannot re-decide once the result is known. The
        // ledger's state machine rejects it and the API surfaces that as a 422.
        $this->postJson("/api/brands/{$b->slug}/recommendations/{$r->id}/accept")
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'Illegal ledger transition'));
    }

    public function test_team_members_can_read_the_board_but_not_decide(): void
    {
        $b  = $this->brand();
        $r  = $this->rec($b);
        $tm = User::factory()->create(['role' => 'team_member']);
        $b->users()->attach($tm->id);

        Sanctum::actingAs($tm);

        // Attached → can SEE the advice (403 on the action, not 404 on the brand).
        $this->getJson("/api/brands/{$b->slug}/recommendations")->assertOk();

        // Agreeing to advice on a client's account is a decision, not a view.
        $this->postJson("/api/brands/{$b->slug}/recommendations/{$r->id}/accept")->assertForbidden();
        $this->postJson("/api/brands/{$b->slug}/recommendations/{$r->id}/dismiss", ['reason' => 'nope'])->assertForbidden();

        $this->assertSame('open', $r->fresh()->status);
    }

    public function test_accepting_makes_no_outbound_calls_helm_never_touches_the_ad_account(): void
    {
        // THE DOCTRINE TEST (§2). Accept records intent. It must not pause a campaign,
        // move a budget, or contact Meta/Google/TikTok in any way. If someone ever wires
        // this button to an ad platform, this test goes red.
        Http::fake();

        $b = $this->brand();
        $r = $this->rec($b, 'scale');
        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));

        $this->postJson("/api/brands/{$b->slug}/recommendations/{$r->id}/accept")->assertOk();

        Http::assertNothingSent();
        $this->assertSame('accepted', $r->fresh()->status);
    }
}
