<?php

declare(strict_types=1);

namespace App\Platforms\Google;

use App\Models\PlatformConnection;
use App\Platforms\Contracts\MetricSnapshot;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Pulls one day of Google Ads reporting metrics for a customer ID and
 * returns a MetricSnapshot.
 */
final class ReportsFetcher
{
    public function __construct(
        private readonly GoogleAdsClient $client,
    ) {}

    public function fetch(PlatformConnection $conn, CarbonImmutable $date): MetricSnapshot
    {
        throw new RuntimeException('Not yet implemented');
    }
}
