<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The Stop/Scale/Fix board (master plan §6.2) — GO-3.2
|--------------------------------------------------------------------------
| DOCTRINE (master plan §2, §10): **Helm NEVER writes to ad platforms.** Accepting a
| recommendation records INTENT — it does not pause a campaign, move a budget, or touch
| an ad account in any way. There is no code path from this board to Meta, Google or
| TikTok, and there is not going to be one (the sole future exception is GO-5b's paused
| DRAFT creatives, which is separately gated on Kanwar approving a write scope).
|
| So the board owes the operator two things, honestly:
|   1. It must never imply that clicking Accept changed something. It didn't.
|   2. It must tell them exactly what to go and do themselves.
|
| That is what `checklists` are: the human steps for each kind of advice. They are here
| in config, not buried in a component, because they are operational knowledge that will
| get tuned by the people doing the work.
|
| Why "Accept" is still worth recording even though it executes nothing: it is the
| operator stating INTENT, and intent is what the outcome (GO-3.3) is measured against.
| "We said pause, you agreed, and 30 days later the waste was gone" is a claim the
| ledger can make. Without the accept step there is nothing to score.
*/

return [

    'kind_labels' => [
        'pause'            => 'Stop',
        'scale'            => 'Scale',
        'fix'              => 'Fix',
        'budget_shift'     => 'Shift budget',
        'creative_refresh' => 'Refresh creative',
        'launch'           => 'Launch',
        'investigate'      => 'Investigate',
    ],

    // The board groups by these, in this order. Money bleeding first.
    'kind_order' => ['pause', 'fix', 'budget_shift', 'creative_refresh', 'scale', 'launch', 'investigate'],

    // What the OPERATOR does after accepting. Helm does none of it.
    'checklists' => [
        'pause' => [
            'Open the ad account and pause the campaign or ad set named in the evidence.',
            'Check nothing downstream depends on it (a retargeting pool, a feed rule).',
            'Come back and mark it done — the outcome is measured 14 and 30 days from now.',
        ],
        'scale' => [
            'Raise the budget in the ad account — 20–30% steps, not a doubling, so learning is not reset.',
            'Give it at least 5 days before judging (the platform needs the window).',
            'Watch that ROAS holds at the higher spend; that is exactly what will be measured.',
        ],
        'fix' => [
            'Open the campaign or ad set in the evidence and address the specific issue named.',
            'Change one thing at a time — two changes at once make the outcome unattributable.',
        ],
        'budget_shift' => [
            'Move budget toward the platform or ad set the evidence points at.',
            'Keep the total spend cap intact unless the plan says otherwise.',
        ],
        'creative_refresh' => [
            'Pause or replace the out-of-season creative named in the evidence.',
            'Swap in an in-season hook — the Ads Library boards have proven ones for this niche.',
        ],
        'launch' => [
            'Build the campaign in the ad account. Helm never creates one for you.',
        ],
        'investigate' => [
            'Check the numbers in the evidence against the ad platform and the store.',
            'If it turns out to be nothing, dismiss it WITH the reason — that record is what keeps Helm honest.',
        ],
    ],

    // Shown on the board, every render. No operator should ever be able to assume that
    // clicking a button in Helm changed something in their ad account.
    'execution_note' => 'Accepting records your decision — it does NOT change anything in Meta, Google or TikTok. '
        . 'Helm never touches your ad accounts. Make the change yourself in the ad platform, then the outcome is '
        . 'measured against what you agreed to here.',

    /*
    |--------------------------------------------------------------------------
    | Outcome measurement (GO-3.3, master plan §6.3)
    |--------------------------------------------------------------------------
    | The engine grades ITSELF. These thresholds decide whether a recommendation is
    | recorded as improved / worsened / flat, 14 and 30 days after the operator decided.
    |
    | The measurement must be able to say Helm was WRONG. A scoring rule tuned so that
    | everything lands in "improved" produces a number that means nothing, and a
    | meaningless win-rate is worse than none — it is a lie with a decimal point.
    */
    'measurement' => [

        // Days after the decision at which outcomes are measured.
        'windows' => [14, 30],

        // A metric must move by more than this to count as improved/worsened. Inside the
        // band it is FLAT — honest, and the most common truthful answer for small changes.
        'material_change_pct' => 10,

        // A 'pause' was actually carried out if post-decision spend fell by at least this
        // much. If the operator accepted and then didn't pause, the waste is NOT avoided
        // and Helm does not get to claim a win for advice nobody executed.
        'pause_spend_drop_pct' => 80,

        // Open advice nobody acted on for this long is EXPIRED, not open forever. It
        // leaves the board and stays in the denominator: pretending it was never made
        // would flatter the acceptance rate.
        'expire_open_after_days' => 30,
    ],

];
