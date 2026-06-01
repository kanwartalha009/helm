<?php

declare(strict_types=1);

namespace App\Console;

use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

/**
 * OBSOLETE — DO NOT ADD SCHEDULES HERE.
 *
 * Laravel 11 does not use App\Console\Kernel. The framework never
 * instantiates this class, so any schedule() defined here is dead code that
 * silently never runs. This caused a real bug: the ratified twice-daily
 * cadence was once edited into this file and never executed.
 *
 * The single source of truth for scheduling is bootstrap/app.php
 * (->withSchedule()). This stub is retained only because it could not be
 * deleted automatically — remove it for good with:
 *
 *     git rm api/app/Console/Kernel.php
 */
final class Kernel extends ConsoleKernel
{
    // Intentionally empty. Scheduling lives in bootstrap/app.php.
}
