<?php

declare(strict_types=1);

namespace App\Services\Ledger;

use App\Models\Brand;
use App\Models\Recommendation;
use App\Models\User;
use InvalidArgumentException;
use RuntimeException;

/**
 * THE LEDGER's only write surface (GO-2.5). Nothing else may insert or transition a
 * recommendation — routing every write through here is what makes the guarantees
 * checkable in one place.
 *
 * Three operations, and no others:
 *   record()     — log a recommendation (idempotent while one is still open)
 *   transition() — open → accepted | dismissed | expired  (dismiss REQUIRES a reason)
 *   measure()    — write the outcome, once, from the measurement job (GO-3.3)
 *
 * A correction is never an edit: supersede() records a NEW row pointing at the old one.
 */
class Ledger
{
    /**
     * Log a recommendation. IDEMPOTENT: if an OPEN row already exists for the same
     * (brand, source, kind, subject) it is returned unchanged and nothing new is
     * written — the daily recorder would otherwise re-log the same advice every night
     * and the acceptance rate would be diluted by hundreds of duplicate rows.
     *
     * Note it does NOT refresh the evidence of the open row. Evidence is frozen at the
     * moment the advice was given; that is the whole point. If the situation has
     * genuinely changed, the honest move is supersede().
     *
     * @param array<string, mixed> $evidence numbers + rule + thresholds cited
     */
    public function record(
        Brand $brand,
        string $source,
        string $kind,
        string $subjectType,
        string $subjectId,
        string $title,
        array $evidence,
        string $confidence = 'solid',
        ?string $outcomeMetric = null,
        ?float $baselineValue = null,
        ?int $supersedesId = null,
    ): Recommendation {
        if ($evidence === []) {
            // An unevidenced recommendation cannot be scored, defended, or trusted.
            throw new InvalidArgumentException('A recommendation must carry evidence.');
        }
        if (! in_array($confidence, ['solid', 'early'], true)) {
            throw new InvalidArgumentException("Unknown confidence: {$confidence}");
        }

        $existing = Recommendation::query()
            ->where('brand_id', $brand->id)
            ->where('source', $source)
            ->where('kind', $kind)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('status', 'open')
            ->first();

        if ($existing !== null) {
            return $existing; // already advised and still open — say it once, not nightly
        }

        return Recommendation::create([
            'brand_id'       => $brand->id,
            'source'         => $source,
            'kind'           => $kind,
            'subject_type'   => $subjectType,
            'subject_id'     => $subjectId,
            'title'          => mb_substr($title, 0, 255),
            'evidence'       => $evidence,
            'confidence'     => $confidence,
            'status'         => 'open',
            'outcome_metric' => $outcomeMetric,
            'baseline_value' => $baselineValue,
            'supersedes_id'  => $supersedesId,
        ]);
    }

    /**
     * Move a recommendation through its state machine. Terminal states are terminal:
     * an accepted call cannot be quietly reopened and re-decided once the result is known.
     *
     * Accepting a 'pause'/'scale' does NOT execute anything — Helm never touches campaign
     * state (doctrine §2). It records INTENT, which is what the outcome is measured against.
     */
    public function transition(Recommendation $rec, string $to, ?User $user = null, ?string $reason = null): Recommendation
    {
        $allowed = Recommendation::TRANSITIONS[$rec->status] ?? [];

        if (! in_array($to, $allowed, true)) {
            throw new RuntimeException(
                "Illegal ledger transition {$rec->status} → {$to}. Allowed from {$rec->status}: "
                . ($allowed === [] ? 'none (terminal state)' : implode(', ', $allowed)) . '.'
            );
        }

        // A dismissal without a stated reason is how an engine buries its misses.
        if ($to === 'dismissed' && trim((string) $reason) === '') {
            throw new InvalidArgumentException('Dismissing a recommendation requires a reason — it is the honesty record.');
        }

        $rec->update([
            'status'            => $to,
            'status_reason'     => $reason,
            'status_by_user_id' => $user?->id,
            'status_at'         => now(),
        ]);

        return $rec;
    }

    /**
     * Write the measured outcome. ONCE. Called by the measurement job (GO-3.3), never by
     * a human — a re-gradable outcome would let a losing call become a winning one.
     *
     * 'unmeasurable' is a first-class, honest result: the campaign was deleted, the
     * product delisted, the subject vanished. Recording it as such beats silently
     * dropping the row from the denominator, which would flatter the win-rate.
     */
    public function measure(Recommendation $rec, string $outcome, ?float $value14d = null, ?float $value30d = null): Recommendation
    {
        if (! in_array($outcome, ['improved', 'worsened', 'flat', 'unmeasurable'], true)) {
            throw new InvalidArgumentException("Unknown outcome: {$outcome}");
        }

        // The model also enforces this; checked here so the caller gets a clear message.
        if ($rec->outcome !== null) {
            throw new RuntimeException('This recommendation has already been measured — outcomes are written once.');
        }

        $rec->update([
            'measured_value_14d' => $value14d,
            'measured_value_30d' => $value30d,
            'outcome'            => $outcome,
            'measured_at'        => now(),
        ]);

        return $rec;
    }

    /**
     * Correct a past recommendation the only way the ledger permits: a NEW row that
     * points at the old one. The original stays exactly as it was written.
     *
     * @param array<string, mixed> $evidence
     */
    public function supersede(Recommendation $old, string $title, array $evidence, ?string $kind = null): Recommendation
    {
        return $this->record(
            $old->brand,
            $old->source,
            $kind ?? $old->kind,
            $old->subject_type,
            $old->subject_id,
            $title,
            $evidence,
            $old->confidence,
            $old->outcome_metric,
            $old->baseline_value,
            supersedesId: $old->id,
        );
    }
}
