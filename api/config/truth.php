<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Triangulated truth — MER spine + platform bias annotations (§4.4, U1)
|--------------------------------------------------------------------------
| DOCTRINE (master plan §0, law 3): Helm NEVER claims "accurate attribution".
| Platforms misreport in BOTH directions, so the credible product is:
|
|     store-truth MER as the SPINE, platform-reported numbers BESIDE it,
|     each carrying its documented bias DIRECTION.
|
| Presenting platform ROAS as truth is instant credibility death with senior media
| buyers — independent measurement is the most-trusted source in the category and
| in-platform reporting the least (AdBeacon senior-buyer survey, Jan 2026).
|
| Every annotation below is SOURCED. Edit the strings here — never hardcode a bias
| claim in a component. If a number changes, change it once, here.
|
| SOURCES
| -------
| - Haus, 640 incrementality experiments (Jul–Aug 2025, haus.io):
|     * Meta Advantage+ campaigns OVER-credit themselves by ≈12pp vs manual campaigns.
|     * Meta on strict 7-day-click for DTC actually UNDER-reports: ≈$115 of real
|       incremental revenue per $100 Meta reports.
| - LayerFive via AdBeacon: aggregate analysis found platforms overstating ROAS
|   ≈2.3× vs verified revenue — the bias is real and it is NOT one-directional.
| - Helm's own Meta default attribution window is 7-day click only (ratified).
*/

return [

    // The spine. This is OUR number, computed from store revenue we synced.
    'mer' => [
        'label'   => 'Verified — store truth',
        'formula' => 'MER = store revenue ÷ total ad spend. It uses the revenue Shopify actually '
            . 'recorded, not what any ad platform claims it caused. It is the only figure here that '
            . 'does not depend on a platform grading its own homework.',
    ],

    // Everything a platform reports about itself.
    'platform_label' => 'Platform-reported — unverified',

    // Per-platform bias annotations. Rendered VERBATIM next to that platform's ROAS.
    'annotations' => [

        'meta' => 'Platform-reported. Meta\'s bias runs in both directions: Advantage+ campaigns '
            . 'over-credit themselves by roughly 12 percentage points versus manual campaigns, while '
            . 'Meta on a strict 7-day-click window (Helm\'s default) tends to UNDER-report DTC — about '
            . '$115 of real incremental revenue per $100 it reports (Haus, 640 incrementality experiments). '
            . 'Treat this as a directional signal, not as revenue.',

        'google' => 'Platform-reported and unverified. Google attributes conversions with its own model '
            . 'across its own surfaces; PMax in particular reports opaquely and cannot be independently '
            . 'checked from the API. Compare against MER, do not add to it.',

        'tiktok' => 'Platform-reported and unverified. TikTok attributes conversions with its own model. '
            . 'Compare against MER, do not add to it.',
    ],

    // Shown wherever platform ROAS sits beside MER.
    'divergence_note' => 'Platform-reported revenue double-counts: two platforms can both claim the same '
        . 'order, and neither can see the other. That is why these figures are shown side by side and are '
        . 'never summed into a "total attributed revenue". Where they diverge sharply from MER, trust MER — '
        . 'it is the money the store actually took.',

];
