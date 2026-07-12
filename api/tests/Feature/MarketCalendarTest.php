<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MarketMoment;
use App\Services\Calendar\MarketCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GO-4.1 — the EU market calendar.
 *
 * These tests pin the dates that a hardcoded calendar would get WRONG, and that a client
 * plan would then get wrong on top of:
 *   - FR soldes are fixed BY LAW to the 2nd Wednesday of January — a different date every
 *     year (2026: Jan 14; 2027: Jan 13).
 *   - Black Friday is the day after the 4th Thursday of November.
 *   - FR Mother's Day is LATE MAY, and moves to June if it collides with Pentecost.
 *   - ES/IT: Three Kings (Jan 6) is the PRIMARY gift moment — a campaign that stops on
 *     Dec 26 quits before the biggest gifting day of the year.
 *   - DE has NO legally fixed sale periods. Claiming otherwise would be false.
 */
final class MarketCalendarTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array<string, mixed>> keyed by "MARKET:moment_key" */
    private function forYear(int $year): array
    {
        $out = [];
        foreach (app(MarketCalendar::class)->forYear($year) as $m) {
            $out[$m['market'] . ':' . $m['moment_key']] = $m;
        }

        return $out;
    }

    public function test_french_soldes_are_the_second_wednesday_of_january_by_law(): void
    {
        // 2026: Jan 1 is a Thursday → Wednesdays are 7, 14, 21, 28 → 2nd = Jan 14.
        $m = $this->forYear(2026)['FR:soldes_hiver'];
        $this->assertSame('2026-01-14', $m['starts_on']);
        $this->assertSame('2026-02-10', $m['ends_on']);     // 4 weeks
        $this->assertSame('legal_sale', $m['kind']);        // statute, not advice
        $this->assertStringContainsString('EVZ', $m['source']);

        // 2027: Jan 1 is a Friday → Wednesdays are 6, 13 → 2nd = Jan 13. A hardcoded
        // "14 January" would already be wrong one year later.
        $this->assertSame('2027-01-13', $this->forYear(2027)['FR:soldes_hiver']['starts_on']);
    }

    public function test_french_summer_soldes_are_the_last_wednesday_of_june(): void
    {
        // June 2026: Wednesdays 3, 10, 17, 24 → last = Jun 24.
        $this->assertSame('2026-06-24', $this->forYear(2026)['FR:soldes_ete']['starts_on']);
    }

    public function test_black_friday_is_the_day_after_the_fourth_thursday_of_november(): void
    {
        // Nov 2026: Thursdays 5, 12, 19, 26 → 4th = Nov 26 → Black Friday = Nov 27.
        $bf = $this->forYear(2026)['FR:black_friday'];
        $this->assertSame('2026-11-27', $bf['starts_on']);
        $this->assertSame('2026-11-30', $bf['ends_on']);   // through Cyber Monday
    }

    public function test_french_mothers_day_is_late_may_not_the_us_date(): void
    {
        // May 2026: Sundays 3, 10, 17, 24, 31 → last = May 31. Pentecost 2026 is May 24,
        // so no collision. Telling a French client to run Mother's Day in early May
        // (the US/ES date) would miss it by three weeks.
        $this->assertSame('2026-05-31', $this->forYear(2026)['FR:mothers_day']['starts_on']);

        // ES is the FIRST Sunday of May — a different date entirely (2026: May 3).
        $this->assertSame('2026-05-03', $this->forYear(2026)['ES:mothers_day']['starts_on']);

        // DE/IT/NL/BE are the SECOND Sunday (2026: May 10).
        $this->assertSame('2026-05-10', $this->forYear(2026)['DE:mothers_day']['starts_on']);
    }

    public function test_the_pentecost_rule_moves_french_mothers_day_into_june(): void
    {
        // 2023 is a real collision year: Easter Apr 9 → Pentecost = May 28, and the last
        // Sunday of May 2023 IS May 28. By law Mother's Day therefore moves to the first
        // Sunday of June → 2023-06-04. (Verified against the actual French date.)
        $this->assertSame('2023-06-04', $this->forYear(2023)['FR:mothers_day']['starts_on']);

        // 2021 did NOT collide (Pentecost May 23, last Sunday May 30) — so the rule must
        // NOT fire, and the date stays in May. A rule that always moves the date would be
        // just as wrong as one that never does.
        $this->assertSame('2021-05-30', $this->forYear(2021)['FR:mothers_day']['starts_on']);
    }

    public function test_three_kings_is_the_primary_gift_moment_in_spain_and_italy(): void
    {
        // The trap this exists to prevent: a Spanish campaign that stops on Dec 26 quits
        // BEFORE the biggest gifting day of the year.
        $es = $this->forYear(2026)['ES:three_kings'];
        $this->assertSame('2026-01-06', $es['ends_on']);
        $this->assertSame('gift', $es['kind']);
        $this->assertStringContainsString('primary gift day', $es['label']);

        $this->assertSame('2026-01-06', $this->forYear(2026)['IT:epiphany']['ends_on']);
    }

    public function test_sinterklaas_competes_with_christmas_in_the_netherlands(): void
    {
        $nl = $this->forYear(2026)['NL:sinterklaas'];
        $this->assertSame('2026-12-05', $nl['ends_on']);
        $this->assertStringContainsString('competes with Christmas', $nl['label']);
    }

    public function test_germany_has_no_legally_fixed_sale_periods(): void
    {
        // Deregulated in 2004. Labelling SSV/WSV as `legal_sale` would be FALSE, and a
        // client could plan around a legal constraint that does not exist.
        $moments = $this->forYear(2026);

        $this->assertSame('event', $moments['DE:wsv']['kind']);
        $this->assertSame('event', $moments['DE:ssv']['kind']);
        $this->assertStringContainsString('not legally fixed', $moments['DE:wsv']['label']);
        $this->assertStringContainsString('HELM DEFAULT', $moments['DE:wsv']['source']);

        // Whereas FR/ES/IT/BE genuinely are statutory.
        $this->assertSame('legal_sale', $moments['FR:soldes_hiver']['kind']);
        $this->assertSame('legal_sale', $moments['BE:solden_winter']['kind']);
    }

    public function test_every_market_has_at_least_one_moment_in_every_quarter(): void
    {
        // Seed integrity: a market with a blank quarter means the playbook engine (GO-4.3)
        // would have nothing to plan against for three months of that market's year.
        $byMarketQuarter = [];
        foreach (app(MarketCalendar::class)->forYear(2026) as $m) {
            $q = (int) ceil(((int) substr($m['starts_on'], 5, 2)) / 3);
            $byMarketQuarter[$m['market']][$q] = true;
        }

        foreach (MarketCalendar::MARKETS as $market) {
            foreach ([1, 2, 3, 4] as $q) {
                $this->assertTrue(
                    isset($byMarketQuarter[$market][$q]),
                    "Market {$market} has no moment in Q{$q} — the playbook engine would have nothing to plan against.",
                );
            }
        }
    }

    public function test_every_moment_carries_a_source(): void
    {
        // A calendar entry a human cannot check is one they should not plan a client's
        // quarter around.
        foreach (app(MarketCalendar::class)->forYear(2026) as $m) {
            $this->assertNotEmpty($m['source'], "{$m['market']}:{$m['moment_key']} has no source.");
        }
    }

    public function test_the_seed_command_is_idempotent(): void
    {
        $this->artisan('calendar:seed', ['year' => 2026])->assertExitCode(0);
        $first = MarketMoment::count();
        $this->assertGreaterThan(0, $first);

        // Re-running a year must update, not duplicate.
        $this->artisan('calendar:seed', ['year' => 2026])->assertExitCode(0);
        $this->assertSame($first, MarketMoment::count());

        $fr = MarketMoment::where('market', 'FR')->where('moment_key', 'soldes_hiver')->where('year', 2026)->firstOrFail();
        $this->assertSame('2026-01-14', $fr->starts_on->toDateString());
        $this->assertSame('legal_sale', $fr->kind);
    }
}
