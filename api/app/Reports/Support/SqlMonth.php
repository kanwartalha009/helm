<?php

declare(strict_types=1);

namespace App\Reports\Support;

use Illuminate\Support\Facades\DB;

/**
 * Driver-portable SQL expression for a date column's calendar-month key
 * ('Y-m'): DATE_FORMAT on the MySQL production database, strftime on the
 * sqlite database the test suite runs on. Keeps the monthly report's grouped
 * queries green on both without duplicating the conditional at every call
 * site.
 */
final class SqlMonth
{
    public static function expr(string $column = 'date'): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "strftime('%Y-%m', {$column})",
            default  => "DATE_FORMAT({$column}, '%Y-%m')",
        };
    }
}
