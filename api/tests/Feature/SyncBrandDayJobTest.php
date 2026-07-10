<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SyncBrandDayJob;
use App\Models\Brand;
use App\Models\DailyMetric;
use App\Models\PlatformConnection;
use App\Models\SyncLog;
use App\Platforms\Contracts\MetricSnapshot;
use App\Platforms\Contracts\PlatformAdapter;
use App\Platforms\PlatformRegistry;
use App\Platforms\Shopify\RevenueFetcher;
use App\Platforms\Support\PlatformRateLimitedException;
use App\Services\Currency\FxService;
use App\Services\Sync\CampaignSync;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Lifecycle contract of the sync unit: sync_logs transitions
 * (queued → running → success/failed/back-to-queued), daily_metrics upsert,
 * connection stamping, rate-limit release, and the failed() safety net.
 * First tests on this path (audit 2026-07-10, layer: tests/CI).
 */
final class SyncBrandDayJobTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;
    private PlatformConnection $conn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->brand = Brand::factory()->create(['timezone' => 'UTC', 'base_currency' => 'EUR']);
        $this->conn  = (new PlatformConnection())->forceFill([
            'brand_id'    => $this->brand->id,
            'platform'    => 'shopify',
            'external_id' => 'test.myshopify.com',
            'status'      => 'active',
            'credentials' => ['shop_domain' => 'test.myshopify.com', 'access_token' => 'x'],
        ]);
        $this->conn->save();
    }

    private function runJob(SyncBrandDayJob $job, PlatformAdapter $adapter): void
    {
        $registry = new PlatformRegistry(app(), ['shopify' => $adapter::class]);
        app()->instance($adapter::class, $adapter);

        // Isolate the unit: the best-effort sub-syncs (campaigns, breakdowns,
        // funnel, commerce) are not under test and must not attempt HTTP.
        $campaigns = Mockery::mock(CampaignSync::class)->shouldIgnoreMissing();
        $revenue   = Mockery::mock(RevenueFetcher::class);
        $revenue->shouldReceive('funnelByDimensionRange')->andReturn([]);
        $revenue->shouldReceive('salesByDimensionRange')->andReturn([]);

        $job->handle($registry, app(FxService::class), $campaigns, $revenue);
    }

    private function queuedLog(CarbonImmutable $date): SyncLog
    {
        return SyncLog::create([
            'brand_id'    => $this->brand->id,
            'platform'    => 'shopify',
            'target_date' => $date->toDateString(),
            'status'      => 'queued',
        ]);
    }

    public function test_success_writes_metric_and_completes_log(): void
    {
        $date = CarbonImmutable::now('UTC')->subDay()->startOfDay();
        $log  = $this->queuedLog($date);

        $adapter = new FakeShopifyAdapter(fn () => new MetricSnapshot(
            brandId: $this->brand->id,
            platform: 'shopify',
            date: $date,
            currency: 'EUR',
            revenue: 120.5,
            netSales: 100.0,
            totalSales: 110.0,
            orders: 4,
            refundsAmount: 5.0,
            isComplete: true,
        ));

        $this->runJob(new SyncBrandDayJob($this->brand, $this->conn, $date, $log->id), $adapter);

        $log->refresh();
        $this->assertSame('success', $log->status);
        $this->assertSame(1, (int) $log->records_processed);
        $this->assertNotNull($log->completed_at);

        $metric = DailyMetric::where('brand_id', $this->brand->id)->where('platform', 'shopify')->first();
        $this->assertNotNull($metric);
        $this->assertEqualsWithDelta(120.5, (float) $metric->revenue, 0.001);
        $this->assertTrue((bool) $metric->is_complete);

        $this->conn->refresh();
        $this->assertNotNull($this->conn->last_sync_at);
        $this->assertNull($this->conn->last_error);
    }

    public function test_failure_marks_log_failed_and_stamps_connection_error(): void
    {
        $date = CarbonImmutable::now('UTC')->subDay()->startOfDay();
        $log  = $this->queuedLog($date);

        $adapter = new FakeShopifyAdapter(fn () => throw new RuntimeException('Shopify error: boom'));

        try {
            $this->runJob(new SyncBrandDayJob($this->brand, $this->conn, $date, $log->id), $adapter);
            $this->fail('Job should rethrow so Horizon retries it.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('boom', $e->getMessage());
        }

        $log->refresh();
        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('boom', (string) $log->error_message);

        $this->conn->refresh();
        $this->assertSame('active', $this->conn->status); // connection is permanent — never disconnected by a failed sync
        $this->assertStringContainsString('boom', (string) $this->conn->last_error);

        // Missing data is NOT zero: no daily_metrics row was written.
        $this->assertSame(0, DailyMetric::where('brand_id', $this->brand->id)->count());
    }

    public function test_rate_limit_releases_job_and_requeues_log(): void
    {
        $date = CarbonImmutable::now('UTC')->subDay()->startOfDay();
        $log  = $this->queuedLog($date);

        $adapter = new FakeShopifyAdapter(
            fn () => throw new PlatformRateLimitedException(120, 'shopify', 'cost ceiling'),
        );

        $job = new SyncBrandDayJob($this->brand, $this->conn, $date, $log->id);

        // A real queue job: release() must be called with delay + jitter.
        $queueJob = Mockery::mock(Job::class)->shouldIgnoreMissing();
        $queueJob->shouldReceive('getConnectionName')->andReturn('redis');
        $queueJob->shouldReceive('release')->once()->withArgs(
            fn (int $delay) => $delay >= 121 && $delay <= 135, // 120s + 1–15s jitter
        );
        $job->setJob($queueJob);

        $this->runJob($job, $adapter); // must NOT throw — a rate limit is not a failure

        $log->refresh();
        $this->assertSame('queued', $log->status, 'rate-limited work goes back to pending, not failed');
        $this->assertStringContainsString('back off', (string) $log->error_message);
        $this->assertSame(0, DailyMetric::where('brand_id', $this->brand->id)->count());
    }

    public function test_failed_hook_marks_stranded_log_failed(): void
    {
        $date = CarbonImmutable::now('UTC')->subDay()->startOfDay();
        $log  = $this->queuedLog($date);

        $job = new SyncBrandDayJob($this->brand, $this->conn, $date, $log->id);
        $job->failed(new RuntimeException('worker timeout'));

        $log->refresh();
        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('worker timeout', (string) $log->error_message);

        // A log that already finished is left alone.
        $done = $this->queuedLog($date->subDay());
        $done->update(['status' => 'success']);
        (new SyncBrandDayJob($this->brand, $this->conn, $date->subDay(), $done->id))
            ->failed(new RuntimeException('late timeout'));
        $this->assertSame('success', $done->refresh()->status);
    }
}

/**
 * Minimal in-test adapter: fetchDay() delegates to the closure; every other
 * contract method is unreachable in these tests.
 */
final class FakeShopifyAdapter implements PlatformAdapter
{
    /** @param \Closure(): MetricSnapshot $fetch */
    public function __construct(private readonly \Closure $fetch) {}

    public function key(): string
    {
        return 'shopify';
    }

    public function label(): string
    {
        return 'Shopify (fake)';
    }

    public function authUrl(Brand $brand): string
    {
        throw new RuntimeException('not used in tests');
    }

    public function handleCallback(Brand $brand, array $payload): PlatformConnection
    {
        throw new RuntimeException('not used in tests');
    }

    public function listAvailableAccounts(PlatformConnection $conn): array
    {
        return [];
    }

    public function attachAccount(PlatformConnection $conn, string $externalId): void
    {
    }

    public function fetchDay(PlatformConnection $conn, CarbonImmutable $date): MetricSnapshot
    {
        return ($this->fetch)();
    }

    public function healthCheck(PlatformConnection $conn): bool
    {
        return true;
    }
}
