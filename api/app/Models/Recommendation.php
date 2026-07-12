<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One row of THE LEDGER (GO-2.5). See the migration for the full contract.
 *
 * This class exists mainly to make the ledger's central promise UNBREAKABLE in code:
 *
 *   - The facts of a recommendation (source, kind, subject, title, evidence,
 *     confidence, baseline) are FROZEN at insert. Any attempt to change one throws.
 *   - Rows cannot be deleted. Ever.
 *   - Only the status region and the outcome region may change, and the outcome may
 *     only be written ONCE.
 *
 * A comment saying "don't edit this" is a suggestion; a thrown exception is a rule.
 * The whole value of a track record is that it cannot be curated after the fact — if a
 * bad call from March can be quietly tidied in June, the number it produces is
 * marketing, not evidence.
 */
class Recommendation extends Model
{
    protected $table = 'recommendations';

    protected $guarded = [];

    protected $casts = [
        'evidence'           => 'array',
        'baseline_value'     => 'float',
        'measured_value_14d' => 'float',
        'measured_value_30d' => 'float',
        'status_at'          => 'datetime',
        'measured_at'        => 'datetime',
    ];

    /** The ONLY columns that may ever change after insert. Everything else is frozen. */
    public const MUTABLE = [
        'status', 'status_reason', 'status_by_user_id', 'status_at',
        'measured_value_14d', 'measured_value_30d', 'outcome', 'measured_at',
        'updated_at',
    ];

    /** Legal state transitions. Terminal states are terminal. */
    public const TRANSITIONS = [
        'open'      => ['accepted', 'dismissed', 'expired'],
        'accepted'  => [],
        'dismissed' => [],
        'expired'   => [],
    ];

    protected static function booted(): void
    {
        static::updating(function (Recommendation $r): void {
            $illegal = array_diff(array_keys($r->getDirty()), self::MUTABLE);
            if ($illegal !== []) {
                throw new RuntimeException(
                    'The ledger is insert-only: ' . implode(', ', $illegal) . ' cannot be changed after a '
                    . 'recommendation is recorded. Record a NEW row with supersedes_id instead — an edited '
                    . 'track record is worthless.'
                );
            }

            // The outcome is written once, by the measurement job. A second write would
            // let a losing call be re-graded into a winning one.
            if ($r->isDirty('outcome') && $r->getOriginal('outcome') !== null) {
                throw new RuntimeException('The ledger is insert-only: an outcome is measured once and cannot be re-graded.');
            }
        });

        static::deleting(function (): void {
            throw new RuntimeException('The ledger is insert-only: recommendations are never deleted.');
        });
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function statusBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_by_user_id');
    }
}
