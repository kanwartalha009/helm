<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Playbook\PlaybookPhysics;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * GO-4.2 — the playbook physics.
 *
 * The load-bearing test is the last one: **no constant may exist without a source.**
 *
 * GO-4.3 puts these numbers into client plans. An unfootnoted number in a client deck is
 * indistinguishable from an invented one — which is exactly the "generic advice" problem
 * that cost every incumbent its credibility. So provenance is enforced in code: adding an
 * unsourced constant makes the suite go red rather than quietly shipping an unattributable
 * claim into someone's Black Friday plan.
 */
final class PlaybookPhysicsTest extends TestCase
{
    private function physics(): PlaybookPhysics
    {
        return app(PlaybookPhysics::class);
    }

    public function test_the_master_plan_constants_are_present_with_the_right_values(): void
    {
        $p = $this->physics();

        // Timeline (§7.2).
        $this->assertSame(8, $p->value('preheat_weeks_start'));
        $this->assertSame(4, $p->value('preheat_weeks_creative_locked'));
        $this->assertSame(72, $p->value('build_lead_hours'));
        $this->assertSame(5, $p->value('judgment_days_min'));
        $this->assertSame(21, $p->value('post_event_phase_days'));

        // Budget.
        $this->assertSame([2.0, 4.0], $p->range('event_budget_ramp'));
        $this->assertSame([0, 20], [$p->range('cpm_spike_scenarios')[0], $p->range('cpm_spike_scenarios')[1]]);
        $this->assertSame([0, 10, 20], $p->value('cpm_spike_scenarios'));

        // Creative + channel context.
        $this->assertSame(7, $p->value('min_event_creatives'));
        $this->assertSame([30, 40], $p->range('email_share_of_event_revenue'));
    }

    public function test_every_constant_carries_a_source(): void
    {
        // THE INVARIANT. Nothing reaches a client plan unattributed.
        $all = $this->physics()->all();

        $this->assertNotEmpty($all);

        foreach ($all as $key => $c) {
            $this->assertNotEmpty($c['source'], "Playbook constant '{$key}' has no source.");
            $this->assertNotEmpty($c['label'], "Playbook constant '{$key}' has no label.");
        }
    }

    public function test_an_unsourced_constant_throws_rather_than_shipping_quietly(): void
    {
        // If a future edit adds a number without a citation, it must FAIL — not silently
        // end up in a client deck as an unattributable claim.
        config()->set('playbooks.physics.rogue_number', ['value' => 42, 'unit' => 'x', 'label' => 'Invented']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/has no source/');
        $this->physics()->get('rogue_number');
    }

    public function test_helm_defaults_say_so_in_plain_words(): void
    {
        // Where no published standard exists we admit it. Pretending to a citation we do
        // not have would be worse than admitting we are guessing.
        $c = $this->physics()->get('post_event_phase_days');
        $this->assertStringContainsString('[HELM DEFAULT]', $c['source']);
        $this->assertStringContainsString('no published standard', $c['source']);
    }

    public function test_the_cpm_scenarios_are_disclosed_as_a_floor_not_a_forecast(): void
    {
        // Modelling +0/10/20% is a STRESS TEST of the margin, not a prediction of CPMs:
        // observed BFCM CPMs run +50–150%. If the CAC ceiling breaks at +20%, it will
        // shatter at +100% — and the source string has to say so, or the plan implies a
        // forecast it cannot make.
        $c = $this->physics()->get('cpm_spike_scenarios');
        $this->assertStringContainsString('+50–150%', $c['source']);
        $this->assertStringContainsString('floor, not a forecast', $c['source']);
    }

    public function test_cite_renders_the_number_with_its_provenance(): void
    {
        // This is exactly what a plan block footnote looks like.
        $c = $this->physics()->cite('preheat_weeks_start');

        $this->assertSame('preheat_weeks_start', $c['key']);
        $this->assertStringContainsString('8 weeks', $c['text']);
        $this->assertStringContainsString('Start warming audiences', $c['text']);
        $this->assertStringContainsString('Top Growth Marketing', $c['source']);

        // A range renders as a range.
        $this->assertStringContainsString('2–4 × baseline', $this->physics()->cite('event_budget_ramp')['text']);
    }

    public function test_an_unknown_constant_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->physics()->value('does_not_exist');
    }
}
