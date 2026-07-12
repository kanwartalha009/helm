<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ledger\OutcomeMeasurer;
use Illuminate\Console\Command;

/**
 * Grade Helm's own advice (GO-3.3). Runs daily: measures accepted/dismissed
 * recommendations 14 and 30 days after the decision, and expires open advice nobody
 * ever acted on.
 *
 * Outcomes are written ONCE (the ledger enforces it), so this command is safe to re-run:
 * anything already graded is skipped, and a loss can never be quietly re-graded into a win.
 *
 *   php artisan ledger:measure
 */
class LedgerMeasureCommand extends Command
{
    protected $signature = 'ledger:measure';

    protected $description = "Measure the outcome of Helm's own recommendations (14/30d) and expire undecided advice.";

    public function handle(OutcomeMeasurer $measurer): int
    {
        $r = $measurer->run();

        $this->info("Measured {$r['measured']} recommendation(s); expired {$r['expired']} undecided one(s).");

        return self::SUCCESS;
    }
}
