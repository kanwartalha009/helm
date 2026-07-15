<?php

declare(strict_types=1);

namespace App\Reports\Mom\Sections;

use App\Models\Brand;
use App\Reports\Contracts\ReportFilters;
use App\Reports\Mom\Contracts\MomSection;
use App\Services\Rules\Pacing;
use Carbon\CarbonImmutable;

/**
 * REV2 R5 — "S-GOALS: Goals vs actual. Pulls brand_targets (the goals feature
 * from bosco-inputs-2026-07-12.md): revenue vs target bar, ROAS vs target,
 * goal-hit badges — the meeting's accountability moment. Renders only when
 * targets are set."
 *
 * Wraps the EXISTING Pacing engine (GO-2.1/D-025) rather than recomputing
 * anything — brand goals were already confirmed built in this program's own
 * overlap check; this section is pure reuse, not a reimplementation. That also
 * means every "missing != zero" and USD-correct-ROAS guarantee Pacing already
 * carries is inherited here for free.
 */
final class SGoalsSection implements MomSection
{
    public function __construct(private readonly Pacing $pacing)
    {
    }

    public function key(): string
    {
        return 'S-GOALS';
    }

    public function build(Brand $brand, ReportFilters $filters): array
    {
        $tz    = $brand->timezone ?: 'UTC';
        $month = $filters->month ?? CarbonImmutable::now($tz)->subMonth()->format('Y-m');

        $p = $this->pacing->forBrand($brand, $month);

        if ($p === null || ($p['revenue'] === null && $p['roas'] === null)) {
            // No target set for this month (and no standing default) — the
            // section renders as absent, per REV2 R5 ("renders only when
            // targets are set"), never a fabricated 0%-of-goal bar.
            return [
                'key'    => $this->key(),
                'status' => 'no_data',
                // M5 addendum (Kanwar, 2026-07-15) — copy updated: the
                // frontend's SGoalsCard now shows a "Set a goal" button right
                // here (GoalsDrawer), no longer just a Settings pointer.
                'note'   => 'No goal set for this brand yet — set one below, or in brand Settings.',
            ];
        }

        $revenue = $p['revenue'];
        $roas    = $p['roas'];

        return [
            'key'    => $this->key(),
            'status' => 'ok',
            'month'  => $p['month'],
            'isStandingDefault' => $p['isStandingDefault'],
            'currency' => $p['currency'],
            'revenue' => $revenue === null ? null : [
                'actual'      => $revenue['actual'],
                'target'      => $revenue['target'],
                'pctOfTarget' => $revenue['pctOfTarget'],
                'status'      => $revenue['status'],
                'goalHit'     => $revenue['pctOfTarget'] !== null && $revenue['pctOfTarget'] >= 100,
            ],
            'roas' => $roas === null ? null : [
                // Pacing's roas.actual is already null (not 0) when there's no
                // ad spend — a ratio with no denominator isn't zero, it's undefined.
                'actual'  => $roas['actual'],
                'target'  => $roas['target'],
                'status'  => $roas['status'],
                'goalHit' => $roas['actual'] !== null && $roas['actual'] >= $roas['target'],
            ],
            'neededPerDay' => $p['neededPerDay'],
            'remainingDays' => $p['remainingDays'],
        ];
    }
}
