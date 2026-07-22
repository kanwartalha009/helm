<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Platforms\Meta\AdProductFetcher;
use PHPUnit\Framework\TestCase;

/**
 * The landing-URL → product-handle regex (spec §4 Phase 5) shared by all three
 * platform ad-product fetchers. Locale prefixes and query/fragment tails must be
 * ignored; non-product URLs resolve to the reserved buckets, never a wrong handle.
 * Pure static methods — no DB.
 */
final class AdProductClassifyTest extends TestCase
{
    public function test_product_handle_ignores_locale_and_query(): void
    {
        $this->assertSame('blue-tee', AdProductFetcher::productHandle('https://shop.com/products/blue-tee'));
        $this->assertSame('blue-tee', AdProductFetcher::productHandle('https://shop.com/en-de/products/blue-tee?variant=42'));
        $this->assertSame('red-cap', AdProductFetcher::productHandle('https://shop.com/fr/products/red-cap#reviews'));
        $this->assertSame('red-cap', AdProductFetcher::productHandle('https://shop.com/it/products/red-cap/'));
    }

    public function test_non_product_urls_return_null_handle(): void
    {
        $this->assertNull(AdProductFetcher::productHandle('https://shop.com/collections/summer'));
        $this->assertNull(AdProductFetcher::productHandle('https://shop.com/pages/about'));
        $this->assertNull(AdProductFetcher::productHandle('https://shop.com/'));
        $this->assertNull(AdProductFetcher::productHandle(''));
    }

    public function test_classify_maps_to_reserved_buckets(): void
    {
        $this->assertSame('blue-tee', AdProductFetcher::classify('https://shop.com/products/blue-tee'));
        $this->assertSame(AdProductFetcher::RESERVED_COLLECTION, AdProductFetcher::classify('https://shop.com/collections/summer'));
        $this->assertSame(AdProductFetcher::RESERVED_OTHER, AdProductFetcher::classify('https://shop.com/'));
        $this->assertSame(AdProductFetcher::RESERVED_OTHER, AdProductFetcher::classify(''));
    }

    public function test_reconcile_tops_up_other_to_the_campaign_truth(): void
    {
        // Incident fix (Kanwar, 2026-07-22): level=ad is ~35% short on Advantage+/
        // partnership/dark spend, which has no ad row. reconcileToCampaign adds the
        // per-day remainder to __other so the product table reconciles to the
        // campaign truth — money shown honestly, never dropped or faked onto a product.
        $rows = [
            ['date' => '2026-07-01', 'key' => 'amboise-studs', 'spend' => 600.0, 'ads' => 2, 'currency' => 'EUR'],
            ['date' => '2026-07-01', 'key' => '__other',       'spend' => 100.0, 'ads' => 1, 'currency' => 'EUR'],
            ['date' => '2026-07-02', 'key' => 'amboise-studs', 'spend' => 500.0, 'ads' => 1, 'currency' => 'EUR'],
        ];
        // Day 1 campaign truth 1000 (attributed 700 → +300 to __other); day 2 truth
        // 800 (attributed 500, no __other row → append 300); day 3 truth with no rows.
        $campaignByDay = ['2026-07-01' => 1000.0, '2026-07-02' => 800.0, '2026-07-03' => 250.0];

        $out = AdProductFetcher::reconcileToCampaign($rows, $campaignByDay, 'EUR');

        // Each day's rows now sum to the campaign truth.
        $sum = static function (array $rows, string $day): float {
            return array_sum(array_map(
                static fn ($r) => $r['date'] === $day ? (float) $r['spend'] : 0.0,
                $rows,
            ));
        };
        $this->assertEqualsWithDelta(1000.0, $sum($out, '2026-07-01'), 0.001);
        $this->assertEqualsWithDelta(800.0, $sum($out, '2026-07-02'), 0.001);
        $this->assertEqualsWithDelta(250.0, $sum($out, '2026-07-03'), 0.001); // appended __other only

        // The existing product row is untouched — only __other absorbs the remainder.
        $amboiseD1 = array_values(array_filter($out, static fn ($r) => $r['date'] === '2026-07-01' && $r['key'] === 'amboise-studs'));
        $this->assertEqualsWithDelta(600.0, $amboiseD1[0]['spend'], 0.001);
        $otherD1 = array_values(array_filter($out, static fn ($r) => $r['date'] === '2026-07-01' && $r['key'] === '__other'));
        $this->assertEqualsWithDelta(400.0, $otherD1[0]['spend'], 0.001); // 100 + 300

        // A day where ad-level already meets/exceeds campaign is left alone.
        $noTouch = AdProductFetcher::reconcileToCampaign(
            [['date' => '2026-07-04', 'key' => 'x', 'spend' => 900.0, 'ads' => 1, 'currency' => 'EUR']],
            ['2026-07-04' => 850.0],
            'EUR',
        );
        $this->assertCount(1, $noTouch); // no __other appended
        $this->assertEqualsWithDelta(900.0, $noTouch[0]['spend'], 0.001);
    }

    public function test_extract_url_reads_the_confirmed_creative_field_paths(): void
    {
        // Regression guard on extractUrl's field precedence (video CTA → link_data
        // → template → asset feed → top-level). effective_object_story_spec is NOT
        // read: it is not a field on the adcreative node (Meta error 100), and
        // requesting it 400s the whole batch — proven live 2026-07-22.
        $this->assertSame('https://s.com/products/a', AdProductFetcher::extractUrl([
            'object_story_spec' => ['video_data' => ['call_to_action' => ['value' => ['link' => 'https://s.com/products/a']]]],
        ]));
        $this->assertSame('https://s.com/products/b', AdProductFetcher::extractUrl([
            'object_story_spec' => ['link_data' => ['link' => 'https://s.com/products/b']],
        ]));
        $this->assertSame('https://s.com/products/c', AdProductFetcher::extractUrl([
            'asset_feed_spec' => ['link_urls' => [['website_url' => 'https://s.com/products/c']]],
        ]));
        $this->assertSame('https://s.com/products/d', AdProductFetcher::extractUrl(['link_url' => 'https://s.com/products/d']));
        // A creative with no readable link → '' (caller buckets it into __other).
        $this->assertSame('', AdProductFetcher::extractUrl(['object_story_spec' => []]));
    }
}
