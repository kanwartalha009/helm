<?php

declare(strict_types=1);

namespace App\Platforms\TikTok;

use App\Models\PlatformConnection;
use App\Platforms\Contracts\MetricSnapshot;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Pulls one day of TikTok advertising reports for an advertiser_id.
 */
final class ReportsFetcher
{
    public function __construct(
        private readonly TikTokClient $client,
    ) {}

    public function fetch(PlatformConnection $conn, CarbonImmutable $date): MetricSnapshot
    {
        throw new RuntimeException('Not yet implemented');
    }
}
