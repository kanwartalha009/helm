<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MarketMoment;
use App\Services\Calendar\MarketCalendar;
use Illuminate\Console\Command;

/**
 * Seed the EU market calendar for a year (GO-4.1).
 *
 * NOT scheduled, on purpose. Sale dates shift, and laws change (Germany deregulated its
 * sale periods in 2004; regional Italian/Spanish rules move). A calendar that silently
 * re-seeds itself every January would quietly propagate a stale assumption into every
 * client plan. This is a deliberate yearly action with a human reviewing the output —
 * Kanwar-owed, per master plan §11.6.
 *
 * Idempotent: re-running a year updates rows rather than duplicating them.
 *
 *   php artisan calendar:seed 2027
 *   php artisan calendar:seed 2027 --dry-run
 */
class CalendarSeedCommand extends Command
{
    protected $signature = 'calendar:seed {year : the calendar year to seed} {--dry-run : print, write nothing}';

    protected $description = 'Seed the EU market calendar (legal sale periods, gift dates, commercial events) for a year.';

    public function handle(MarketCalendar $calendar): int
    {
        $year = (int) $this->argument('year');
        if ($year < 2020 || $year > 2100) {
            $this->error('Year looks wrong.');

            return self::FAILURE;
        }

        $moments = $calendar->forYear($year);

        if ($this->option('dry-run')) {
            $this->table(
                ['Market', 'Moment', 'Starts', 'Ends', 'Kind'],
                array_map(static fn (array $m): array => [
                    $m['market'], $m['label'], $m['starts_on'], $m['ends_on'], $m['kind'],
                ], $moments),
            );
            $this->comment(count($moments) . ' moment(s) — nothing written (dry run).');

            return self::SUCCESS;
        }

        foreach ($moments as $m) {
            MarketMoment::updateOrCreate(
                ['market' => $m['market'], 'moment_key' => $m['moment_key'], 'year' => $m['year']],
                [
                    'label'     => $m['label'],
                    'starts_on' => $m['starts_on'],
                    'ends_on'   => $m['ends_on'],
                    'kind'      => $m['kind'],
                    'source'    => $m['source'],   // every row can be checked back to a source
                ],
            );
        }

        $this->info(count($moments) . " moment(s) seeded for {$year} across " . count(MarketCalendar::MARKETS) . ' markets.');
        $this->comment('Dates shift year to year — review the output before planning a client quarter around it.');

        return self::SUCCESS;
    }
}
