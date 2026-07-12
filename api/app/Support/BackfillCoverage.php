<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Day-level resume for the backfill commands.
 *
 * The problem it solves: a full-history backfill across ~80 brands takes hours. When it dies
 * partway — a timeout, a rate limit, a dropped SSH session — re-running it repeats everything it
 * already did. `--missing` doesn't help: it skips any brand that has ANY rows, so an interrupted
 * brand gets skipped entirely and its remaining months are lost for good.
 *
 * So we record what we have already PULLED, per (brand, dataset, scope, day), and skip exactly
 * those days on the next run. A day that returned no data is recorded with `rows_written = 0` —
 * it is done, not missing. Without that distinction, every quiet day and every paused-ads day
 * would be re-fetched on every run for the rest of the project's life.
 *
 * Usage in a command:
 *
 *     $chunks = $coverage->pendingChunks($brand->id, 'commerce', 'product', $since, $until, 31);
 *     foreach ($chunks as [$from, $to]) {
 *         $rows = $fetcher->pull($conn, $from, $to);
 *         …write…
 *         $coverage->mark($brand->id, 'commerce', 'product', $from, $to, count($rows));
 *     }
 *
 * Mark AFTER the write succeeds, never before — a day marked done but not written is a permanent
 * hole that no future run will ever fill.
 */
class BackfillCoverage
{
    /**
     * The days in [$from, $to] we have NOT pulled yet for this (brand, dataset, scope).
     *
     * @return array<int, string> Y-m-d, ascending
     */
    public function pendingDays(int $brandId, string $dataset, string $scope, string $from, string $to): array
    {
        $done = DB::table('backfill_coverage')
            ->where('brand_id', $brandId)
            ->where('dataset', $dataset)
            ->where('scope', $scope)
            ->whereBetween('date', [$from, $to])
            ->pluck('date')
            ->map(static fn ($d): string => CarbonImmutable::parse((string) $d)->toDateString())
            ->flip();   // O(1) lookups

        $out    = [];
        $cursor = CarbonImmutable::parse($from)->startOfDay();
        $end    = CarbonImmutable::parse($to)->startOfDay();

        while ($cursor->lessThanOrEqualTo($end)) {
            $day = $cursor->toDateString();
            if (! $done->has($day)) {
                $out[] = $day;
            }
            $cursor = $cursor->addDay();
        }

        return $out;
    }

    /**
     * The pending days, grouped back into CONTIGUOUS chunks of at most $maxDays — so a command
     * that pulls a month per API call keeps doing that, and only skips the stretches it already
     * has. A resumed run that is 90% done issues 90% fewer calls; a fresh one is unchanged.
     *
     * @return array<int, array{0: string, 1: string}> [[from, to], …]
     */
    public function pendingChunks(int $brandId, string $dataset, string $scope, string $from, string $to, int $maxDays = 31): array
    {
        $days = $this->pendingDays($brandId, $dataset, $scope, $from, $to);
        if ($days === []) {
            return [];
        }

        $maxDays = max(1, $maxDays);
        $chunks  = [];
        $start   = $days[0];
        $prev    = $days[0];
        $len     = 1;

        for ($i = 1, $n = count($days); $i < $n; $i++) {
            $day        = $days[$i];
            $contiguous = CarbonImmutable::parse($prev)->addDay()->toDateString() === $day;

            if ($contiguous && $len < $maxDays) {
                $prev = $day;
                $len++;
                continue;
            }

            $chunks[] = [$start, $prev];
            $start    = $day;
            $prev     = $day;
            $len      = 1;
        }

        $chunks[] = [$start, $prev];

        return $chunks;
    }

    /**
     * Record every day in [$from, $to] as pulled. Call this ONLY after the write succeeded.
     *
     * `$rowsWritten` is for the operator's benefit (and for spotting a dataset that returns
     * nothing everywhere); 0 does not mean failure — it means the platform had nothing, which is
     * an answer, and we will not ask again.
     */
    public function mark(int $brandId, string $dataset, string $scope, string $from, string $to, int $rowsWritten = 0): void
    {
        $now     = now();
        $records = [];
        $cursor  = CarbonImmutable::parse($from)->startOfDay();
        $end     = CarbonImmutable::parse($to)->startOfDay();

        while ($cursor->lessThanOrEqualTo($end)) {
            $records[] = [
                'brand_id'     => $brandId,
                'workspace_id' => null,   // D-022 seam
                'dataset'      => $dataset,
                'scope'        => $scope,
                'date'         => $cursor->toDateString(),
                'rows_written' => $rowsWritten,
                'completed_at' => $now,
            ];
            $cursor = $cursor->addDay();
        }

        foreach (array_chunk($records, 500) as $chunk) {
            DB::table('backfill_coverage')->upsert(
                $chunk,
                ['brand_id', 'dataset', 'scope', 'date'],
                ['rows_written', 'completed_at'],
            );
        }
    }

    /** Forget a window so `--force` genuinely re-pulls it. */
    public function forget(int $brandId, string $dataset, string $scope, string $from, string $to): void
    {
        DB::table('backfill_coverage')
            ->where('brand_id', $brandId)
            ->where('dataset', $dataset)
            ->where('scope', $scope)
            ->whereBetween('date', [$from, $to])
            ->delete();
    }

    /** How many days of [$from, $to] are already done — for the "· 412/550 days already done" line. */
    public function doneCount(int $brandId, string $dataset, string $scope, string $from, string $to): int
    {
        return (int) DB::table('backfill_coverage')
            ->where('brand_id', $brandId)
            ->where('dataset', $dataset)
            ->where('scope', $scope)
            ->whereBetween('date', [$from, $to])
            ->count();
    }
}
