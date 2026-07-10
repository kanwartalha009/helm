<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Platforms\Meta\InsightsFetcher;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit test — no Laravel boot, no network. Pins the Meta insights row to
 * MetricSnapshot mapping: 7-day-click attribution, omni_purchase preferred for
 * conversions/value, and a graceful empty-row fallback. This is the logic that
 * decides what ad spend and ROAS the dashboard will eventually show, so it must
 * not drift silently.
 */
final class MetaInsightsMapperTest extends TestCase
{
    /** @return array<string, mixed> */
    private function sampleRow(): array
    {
        return [
            'account_currency' => 'EUR',
            'spend'            => '123.45',
            'impressions'      => '10000',
            'clicks'           => '350',
            'actions' => [
                ['action_type' => 'landing_page_view', 'value' => '500', '7d_click' => '480'],
                ['action_type' => 'omni_purchase',     'value' => '42',  '7d_click' => '37'],
                ['action_type' => 'purchase',          'value' => '40',  '7d_click' => '35'],
                ['action_type' => 'omni_add_to_cart',  'value' => '210', '7d_click' => '180'],
                ['action_type' => 'add_to_cart',       'value' => '200', '7d_click' => '170'],
                ['action_type' => 'omni_initiated_checkout', 'value' => '88', '7d_click' => '75'],
            ],
            'action_values' => [
                ['action_type' => 'omni_purchase', 'value' => '5000.00', '7d_click' => '4200.50'],
            ],
        ];
    }

    public function test_it_maps_core_metrics_and_leaves_commerce_fields_null(): void
    {
        $s = InsightsFetcher::mapInsightRow($this->sampleRow(), 7, CarbonImmutable::parse('2026-05-30'), 'USD', true);

        $this->assertSame('meta', $s->platform);
        $this->assertSame(7, $s->brandId);
        $this->assertSame('EUR', $s->currency);
        $this->assertEqualsWithDelta(123.45, $s->spend, 0.0001);
        $this->assertSame(10000, $s->impressions);
        $this->assertSame(350, $s->clicks);
        $this->assertTrue($s->isComplete);
        // Meta fills no commerce fields — those stay null on the polymorphic row.
        $this->assertNull($s->revenue);
        $this->assertNull($s->orders);
    }

    public function test_it_uses_7d_click_attribution_and_prefers_omni_purchase(): void
    {
        $s = InsightsFetcher::mapInsightRow($this->sampleRow(), 1, CarbonImmutable::parse('2026-05-30'), 'USD', true);

        // omni_purchase 7d_click = 37 conversions and 4200.50 value — NOT the
        // default 'value', NOT the lower-priority 'purchase' action.
        $this->assertSame(37, $s->conversions);
        $this->assertEqualsWithDelta(4200.50, $s->conversionValue, 0.0001);
        $this->assertSame('7d_click', $s->metadata['attribution_window']);
    }

    public function test_it_parses_funnel_steps_from_plain_action_values(): void
    {
        $s = InsightsFetcher::mapInsightRow($this->sampleRow(), 1, CarbonImmutable::parse('2026-05-30'), 'USD', true);

        // Funnel steps use the PLAIN value (they're steps, not attributed
        // conversions) and prefer the omni_* type over the pixel-specific one.
        $this->assertSame(210, $s->addToCarts);
        $this->assertSame(88, $s->checkoutsInitiated);
    }

    public function test_empty_row_yields_zeroes_with_fallback_currency(): void
    {
        $s = InsightsFetcher::mapInsightRow([], 1, CarbonImmutable::parse('2026-05-30'), 'gbp', false);

        $this->assertSame('GBP', $s->currency);
        $this->assertEqualsWithDelta(0.0, $s->spend, 0.0001);
        $this->assertSame(0, $s->conversions);
        $this->assertEqualsWithDelta(0.0, $s->conversionValue, 0.0001);
        $this->assertFalse($s->isComplete);
    }

    public function test_account_id_normalizes_to_act_prefix(): void
    {
        $this->assertSame('act_123', InsightsFetcher::normalizeAccountId('123'));
        $this->assertSame('act_123', InsightsFetcher::normalizeAccountId('act_123'));
    }
}
