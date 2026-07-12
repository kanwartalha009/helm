<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use Carbon\CarbonImmutable;

/**
 * The EU market calendar (GO-4.1, master plan §7.1) — when each market actually shops.
 *
 * Pure computation: give it a year, it gives you every moment in every market with the
 * dates worked out. No database access, so the maths can be tested directly.
 *
 * ══ WHY THE DATES ARE COMPUTED, NOT TYPED ══
 * These moments MOVE:
 *   - French soldes are fixed BY LAW to the 2nd Wednesday of January — a different
 *     calendar date every year (2026: Jan 14).
 *   - Black Friday is the day after the 4th Thursday of November.
 *   - Mother's Day is a different Sunday in every market — and in FRANCE it moves again
 *     if it collides with Pentecost (last Sunday of May, unless that IS Pentecost, in
 *     which case the first Sunday of June). Getting this wrong means telling a client to
 *     launch a Mother's Day campaign a week after Mother's Day.
 * A hardcoded date is a wrong number with a delayed fuse. So we compute, and every row
 * carries its `source` so a human can check the claim.
 *
 * SOURCES (re-verify yearly — dates and even laws shift):
 *   - Legal sale periods: EVZ (the official EU consumer body), evz.de sale-periods page,
 *     updated 2025-01.
 *   - Gift dates: Trusted Shops EU holiday calendar, May 2025.
 *   - BFCM peak window: Triple Whale (Nov 9 – Dec 19 observed shopping window).
 *
 * MARKET-SPECIFIC TRAPS this encodes (the ones that cost real money):
 *   - ES/IT: THREE KINGS (Jan 6) is the primary gift moment — not Christmas Day. A
 *     Spanish campaign that stops on Dec 26 quits before the biggest gifting day.
 *   - NL: Sinterklaas (Dec 5) competes WITH Christmas and largely beats it for gifting.
 *   - FR: Mother's Day is LATE MAY, not the US date. Same for most of the EU vs the US/UK.
 *   - DE: has NO legally fixed sale periods (deregulated 2004) — SSV/WSV are traditional
 *     only, and are seeded as `event`, not `legal_sale`. Saying otherwise would be false.
 */
class MarketCalendar
{
    /** Markets Helm covers. */
    public const MARKETS = ['FR', 'ES', 'IT', 'DE', 'AT', 'BE', 'NL', 'PL'];

    private const SRC_EVZ   = 'EVZ (EU consumer body) — legal sale periods, evz.de (updated 2025-01)';
    private const SRC_TS    = 'Trusted Shops — EU holiday/gift calendar (May 2025)';
    private const SRC_TW    = 'Triple Whale — observed BFCM peak shopping window';
    private const SRC_HELM  = '[HELM DEFAULT] — no legally fixed period; traditional retail moment';

    /**
     * Every moment, in every market, for a year.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forYear(int $year): array
    {
        $out = [];

        foreach (self::MARKETS as $market) {
            foreach ($this->panEu($year) as $m) {
                $out[] = $m + ['market' => $market];
            }
            foreach ($this->marketSpecific($market, $year) as $m) {
                $out[] = $m + ['market' => $market];
            }
        }

        return $out;
    }

    /**
     * Moments that apply to every market Helm covers.
     *
     * @return array<int, array<string, mixed>>
     */
    private function panEu(int $year): array
    {
        // Black Friday = the day AFTER the 4th Thursday of November. Cyber Monday = +3.
        $blackFriday = $this->nthWeekday($year, 11, CarbonImmutable::THURSDAY, 4)->addDay();

        return [
            $this->m('valentines', "Valentine's Day", "{$year}-02-14", "{$year}-02-14", 'gift', self::SRC_TS, $year),

            // Back to school — a real EU-wide retail moment; no legal basis, so `event`.
            $this->m('back_to_school', 'Back to school', "{$year}-08-15", "{$year}-09-15", 'event', self::SRC_HELM, $year),

            $this->m('singles_day', 'Singles Day (11.11)', "{$year}-11-11", "{$year}-11-11", 'event', self::SRC_TS, $year),

            $this->m(
                'black_friday', 'Black Friday / Cyber Monday',
                $blackFriday->toDateString(), $blackFriday->addDays(3)->toDateString(),
                'event', self::SRC_TS, $year,
            ),

            // The window that actually matters for planning — peak demand and peak CPMs
            // start well before Black Friday itself.
            $this->m('peak_season', 'Peak shopping window', "{$year}-11-09", "{$year}-12-19", 'event', self::SRC_TW, $year),

            $this->m('christmas', 'Christmas', "{$year}-12-01", "{$year}-12-26", 'gift', self::SRC_TS, $year),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function marketSpecific(string $market, int $year): array
    {
        // Mother's Day: a different Sunday almost everywhere. Getting this wrong means
        // launching the campaign after the day has passed.
        $motherSecondSun = $this->nthWeekday($year, 5, CarbonImmutable::SUNDAY, 2);   // DE/AT/IT/BE/NL
        $motherFirstSun  = $this->nthWeekday($year, 5, CarbonImmutable::SUNDAY, 1);   // ES

        return match ($market) {
            // ── FRANCE — soldes are fixed BY LAW ──
            'FR' => [
                // 2nd Wednesday of January, 4 weeks. This is statute, not a guess.
                $this->window('soldes_hiver', "Soldes d'hiver (winter sales — fixed by law)",
                    $this->nthWeekday($year, 1, CarbonImmutable::WEDNESDAY, 2), 4 * 7 - 1, 'legal_sale', self::SRC_EVZ, $year),

                // Last Wednesday of June, 4 weeks.
                $this->window('soldes_ete', "Soldes d'été (summer sales — fixed by law)",
                    $this->lastWeekday($year, 6, CarbonImmutable::WEDNESDAY), 4 * 7 - 1, 'legal_sale', self::SRC_EVZ, $year),

                $this->m('french_days_spring', 'French Days (spring)', "{$year}-04-24", "{$year}-04-29", 'event', self::SRC_HELM, $year),
                $this->m('french_days_autumn', 'French Days (autumn)', "{$year}-09-25", "{$year}-09-30", 'event', self::SRC_HELM, $year),

                // LATE MAY — and it moves to the first Sunday of June if that Sunday is
                // Pentecost. This is the single most-mistimed EU gift date.
                $this->m('mothers_day', "Mother's Day (fête des mères)",
                    $this->frenchMothersDay($year)->toDateString(), $this->frenchMothersDay($year)->toDateString(),
                    'gift', self::SRC_TS, $year),

                $this->m('saint_nicolas', 'Saint-Nicolas', "{$year}-12-06", "{$year}-12-06", 'gift', self::SRC_TS, $year),
            ],

            // ── SPAIN — Three Kings is the PRIMARY gift moment, not Christmas Day ──
            'ES' => [
                $this->m('rebajas_invierno', 'Rebajas de invierno (winter sales)', "{$year}-01-07", "{$year}-03-01", 'legal_sale', self::SRC_EVZ, $year),
                $this->m('rebajas_verano', 'Rebajas de verano (summer sales)', "{$year}-07-01", "{$year}-08-31", 'legal_sale', self::SRC_EVZ, $year),

                // THE trap: a Spanish campaign that stops on Dec 26 quits before the
                // biggest gifting day of the year.
                $this->m('three_kings', 'Reyes Magos / Three Kings — primary gift day', "{$year}-01-02", "{$year}-01-06", 'gift', self::SRC_TS, $year),

                $this->m('fathers_day', 'Día del Padre (San José)', "{$year}-03-19", "{$year}-03-19", 'gift', self::SRC_TS, $year),
                $this->m('mothers_day', 'Día de la Madre', $motherFirstSun->toDateString(), $motherFirstSun->toDateString(), 'gift', self::SRC_TS, $year),
            ],

            // ── ITALY — saldi are regional law; Epiphany is the gift moment ──
            'IT' => [
                $this->m('saldi_invernali', 'Saldi invernali (winter sales — regional law)', "{$year}-01-05", "{$year}-03-01", 'legal_sale', self::SRC_EVZ, $year),
                $this->m('saldi_estivi', 'Saldi estivi (summer sales — regional law)', "{$year}-07-04", "{$year}-08-15", 'legal_sale', self::SRC_EVZ, $year),
                $this->m('epiphany', 'Epifania / Befana', "{$year}-01-02", "{$year}-01-06", 'gift', self::SRC_TS, $year),
                $this->m('fathers_day', 'Festa del papà (San Giuseppe)', "{$year}-03-19", "{$year}-03-19", 'gift', self::SRC_TS, $year),
                $this->m('mothers_day', 'Festa della mamma', $motherSecondSun->toDateString(), $motherSecondSun->toDateString(), 'gift', self::SRC_TS, $year),
            ],

            // ── BELGIUM — strict statutory windows ──
            'BE' => [
                $this->m('solden_winter', 'Winter solden (fixed by law)', "{$year}-01-03", "{$year}-01-31", 'legal_sale', self::SRC_EVZ, $year),
                $this->m('solden_zomer', 'Zomer solden (fixed by law)', "{$year}-07-01", "{$year}-07-31", 'legal_sale', self::SRC_EVZ, $year),
                $this->m('sinterklaas', 'Sinterklaas', "{$year}-11-15", "{$year}-12-06", 'gift', self::SRC_TS, $year),
                $this->m('mothers_day', 'Moederdag', $motherSecondSun->toDateString(), $motherSecondSun->toDateString(), 'gift', self::SRC_TS, $year),
            ],

            // ── NETHERLANDS — Sinterklaas competes with (and often beats) Christmas ──
            'NL' => [
                $this->m('kings_day', "King's Day (Koningsdag)", "{$year}-04-27", "{$year}-04-27", 'event', self::SRC_TS, $year),
                $this->m('sinterklaas', 'Sinterklaas — competes with Christmas for gifting', "{$year}-11-15", "{$year}-12-05", 'gift', self::SRC_TS, $year),
                $this->m('sint_maarten', "St. Martin's Day", "{$year}-11-11", "{$year}-11-11", 'event', self::SRC_TS, $year),
                $this->m('mothers_day', 'Moederdag', $motherSecondSun->toDateString(), $motherSecondSun->toDateString(), 'gift', self::SRC_TS, $year),
            ],

            // ── GERMANY / AUSTRIA — NO legally fixed sale periods (deregulated 2004) ──
            'DE', 'AT' => [
                // Traditional only. Calling these `legal_sale` would be false, so they are
                // `event` with a [HELM DEFAULT] source. Honesty over tidiness.
                $this->m('wsv', 'Winterschlussverkauf (traditional — not legally fixed)', "{$year}-01-25", "{$year}-02-15", 'event', self::SRC_HELM, $year),
                $this->m('ssv', 'Sommerschlussverkauf (traditional — not legally fixed)', "{$year}-07-25", "{$year}-08-15", 'event', self::SRC_HELM, $year),
                $this->m('nikolaus', 'Nikolaus', "{$year}-12-06", "{$year}-12-06", 'gift', self::SRC_TS, $year),
                $this->m('advent', 'Advent', $this->firstAdvent($year)->toDateString(), "{$year}-12-24", 'gift', self::SRC_TS, $year),
                $this->m('mothers_day', 'Muttertag', $motherSecondSun->toDateString(), $motherSecondSun->toDateString(), 'gift', self::SRC_TS, $year),
            ],

            // ── POLAND ──
            'PL' => [
                $this->m('mothers_day', 'Dzień Matki', "{$year}-05-26", "{$year}-05-26", 'gift', self::SRC_TS, $year),
                $this->m('childrens_day', 'Dzień Dziecka', "{$year}-06-01", "{$year}-06-01", 'gift', self::SRC_TS, $year),
                $this->m('wyprzedaze_zimowe', 'Winter sales', "{$year}-01-02", "{$year}-02-28", 'event', self::SRC_HELM, $year),
                $this->m('wyprzedaze_letnie', 'Summer sales', "{$year}-07-01", "{$year}-08-31", 'event', self::SRC_HELM, $year),
            ],

            default => [],
        };
    }

    /**
     * France: Mother's Day is the LAST Sunday of May — unless that Sunday is Pentecost,
     * in which case it moves to the FIRST Sunday of June. (Code de l'action sociale.)
     * Pentecost = Easter + 49 days.
     */
    private function frenchMothersDay(int $year): CarbonImmutable
    {
        $lastSunMay = $this->lastWeekday($year, 5, CarbonImmutable::SUNDAY);
        $pentecost  = $this->easter($year)->addDays(49);

        return $lastSunMay->equalTo($pentecost)
            ? $this->nthWeekday($year, 6, CarbonImmutable::SUNDAY, 1)
            : $lastSunMay;
    }

    /** First Advent = the 4th Sunday before Christmas Day. */
    private function firstAdvent(int $year): CarbonImmutable
    {
        $xmas = CarbonImmutable::create($year, 12, 25)->startOfDay();
        // The Sunday on or before Dec 24, then back three more weeks.
        $fourthAdvent = $xmas->previous(CarbonImmutable::SUNDAY);

        return $fourthAdvent->subWeeks(3);
    }

    /** Anonymous Gregorian algorithm — Easter Sunday. Deterministic, no dependencies. */
    private function easter(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day   = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($year, $month, $day)->startOfDay();
    }

    /** The nth given weekday of a month (1-indexed). FR soldes = 2nd Wednesday of January. */
    private function nthWeekday(int $year, int $month, int $weekday, int $nth): CarbonImmutable
    {
        $d = CarbonImmutable::create($year, $month, 1)->startOfDay();
        if ((int) $d->dayOfWeek !== $weekday) {
            $d = $d->next($weekday);
        }

        return $d->addWeeks($nth - 1);
    }

    /** The last given weekday of a month. FR soldes d'été = last Wednesday of June. */
    private function lastWeekday(int $year, int $month, int $weekday): CarbonImmutable
    {
        $d = CarbonImmutable::create($year, $month, 1)->endOfMonth()->startOfDay();

        return (int) $d->dayOfWeek === $weekday ? $d : $d->previous($weekday);
    }

    /** @return array<string, mixed> */
    private function m(string $key, string $label, string $start, string $end, string $kind, string $source, int $year): array
    {
        return [
            'moment_key' => $key,
            'label'      => $label,
            'starts_on'  => $start,
            'ends_on'    => $end,
            'kind'       => $kind,
            'source'     => $source,
            'year'       => $year,
        ];
    }

    /** @return array<string, mixed> */
    private function window(string $key, string $label, CarbonImmutable $start, int $days, string $kind, string $source, int $year): array
    {
        return $this->m($key, $label, $start->toDateString(), $start->addDays($days)->toDateString(), $kind, $source, $year);
    }
}
