<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Platforms\Contracts\MetricSnapshot;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Guards the daily_metrics row contract — specifically that Shopify's
 * "Total sales" (total_sales) and Net sales are carried through toRow() and are
 * upsert-updateable. Regressing this silently zeroes the dashboard's Total
 * revenue + Blended ROAS (shipped 2026-06-19). Boots Laravel only because
 * toRow() stamps pulled_at via now(); it touches no database.
 */
final class MetricSnapshotTest extends TestCase
{
    public function test_to_row_carries_net_and_total_sales(): void
    {
        $snap = new MetricSnapshot(
            brandId: 7,
            platform: 'shopify',
            date: CarbonImmutable::parse('2026-06-18'),
            currency: 'EUR',
            revenue: 227148.34,
            revenueNet: 227148.34,
            netSales: 165318.15,
            totalSales: 205695.09,
            orders: 3683,
            refundsAmount: 0.0,
        );

        $row = $snap->toRow(1.08, fxPending: false);

        $this->assertSame(165318.15, $row['net_sales']);
        $this->assertSame(205695.09, $row['total_sales']);
        $this->assertSame('shopify', $row['platform']);
        $this->assertSame(7, $row['brand_id']);
        $this->assertSame('2026-06-18', $row['date']);
        $this->assertSame(1.08, $row['fx_rate_to_usd']);
    }

    public function test_total_sales_is_updateable_on_upsert(): void
    {
        $snap = new MetricSnapshot(
            brandId: 1,
            platform: 'shopify',
            date: CarbonImmutable::parse('2026-06-18'),
            currency: 'EUR',
        );

        $this->assertContains('total_sales', $snap->updateableFields());
        $this->assertContains('net_sales', $snap->updateableFields());
    }

    public function test_ad_snapshot_leaves_sales_columns_null(): void
    {
        $snap = new MetricSnapshot(
            brandId: 1,
            platform: 'meta',
            date: CarbonImmutable::parse('2026-06-18'),
            currency: 'EUR',
            spend: 1200.0,
        );

        $row = $snap->toRow(1.0);

        $this->assertNull($row['total_sales']);
        $this->assertNull($row['net_sales']);
        $this->assertSame(1200.0, $row['spend']);
    }
}
