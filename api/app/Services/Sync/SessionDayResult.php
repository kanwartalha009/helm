<?php

declare(strict_types=1);

namespace App\Services\Sync;

/**
 * The verdict on one brand-day of session traffic.
 *
 * ══ WHY THIS REPLACED A `?int` RETURN ══
 * `syncDay()` used to return the number of rows written, with null meaning "we learned nothing".
 * That signal cannot express the state that actually matters:
 *
 *     rows written = 1,847     ← looks like a success
 *     is_complete  = false     ← the day is UNUSABLE and the window stays blank
 *
 * Every caller read "1,847 > 0" as success. The backfill logged it as done, the repair button
 * reported "filled", and the operator watched the window stay empty click after click while being
 * told it had worked. A count of rows is not a statement about whether those rows are TRUSTWORTHY,
 * and conflating the two produced a UI that lied.
 *
 * `complete` is the only field a caller should branch on to decide whether the day is fixed.
 */
final class SessionDayResult
{
    private function __construct(
        /** We got an answer out of Shopify at all. False = transport/parse failure; nothing written. */
        public readonly bool $established,
        /** The landing-page breakdown summed EXACTLY to Shopify's own store total. */
        public readonly bool $complete,
        public readonly int $rowsWritten,
        /** Shopify's own session total for the day. Null = we never established it. */
        public readonly ?int $storeTotal,
        /** What our landing-page breakdown added up to. */
        public readonly int $pagedTotal,
        /**
         * Why the day is unusable, straight from the fetcher.
         *
         * ══ WHY THIS ISN'T DERIVED FROM storeTotal − pagedTotal ══
         * It was, and it produced a message that contradicted itself:
         *
         *     "2026-06-28 — Shopify reports 152,621 sessions but its landing-page breakdown only
         *      adds up to 152,621 (0 missing)."   …listed as a FAILURE.
         *
         * In the bounded strategy `pagedTotal` is DERIVED from the store total, so it always equals
         * it and the shortfall is structurally zero. The day had failed for a completely different
         * reason — a subset that didn't page to the end — and the message had no way to say so. An
         * error message that cannot describe the error is worse than no message.
         *
         * @var list<string>
         */
        public readonly array $reasons = [],
    ) {}

    /** The day could not be established at all — do not record it as covered. */
    public static function failed(?int $storeTotal = null, int $pagedTotal = 0, array $reasons = []): self
    {
        return new self(false, false, 0, $storeTotal, $pagedTotal, $reasons);
    }

    /** We pulled the day. `complete` says whether the result can be trusted. */
    public static function pulled(
        bool $complete,
        int $rowsWritten,
        ?int $storeTotal,
        int $pagedTotal,
        array $reasons = [],
    ): self {
        return new self(true, $complete, $rowsWritten, $storeTotal, $pagedTotal, $reasons);
    }

    /** Sessions the breakdown is short by. Null when Shopify's own total was never obtained. */
    public function shortfall(): ?int
    {
        return $this->storeTotal === null ? null : $this->storeTotal - $this->pagedTotal;
    }

    /** Why this day is unusable, for an operator who cannot read the logs. */
    public function reason(): string
    {
        if ($this->complete) {
            return 'ok';
        }

        // The FETCHER knows exactly what went wrong. Prefer its answer over anything inferred here —
        // inferring it is what produced "adds up to 152,621 (0 missing)" on a day marked FAILED.
        if ($this->reasons !== []) {
            return implode(' ', $this->reasons);
        }

        if (! $this->established) {
            return 'Shopify did not return the day (request or parse failure).';
        }
        if ($this->storeTotal === null) {
            return 'Shopify would not return its own session total for the day, so nothing could be checked against it.';
        }

        if ((int) $this->shortfall() === 0) {
            // NEVER print "0 missing" next to the word "failed" again. Admit we don't know.
            return 'The day did not pass its completeness check, and the reason was not recorded.';
        }

        return sprintf(
            'Shopify reports %s sessions for the day but its landing-page breakdown only adds up to %s (%s missing).',
            number_format($this->storeTotal),
            number_format($this->pagedTotal),
            number_format((int) $this->shortfall()),
        );
    }
}
