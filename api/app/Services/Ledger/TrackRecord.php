<?php

declare(strict_types=1);

namespace App\Services\Ledger;

use App\Models\Recommendation;
use Illuminate\Support\Collection;

/**
 * Helm's track record (GO-3.3, master plan §6.3 / upgrade U2).
 *
 *     "34 recommendations · 24 accepted · 71% of accepted improved the target metric"
 *
 * No tool on the market publishes this about itself. The distrust literature says it is
 * exactly what is missing — unverifiable, incentive-conflicted advice is WHY only ~5% of
 * audited accounts accept Google's own recommendations.
 *
 * ══ COMPUTED LIVE FROM LEDGER ROWS. NEVER CACHED. ══ (master plan §6.3)
 * A cached number is a number someone can freeze on a good week. This one is a COUNT(*)
 * every time it is asked for, so it cannot drift from the rows underneath it — and if
 * the engine has a bad month, the page will say so.
 *
 * The denominator is honest on purpose:
 *  - `expired` advice (nobody decided) stays in the total. Dropping it would inflate the
 *    acceptance rate.
 *  - `unmeasurable` outcomes stay in the measured set. Dropping them would inflate the
 *    win-rate — they are the rows where we could not prove we helped.
 */
class TrackRecord
{
    /**
     * @param array<int, int>|null $brandIds null = every brand (caller must scope)
     * @return array<string, mixed>
     */
    public function compute(?array $brandIds = null): array
    {
        /** @var Collection<int, Recommendation> $rows */
        $rows = Recommendation::query()
            ->when($brandIds !== null, fn ($q) => $q->whereIn('brand_id', $brandIds))
            ->get(['kind', 'status', 'outcome', 'confidence']);

        $total     = $rows->count();
        $accepted  = $rows->where('status', 'accepted');
        $dismissed = $rows->where('status', 'dismissed')->count();
        $open      = $rows->where('status', 'open')->count();
        $expired   = $rows->where('status', 'expired')->count();

        // Only ACCEPTED + measured rows can speak to whether Helm's advice worked: an
        // idea nobody acted on proves nothing either way.
        $measured = $accepted->filter(fn (Recommendation $r): bool => $r->outcome !== null);
        $improved = $measured->where('outcome', 'improved')->count();
        $worsened = $measured->where('outcome', 'worsened')->count();
        $flat     = $measured->where('outcome', 'flat')->count();
        // Kept in the denominator. These are the ones we could NOT prove helped.
        $unmeasurable = $measured->where('outcome', 'unmeasurable')->count();

        $acceptedCount = $accepted->count();
        $decided       = $acceptedCount + $dismissed;

        return [
            'total'        => $total,
            'open'         => $open,
            'accepted'     => $acceptedCount,
            'dismissed'    => $dismissed,
            'expired'      => $expired,
            // Of the advice actually DECIDED (not the ones still sitting there).
            'acceptedPct'  => $decided > 0 ? round($acceptedCount / $decided * 100, 1) : null,
            'measured'     => $measured->count(),
            'improved'     => $improved,
            'worsened'     => $worsened,
            'flat'         => $flat,
            'unmeasurable' => $unmeasurable,
            // THE number. Null — not 0% — until something has actually been measured:
            // "no data yet" and "0% success" are very different claims.
            'improvedPct'  => $measured->count() > 0 ? round($improved / $measured->count() * 100, 1) : null,
            'byKind'       => $this->byKind($rows),
            'note'         => 'Computed live from the ledger every time this page loads — never cached. '
                . 'Advice nobody decided on (expired) still counts in the total, and outcomes we could not '
                . 'measure still count in the denominator. If Helm has a bad month, this number will say so.',
        ];
    }

    /**
     * @param Collection<int, Recommendation> $rows
     * @return array<int, array<string, mixed>>
     */
    private function byKind(Collection $rows): array
    {
        return $rows->groupBy('kind')->map(function (Collection $g, string $kind): array {
            $accepted = $g->where('status', 'accepted');
            $measured = $accepted->filter(fn (Recommendation $r): bool => $r->outcome !== null);

            return [
                'kind'        => $kind,
                'total'       => $g->count(),
                'accepted'    => $accepted->count(),
                'measured'    => $measured->count(),
                'improved'    => $measured->where('outcome', 'improved')->count(),
                'improvedPct' => $measured->count() > 0
                    ? round($measured->where('outcome', 'improved')->count() / $measured->count() * 100, 1)
                    : null,
            ];
        })->values()->all();
    }
}
