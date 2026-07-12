<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Playbook physics (master plan §7.2, upgrade U3) — GO-4.2
|--------------------------------------------------------------------------
| The numbers that make a seasonal plan a STRATEGIST'S plan rather than LLM vibes.
|
| The whitespace (assessment §2: nobody in the market does this) is only winnable if the
| plans contain real, sourced numbers. "Start pre-heating 8 weeks out and lock creative by
| week 4" is a claim. "Ramp aggressively and test lots of creative" is a horoscope.
|
| ══ PROVENANCE TRAVELS WITH THE NUMBER ══
| Every constant is a STRUCTURE, not a scalar: {value, unit, label, source}. The source is
| DATA, not a comment, because GO-4.3 must footnote every number it puts in a client plan.
| A source that lives only in a code comment cannot be rendered next to the figure it
| justifies — and an unfootnoted number in a client deck is exactly the "generic advice"
| failure mode that cost every incumbent its credibility.
|
| Where no published standard exists, the source says `[HELM DEFAULT]` in plain words.
| Pretending we have a citation we don't have would be worse than admitting we're guessing.
|
| SOURCES:
|   TGM — Top Growth Marketing, BFCM guide (topgrowthmarketing.com/bfcm)
|   CTC — Common Thread Co, "the 6-week window" (commonthreadco.com, May 2026 + Dec 2024)
|   Motion — Motion Creative Benchmarks 2026
|   Observed — BFCM CPMs run +50–150% vs October (TGM; Motion benchmarks)
*/

return [

    'physics' => [

        // ── Timeline ──────────────────────────────────────────────────────────────
        'preheat_weeks_start' => [
            'value'  => 8,
            'unit'   => 'weeks',
            'label'  => 'Start warming audiences and testing creative',
            'source' => 'Top Growth Marketing BFCM guide; Common Thread Co "6-week window" (2026)',
        ],

        'preheat_weeks_creative_locked' => [
            'value'  => 4,
            'unit'   => 'weeks',
            'label'  => 'Creative pre-tested and locked by now',
            // The reason this matters: anything still in learning at peak is learning at
            // the most expensive CPMs of the year.
            'source' => 'Top Growth Marketing — creative pre-tested by T-4w so nothing sits in learning at peak CPMs',
        ],

        'build_lead_hours' => [
            'value'  => 72,
            'unit'   => 'hours',
            'label'  => 'Campaigns built and submitted before launch',
            'source' => 'Top Growth Marketing — ad review queues lengthen before peak; build ≥72h ahead',
        ],

        'judgment_days_min' => [
            'value'  => 5,
            'unit'   => 'days',
            'label'  => 'Minimum window before judging a campaign',
            // Killing a campaign on day 2 of a peak event is judging noise.
            'source' => 'Top Growth Marketing — ≥5-day judgment window',
        ],

        'post_event_phase_days' => [
            'value'  => 21,
            'unit'   => 'days',
            'label'  => 'Returning-customer phase after the event',
            'source' => '[HELM DEFAULT] — no published standard; the post-peak retention window we plan to',
        ],

        // ── Budget ────────────────────────────────────────────────────────────────
        'event_budget_ramp' => [
            'value'  => [2.0, 4.0],
            'unit'   => '× baseline',
            'label'  => 'Budget ramp during the event',
            'source' => 'Top Growth Marketing — 2–4× baseline spend across the event window',
        ],

        'cpm_spike_scenarios' => [
            'value'  => [0, 10, 20],
            'unit'   => '% CPM increase',
            'label'  => 'CAC-ceiling scenarios to model before the spike',
            // Deliberately CONSERVATIVE against the observed range: BFCM CPMs run +50–150%.
            // Modelling 0/10/20% is a stress test of the margin, not a forecast of CPMs —
            // if the CAC ceiling breaks at +20%, it will shatter at +100%.
            'source' => 'Common Thread Co method (model CAC ceilings at CPM +0/+10/+20%). Observed BFCM CPMs run +50–150% vs October (TGM; Motion 2026) — so these are a floor, not a forecast',
        ],

        // ── Creative ──────────────────────────────────────────────────────────────
        'min_event_creatives' => [
            'value'  => 7,
            'unit'   => 'creatives',
            'label'  => 'Minimum event-ready creatives',
            'source' => 'Top Growth Marketing — 7–10+ event-ready creatives minimum; Motion Creative Benchmarks 2026',
        ],

        // ── Channel context ───────────────────────────────────────────────────────
        'email_share_of_event_revenue' => [
            'value'  => [30, 40],
            'unit'   => '% of event revenue',
            'label'  => 'Share of event revenue email typically carries',
            // This is CONTEXT for sizing the email plan, not a target to hit. It is also
            // why GO-1.1 (Klaviyo) had to exist before seasonal plans could be complete.
            'source' => 'Top Growth Marketing — email carries 30–40% of BFCM revenue',
        ],
    ],

];
