<?php

declare(strict_types=1);

namespace App\Services\DateRange;

use App\Models\Brand;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Resolves a textual preset like 'yesterday' or 'last_7' into a concrete
 * [from, to] pair anchored to the brand's IANA timezone.
 *
 * The adapter contract guarantees `fetchDay()` receives dates already in
 * the brand's timezone — this is where that happens.
 */
final class DateRangeResolver
{
    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function resolve(Brand $brand, string $preset): array
    {
        $now = CarbonImmutable::now($brand->timezone);

        return match ($preset) {
            'yesterday' => [
                $now->subDay()->startOfDay(),
                $now->subDay()->endOfDay(),
            ],
            'last_7' => [
                $now->subDays(7)->startOfDay(),
                $now->subDay()->endOfDay(),
            ],
            'mtd' => [
                $now->startOfMonth()->startOfDay(),
                $now->subDay()->endOfDay(),
            ],
            'last_30', 'last_90', 'qtd', 'ytd', 'custom' => throw new InvalidArgumentException(
                "Date range preset '{$preset}' not yet implemented"
            ),
            default => throw new InvalidArgumentException("Unknown date range preset: {$preset}"),
        };
    }

    /**
     * Iterate every CarbonImmutable date between $from and $to inclusive.
     *
     * @return \Generator<int, CarbonImmutable>
     */
    public function eachDay(CarbonImmutable $from, CarbonImmutable $to): \Generator
    {
        $cursor = $from->startOfDay();
        $end    = $to->startOfDay();
        while ($cursor <= $end) {
            yield $cursor;
            $cursor = $cursor->addDay();
        }
    }
}
