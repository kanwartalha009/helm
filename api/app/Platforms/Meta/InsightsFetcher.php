<?php

declare(strict_types=1);

namespace App\Platforms\Meta;

use App\Models\PlatformConnection;
use App\Platforms\Contracts\MetricSnapshot;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Pulls one day of ad insights (spend, impressions, clicks, conversions,
 * conversion value) for an ad account. Returns a MetricSnapshot with the
 * Meta attribution window stamped into metadata.
 */
final class InsightsFetcher
{
    public function __construct(
        private readonly MetaClient $client,
    ) {}

    public function fetch(PlatformConnection $conn, CarbonImmutable $date): MetricSnapshot
    {
        throw new RuntimeException('Not yet implemented');
    }
}
