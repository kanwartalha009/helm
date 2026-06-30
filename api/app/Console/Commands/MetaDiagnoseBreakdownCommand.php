<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Brand;
use App\Platforms\Meta\InsightsFetcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Probe Meta insights breakdowns against a brand's REAL ad data to confirm the
 * exact key for the Advantage+ Shopping audience segmentation (new / engaged /
 * existing) — which isn't in Meta's standard documented breakdowns and can't be
 * verified offline. Run it on a brand that runs ASC campaigns (e.g. Meller):
 * the candidate that returns those segments is the real key; the others error
 * with "invalid breakdown". The two sanity probes confirm the call itself works.
 *
 *   php artisan meta:diagnose-breakdown meller
 */
class MetaDiagnoseBreakdownCommand extends Command
{
    protected $signature = 'meta:diagnose-breakdown {brand : slug or id}';

    protected $description = 'Probe Meta breakdowns against a brand to find the working ASC audience-segment key.';

    /**
     * label => Meta breakdown list to try. The "audience?" rows are unverified
     * candidates; whichever returns new/engaged/existing segments is the key.
     *
     * @var array<string, array<int, string>>
     */
    private const PROBES = [
        'audience? user_segment_key'  => ['user_segment_key'],
        'audience? user_segment'      => ['user_segment'],
        'audience? audience_segment'  => ['audience_segment'],
        'audience? audience_type'     => ['audience_type'],
        'audience? conversion_audience' => ['conversion_audience'],
        'sanity · age'                => ['age'],
        'sanity · publisher_platform' => ['publisher_platform'],
    ];

    public function handle(InsightsFetcher $meta): int
    {
        $arg   = (string) $this->argument('brand');
        $lower = strtolower(trim($arg));

        $brand = is_numeric($arg)
            ? Brand::query()->with('connections')->find((int) $arg)
            : (Brand::query()->with('connections')
                ->whereRaw('LOWER(slug) = ?', [$lower])
                ->orWhereRaw('LOWER(name) = ?', [$lower])
                ->first()
                ?: Brand::query()->with('connections')
                    ->where('name', 'like', '%' . $arg . '%')
                    ->first());

        if ($brand === null) {
            $this->error("No brand matched '{$arg}'.");

            return self::FAILURE;
        }

        $conn = $brand->connections->firstWhere('platform', 'meta');
        if (! $conn || $conn->status !== 'active') {
            $this->error("{$brand->name} has no active Meta connection.");

            return self::FAILURE;
        }
        $conn->setRelation('brand', $brand);

        $tz   = $brand->timezone ?: 'UTC';
        $to   = CarbonImmutable::now($tz)->subDay()->startOfDay();
        $from = $to->subDays(6);

        $this->info("Meta breakdown probe · {$brand->name} · {$from->toDateString()}..{$to->toDateString()}");
        $this->newLine();

        foreach (self::PROBES as $label => $breakdowns) {
            try {
                $rows = $meta->fetchBreakdownRange($conn, $breakdowns, $from, $to);
                $segments = array_values(array_unique(array_map(static fn ($r) => (string) $r['segment_key'], $rows)));
                $this->info(sprintf('  ✓ %-32s %d rows · segments: %s', $label, count($rows), implode(', ', array_slice($segments, 0, 10)) ?: '—'));
            } catch (Throwable $e) {
                $this->line(sprintf('  ✗ %-32s %s', $label, $e->getMessage()));
            }
        }

        $this->newLine();
        $this->line('The "audience?" probe that lists new/engaged/existing segments is the key to bake in.');

        return self::SUCCESS;
    }
}
