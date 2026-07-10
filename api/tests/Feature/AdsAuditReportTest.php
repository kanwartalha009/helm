<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The platform-scoped ads-audit report (campaign-level, shareable). Verifies
 * the per-platform blocks with hand-computed KPI math, the movers ranking by
 * absolute spend shift, the ?platform= scope (including the unconnected-
 * platform → empty case), the evidence-aware confidence tags with windowDays,
 * and the FAIL-CLOSED freshness gate when no campaign rows exist.
 */
class AdsAuditReportTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Europe/Madrid';

    private function makeBrand(): Brand
    {
        return Brand::factory()->create([
            'base_currency' => 'EUR',
            'timezone'      => self::TZ,
            'status'        => 'active',
        ]);
    }

    private function connect(int $brandId, string $platform): void
    {
        DB::table('platform_connections')->insert([
            'brand_id'    => $brandId,
            'platform'    => $platform,
            'external_id' => "acct_{$platform}",
            'credentials' => '{}',
            'status'      => 'active',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    /** Seed one ad_campaign_daily_metrics row via DB::table with date-only strings (sqlite trap). */
    private function seedCampaignDay(int $brandId, string $platform, string $date, string $cid, string $name, float $spend, float $value, int $conversions, float $fx = 1.0, int $impressions = 10000, int $clicks = 200, ?string $status = null, ?string $channelType = null, ?float $budgetLostIs = null): void
    {
        DB::table('ad_campaign_daily_metrics')->insert([
            'brand_id'         => $brandId,
            'platform'         => $platform,
            'date'             => $date,
            'campaign_id'      => $cid,
            'campaign_name'    => $name,
            'status'           => $status,
            'channel_type'     => $channelType,
            'spend'            => $spend,
            'impressions'      => $impressions,
            'clicks'           => $clicks,
            'conversions'      => $conversions,
            'conversion_value' => $value,
            'search_budget_lost_is' => $budgetLostIs,
            'currency'         => 'EUR',
            'fx_rate_to_usd'   => $fx,
            'is_complete'      => true,
            'pulled_at'        => now(),
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    /** Seed one meta_breakdown_daily row (date-only strings — sqlite trap). */
    private function seedBreakdownDay(int $brandId, string $platform, string $date, string $type, string $key, ?string $label, float $spend, int $impressions, int $clicks, int $conversions, float $value, float $fx = 1.0): void
    {
        DB::table('meta_breakdown_daily')->insert([
            'brand_id'         => $brandId,
            'platform'         => $platform,
            'date'             => $date,
            'breakdown_type'   => $type,
            'segment_key'      => $key,
            'segment_label'    => $label,
            'spend'            => $spend,
            'impressions'      => $impressions,
            'clicks'           => $clicks,
            'conversions'      => $conversions,
            'conversion_value' => $value,
            'currency'         => 'EUR',
            'fx_rate_to_usd'   => $fx,
            'is_complete'      => true,
            'pulled_at'        => now(),
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    /** Seed one ad_creative_daily row (date-only strings — sqlite trap). */
    private function seedCreativeDay(int $brandId, string $platform, string $date, string $adId, string $campaignId, float $spend, float $value, int $conversions = 0, int $impressions = 10000, int $clicks = 200, int $video3s = 0, int $thruplays = 0, ?string $mediaType = null, ?string $thumb = null, ?string $qualityRanking = null, ?string $engagementRanking = null, float $fx = 1.0): void
    {
        DB::table('ad_creative_daily')->insert([
            'brand_id'           => $brandId,
            'platform'           => $platform,
            'date'               => $date,
            'ad_id'              => $adId,
            'ad_name'            => "Ad {$adId}",
            'campaign_id'        => $campaignId,
            'thumbnail_url'      => $thumb,
            'media_type'         => $mediaType,
            'spend'              => $spend,
            'impressions'        => $impressions,
            'clicks'             => $clicks,
            'video_3s'           => $video3s,
            'thruplays'          => $thruplays,
            'add_to_cart'        => 0,
            'quality_ranking'    => $qualityRanking,
            'engagement_ranking' => $engagementRanking,
            'conversion_ranking' => null,
            'conversions'        => $conversions,
            'conversion_value'   => $value,
            'currency'           => 'EUR',
            'fx_rate_to_usd'     => $fx,
            'is_complete'        => true,
            'pulled_at'          => now(),
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    public function test_builds_per_platform_blocks_with_kpi_math_movers_and_confidence(): void
    {
        $user  = User::factory()->create(['role' => 'master_admin']);
        $brand = $this->makeBrand();
        $this->connect($brand->id, 'meta');
        $this->connect($brand->id, 'google');

        // period=last7 → [yesterday-6 .. yesterday]; previous → the 7 days
        // before that. Current rows land on yesterday so freshness is green.
        $yesterday = CarbonImmutable::now(self::TZ)->subDay()->startOfDay();
        $cur       = $yesterday->toDateString();
        $prev      = $yesterday->subDays(7)->toDateString();

        // Meta m1: 200 spend / 800 value / 10 conv now, 100 / 200 / 5 before →
        // ROAS 4.0 (winner, solid: 200 ≥ 150), spend shift |200-100| = 100.
        $this->seedCampaignDay($brand->id, 'meta', $cur, 'm1', 'Prospecting', 200, 800, 10);
        $this->seedCampaignDay($brand->id, 'meta', $prev, 'm1', 'Prospecting', 100, 200, 5);
        // Meta m2: 60 spend / 30 value / 2 conv, no prior rows → ROAS 0.5
        // (dead) but EARLY — only 60 USD of evidence (< 150). Shift 60.
        $this->seedCampaignDay($brand->id, 'meta', $cur, 'm2', 'New test', 60, 30, 2);
        // Google g1: no comparison rows → null-safe previous.
        $this->seedCampaignDay($brand->id, 'google', $cur, 'g1', 'Brand search', 300, 900, 3);

        Sanctum::actingAs($user);
        $res = $this->getJson("/api/brands/{$brand->slug}/reports/ads-audit?period=last7&compare=previous")
            ->assertOk()
            ->assertJsonPath('reportType', 'ads-audit')
            ->assertJsonPath('brand.slug', $brand->slug)
            ->assertJsonPath('platformFilter', null)
            ->assertJsonPath('hasData', true)
            ->assertJsonStructure([
                'currency',
                'period'     => ['label', 'start', 'end'],
                'comparison' => ['label', 'start', 'end'],
                'platforms'  => [['platform', 'kpis' => ['spend', 'conversionValue', 'roas', 'purchases', 'cpa'], 'audit', 'movers']],
                'freshness'  => ['upToDate', 'lastSynced', 'staleDays', 'windowEnd'],
            ]);

        $platforms = collect($res->json('platforms'))->keyBy('platform');
        $this->assertCount(2, $platforms);

        // Meta KPIs, hand-computed: spend 260 vs 100 (+160%), value 830 vs 200
        // (+315%), ROAS 830/260 = 3.19 vs 2.0 (Δ +1.19 abs), purchases 12 vs 5
        // (+140%), CPA 260/12 = 21.67 vs 20.0.
        $meta = $platforms['meta'];
        $this->assertEquals(260.0, $meta['kpis']['spend']['value']);
        $this->assertEquals(100.0, $meta['kpis']['spend']['previous']);
        $this->assertEqualsWithDelta(160.0, $meta['kpis']['spend']['deltaPct'], 0.1);
        $this->assertEquals(830.0, $meta['kpis']['conversionValue']['value']);
        $this->assertEqualsWithDelta(315.0, $meta['kpis']['conversionValue']['deltaPct'], 0.1);
        $this->assertEquals(3.19, $meta['kpis']['roas']['value']);
        $this->assertEquals(2.0, $meta['kpis']['roas']['previous']);
        $this->assertEquals(1.19, $meta['kpis']['roas']['deltaAbs']);
        $this->assertSame(12, $meta['kpis']['purchases']['value']);
        $this->assertSame(5, $meta['kpis']['purchases']['previous']);
        $this->assertEqualsWithDelta(140.0, $meta['kpis']['purchases']['deltaPct'], 0.1);
        $this->assertEquals(21.67, $meta['kpis']['cpa']['value']);
        $this->assertEquals(20.0, $meta['kpis']['cpa']['previous']);

        // Movers ranked by |spend shift|: m1 (100) before m2 (60), each carrying
        // the AdAudit verdict + evidence confidence.
        $movers = $meta['movers'];
        $this->assertSame(['m1', 'm2'], array_column($movers, 'campaignId'));
        $this->assertEquals(200.0, $movers[0]['spend']);
        $this->assertEquals(100.0, $movers[0]['prevSpend']);
        $this->assertEqualsWithDelta(100.0, $movers[0]['spendDeltaPct'], 0.1);
        $this->assertEquals(4.0, $movers[0]['roas']);
        $this->assertEquals(2.0, $movers[0]['prevRoas']);
        $this->assertSame('winner', $movers[0]['verdict']);
        $this->assertSame('solid', $movers[0]['confidence']); // 200 USD ≥ 150 evidence floor
        $this->assertSame('dead', $movers[1]['verdict']);
        $this->assertSame('early', $movers[1]['confidence']); // 60 USD < 150 — under-evidenced
        $this->assertNull($movers[1]['prevSpend']);           // missing ≠ zero

        // The embedded AdAudit block carries the window size + tagged campaigns.
        $this->assertSame(7, $meta['audit']['windowDays']);
        $auditById = collect($meta['audit']['campaigns'])->keyBy('id');
        $this->assertSame('solid', $auditById['m1']['confidence']);
        $this->assertSame('early', $auditById['m2']['confidence']);

        // Google: no comparison rows → previous/deltas null, never 0-based.
        $google = $platforms['google'];
        $this->assertEquals(300.0, $google['kpis']['spend']['value']);
        $this->assertNull($google['kpis']['spend']['previous']);
        $this->assertNull($google['kpis']['spend']['deltaPct']);
        $this->assertNull($google['movers'][0]['prevSpend']);

        // Freshness: campaign rows reach the window end (yesterday).
        $this->assertTrue($res->json('freshness.upToDate'));
        $this->assertSame($cur, $res->json('freshness.lastSynced'));
    }

    public function test_platform_filter_scopes_to_one_platform(): void
    {
        $user  = User::factory()->create(['role' => 'master_admin']);
        $brand = $this->makeBrand();
        $this->connect($brand->id, 'meta');
        $this->connect($brand->id, 'google');

        $cur = CarbonImmutable::now(self::TZ)->subDay()->toDateString();
        $this->seedCampaignDay($brand->id, 'meta', $cur, 'm1', 'Prospecting', 200, 800, 10);
        $this->seedCampaignDay($brand->id, 'google', $cur, 'g1', 'Brand search', 300, 900, 3);

        Sanctum::actingAs($user);
        $res = $this->getJson("/api/brands/{$brand->slug}/reports/ads-audit?period=last7&platform=google")
            ->assertOk()
            ->assertJsonPath('platformFilter', 'google')
            ->assertJsonPath('hasData', true);

        $platforms = $res->json('platforms');
        $this->assertCount(1, $platforms);
        $this->assertSame('google', $platforms[0]['platform']);
    }

    public function test_filter_on_an_unconnected_platform_yields_no_data_not_an_error(): void
    {
        $user  = User::factory()->create(['role' => 'master_admin']);
        $brand = $this->makeBrand();
        $this->connect($brand->id, 'meta');

        $cur = CarbonImmutable::now(self::TZ)->subDay()->toDateString();
        $this->seedCampaignDay($brand->id, 'meta', $cur, 'm1', 'Prospecting', 200, 800, 10);

        Sanctum::actingAs($user);
        $res = $this->getJson("/api/brands/{$brand->slug}/reports/ads-audit?period=last7&platform=tiktok")
            ->assertOk()
            ->assertJsonPath('platformFilter', 'tiktok')
            ->assertJsonPath('hasData', false);

        $this->assertSame([], $res->json('platforms'));
    }

    public function test_freshness_fails_closed_when_no_campaign_data_exists(): void
    {
        $user  = User::factory()->create(['role' => 'master_admin']);
        $brand = $this->makeBrand();
        $this->connect($brand->id, 'meta');

        Sanctum::actingAs($user);
        $res = $this->getJson("/api/brands/{$brand->slug}/reports/ads-audit?period=last7")
            ->assertOk()
            ->assertJsonPath('hasData', false);

        // Empty ad_campaign_daily_metrics → the gate holds the report back:
        // never "up to date" without a synced row to prove it.
        $this->assertFalse($res->json('freshness.upToDate'));
        $this->assertNull($res->json('freshness.lastSynced'));
        $this->assertSame([], $res->json('platforms'));
    }

    /** One authenticated report fetch, returning the platform blocks keyed by platform. */
    private function fetchPlatforms(Brand $brand, string $query = 'period=last7&compare=previous'): array
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));

        return collect($this->getJson("/api/brands/{$brand->slug}/reports/ads-audit?{$query}")
            ->assertOk()
            ->json('platforms'))->keyBy('platform')->all();
    }

    public function test_best_and_worst_rankings_with_null_roas_spender_worst_first(): void
    {
        $brand = $this->makeBrand();
        $this->connect($brand->id, 'meta');
        $cur = CarbonImmutable::now(self::TZ)->subDay()->toDateString();

        // Five clear winners so `best` fills up and `worst` must draw on the rest.
        $this->seedCampaignDay($brand->id, 'meta', $cur, 'w1', 'W1', 200, 1000, 4, status: 'active'); // ROAS 5.0, solid
        $this->seedCampaignDay($brand->id, 'meta', $cur, 'w2', 'W2', 100, 400, 2);   // 4.0
        $this->seedCampaignDay($brand->id, 'meta', $cur, 'w3', 'W3', 100, 350, 2);   // 3.5
        $this->seedCampaignDay($brand->id, 'meta', $cur, 'w4', 'W4', 100, 300, 2);   // 3.0
        $this->seedCampaignDay($brand->id, 'meta', $cur, 'w5', 'W5', 100, 250, 2);   // 2.5
        // Losers: two low ROAS + one spender with ZERO attribution (null, not 0×).
        $this->seedCampaignDay($brand->id, 'meta', $cur, 'l1', 'L1', 100, 50, 1);    // 0.5
        $this->seedCampaignDay($brand->id, 'meta', $cur, 'l2', 'L2', 100, 80, 1);    // 0.8
        $this->seedCampaignDay($brand->id, 'meta', $cur, 'z1', 'Z1', 120, 0, 0);     // null ROAS
        // Sub-$50: below AdAudit::MIN_SPEND — excluded from BOTH despite 10× ROAS.
        $this->seedCampaignDay($brand->id, 'meta', $cur, 'tiny', 'Tiny', 40, 400, 1);

        $meta = $this->fetchPlatforms($brand)['meta'];

        // best: ROAS desc, null-ROAS excluded, ≤5.
        $this->assertSame(['w1', 'w2', 'w3', 'w4', 'w5'], array_column($meta['best'], 'campaignId'));
        // worst: the null-ROAS spender FIRST, then ROAS asc; best members excluded.
        $this->assertSame(['z1', 'l1', 'l2'], array_column($meta['worst'], 'campaignId'));
        $this->assertNull($meta['worst'][0]['roas']); // zero attribution = null, never 0
        $this->assertEquals(0.5, $meta['worst'][1]['roas']);

        // Hand-verified row math for w1 (spend 200, value 1000, 4 conv, 10000 impr, 200 clicks).
        $w1 = $meta['best'][0];
        $this->assertSame('active', $w1['status']);
        $this->assertEquals(200.0, $w1['spend']);
        $this->assertEquals(1000.0, $w1['conversionValue']);
        $this->assertEquals(5.0, $w1['roas']);
        $this->assertEquals(50.0, $w1['cpa']);   // 200 / 4
        $this->assertEquals(2.0, $w1['ctr']);    // 200 / 10000 × 100
        $this->assertEquals(20.0, $w1['cpm']);   // 200 / 10000 × 1000
        $this->assertSame(4, $w1['purchases']);
        $this->assertSame('solid', $w1['confidence']); // 200 ≥ AdAudit::SOLID_SPEND
        $this->assertSame('early', $meta['worst'][1]['confidence']); // 100 < 150

        $all = array_merge(array_column($meta['best'], 'campaignId'), array_column($meta['worst'], 'campaignId'));
        $this->assertNotContains('tiny', $all);
    }

    public function test_segments_axes_present_with_hand_verified_math_and_empty_when_none(): void
    {
        $brand = $this->makeBrand();
        $this->connect($brand->id, 'meta');
        $this->connect($brand->id, 'google');
        $day  = CarbonImmutable::now(self::TZ)->subDay();
        $cur  = $day->toDateString();
        $cur2 = $day->subDays(2)->toDateString();

        $this->seedCampaignDay($brand->id, 'meta', $cur, 'm1', 'Prospecting', 200, 800, 10);
        $this->seedCampaignDay($brand->id, 'google', $cur, 'g1', 'Brand', 300, 900, 3);

        // age_gender f25 split across two days (aggregation across the window):
        // spend 300, 1000 impr, 25 clicks, 3 conv, 900 value.
        $this->seedBreakdownDay($brand->id, 'meta', $cur, 'age_gender', '25-34 · female', 'Women 25–34', 150, 500, 15, 2, 500);
        $this->seedBreakdownDay($brand->id, 'meta', $cur2, 'age_gender', '25-34 · female', 'Women 25–34', 150, 500, 10, 1, 400);
        // m25: spends with ZERO conversions data → roas null, never 0.
        $this->seedBreakdownDay($brand->id, 'meta', $cur, 'age_gender', '25-34 · male', 'Men 25–34', 100, 2000, 10, 0, 0);
        $this->seedBreakdownDay($brand->id, 'meta', $cur, 'device', 'mobile', 'Mobile', 50, 500, 5, 1, 100);

        $platforms = $this->fetchPlatforms($brand);
        $axes      = collect($platforms['meta']['segments']['axes'])->keyBy('axis');

        // Only the axes with rows appear, in canonical order (missing ≠ empty).
        $this->assertSame(['age_gender', 'device'], array_column($platforms['meta']['segments']['axes'], 'axis'));

        $ag = $axes['age_gender']['rows'];
        $this->assertSame(['25-34 · female', '25-34 · male'], array_column($ag, 'key')); // spend desc
        $this->assertSame('Women 25–34', $ag[0]['label']);
        $this->assertEquals(300.0, $ag[0]['spend']);
        $this->assertEquals(75.0, $ag[0]['sharePct']);  // 300 of 400 axis total
        $this->assertEquals(2.5, $ag[0]['ctr']);        // 25 / 1000 × 100
        $this->assertEquals(300.0, $ag[0]['cpm']);      // 300 / 1000 × 1000
        $this->assertEquals(3.0, $ag[0]['roas']);       // 900 / 300 (USD)
        $this->assertSame(3, $ag[0]['purchases']);
        $this->assertEquals(25.0, $ag[1]['sharePct']);
        $this->assertEquals(0.5, $ag[1]['ctr']);
        $this->assertNull($ag[1]['roas']);              // spent, zero attribution → null

        $dev = $axes['device']['rows'];
        $this->assertCount(1, $dev);
        $this->assertEquals(100.0, $dev[0]['sharePct']);
        $this->assertEquals(2.0, $dev[0]['roas']);

        // Google synced no breakdowns → axes [] (the SPA shows its note).
        $this->assertSame([], $platforms['google']['segments']['axes']);
    }

    public function test_creative_winners_fatigue_rules_and_google_block_has_no_creatives_key(): void
    {
        $brand = $this->makeBrand();
        $this->connect($brand->id, 'meta');
        $this->connect($brand->id, 'google');
        $this->connect($brand->id, 'tiktok');
        $day  = CarbonImmutable::now(self::TZ)->subDay();
        $cur  = $day->toDateString();
        $prev = $day->subDays(7)->toDateString();

        $this->seedCampaignDay($brand->id, 'meta', $cur, 'c1', 'Prospecting', 500, 1200, 10);
        $this->seedCampaignDay($brand->id, 'google', $cur, 'g1', 'Brand', 300, 900, 3);
        $this->seedCampaignDay($brand->id, 'tiktok', $cur, 't1', 'Spark', 100, 300, 2);

        // ROAS values with spend: A 4.0, B 1.0, C 2.0, F 1.4, G 10.0, H 0.5 →
        // median 1.7... sorted [0.5, 1.0, 1.4, 2.0, 4.0, 10.0] → (1.4+2.0)/2 = 1.7;
        // winner threshold = 2.0 × 1.7 = 3.4 (CreativeReport::SCALE_ROAS_MULT).
        $this->seedCreativeDay($brand->id, 'meta', $cur, 'A', 'c1', 60, 240, 2, 10000, 200, 1000, 400, 'video', 'https://cdn/a.jpg');
        $this->seedCreativeDay($brand->id, 'meta', $cur, 'B', 'c1', 60, 60, 1);
        $this->seedCreativeDay($brand->id, 'meta', $cur, 'C', 'c1', 60, 120, 1);
        // F: fatigued — spend 120 ≥ $100 floor, prev ROAS 2.0 → 1.4 = EXACTLY a
        // 30% drop (threshold inclusive). CTR flat so only ROAS triggers.
        $this->seedCreativeDay($brand->id, 'meta', $cur, 'F', 'c1', 120, 168, 2, 10000, 200, 1000, 400, 'video', 'https://cdn/f.jpg');
        $this->seedCreativeDay($brand->id, 'meta', $prev, 'F', 'c1', 100, 200, 2, 10000, 200);
        // G: 10× ROAS but $40 spend < SCALE_MIN_SPEND → never a winner.
        $this->seedCreativeDay($brand->id, 'meta', $cur, 'G', 'c1', 40, 400, 1);
        // H: 75% ROAS drop but $90 < FATIGUE_MIN_SPEND → never fatigued.
        $this->seedCreativeDay($brand->id, 'meta', $cur, 'H', 'c1', 90, 45, 1, 10000, 200);
        $this->seedCreativeDay($brand->id, 'meta', $prev, 'H', 'c1', 100, 200, 2, 10000, 200);

        $platforms = $this->fetchPlatforms($brand);
        $meta      = $platforms['meta'];

        $this->assertSame('ok', $meta['creatives']['status']);
        $this->assertSame(['A'], array_column($meta['creatives']['winners'], 'adId')); // 4.0 ≥ 3.4; G sub-$50 excluded
        $this->assertSame(['F'], array_column($meta['creatives']['fatigued'], 'adId')); // H sub-$100 excluded

        // Hand-verified fatigued row (spend 120, value 168, 2 conv, 10000 impr,
        // 200 clicks, 1000 video_3s, 400 thruplays).
        $f = $meta['creatives']['fatigued'][0];
        $this->assertSame('Ad F', $f['name']);
        $this->assertSame('https://cdn/f.jpg', $f['thumbnailUrl']);
        $this->assertSame('video', $f['mediaType']);
        $this->assertEquals(120.0, $f['spend']);
        $this->assertEquals(1.4, $f['roas']);
        $this->assertEquals(2.0, $f['ctr']);
        $this->assertEquals(10.0, $f['thumbstopPct']); // 1000 / 10000 × 100
        $this->assertEquals(40.0, $f['holdPct']);      // 400 / 1000 × 100
        $this->assertEquals(60.0, $f['cpa']);          // 120 / 2
        $this->assertFalse($f['belowAverage']);

        // Google has no creative grain → no `creatives` key at all.
        $this->assertArrayNotHasKey('creatives', $platforms['google']);
        // TikTok is a creative platform but synced no rows → explicit no_data.
        $this->assertSame(['winners' => [], 'fatigued' => [], 'status' => 'no_data'], $platforms['tiktok']['creatives']);
    }

    public function test_campaign_details_capped_at_12_by_spend_desc(): void
    {
        $brand = $this->makeBrand();
        $this->connect($brand->id, 'meta');
        $cur = CarbonImmutable::now(self::TZ)->subDay()->toDateString();

        for ($i = 1; $i <= 14; $i++) {
            $id = sprintf('c%02d', $i);
            $this->seedCampaignDay($brand->id, 'meta', $cur, $id, "C {$i}", 300 - $i, (300 - $i) * 2, 2);
        }

        $details = $this->fetchPlatforms($brand)['meta']['campaignDetails'];
        $this->assertCount(12, $details);
        $ids = array_column($details, 'campaignId');
        $this->assertSame('c01', $ids[0]); // biggest spender first
        $this->assertSame('c12', $ids[11]);
        $this->assertNotContains('c13', $ids);
        $this->assertNotContains('c14', $ids);
    }

    public function test_campaign_details_series_kpis_and_verdict_coherent_with_ad_audit(): void
    {
        $brand = $this->makeBrand();
        $this->connect($brand->id, 'meta');
        $day   = CarbonImmutable::now(self::TZ)->subDay();
        $d0    = $day->toDateString();
        $dMin2 = $day->subDays(2)->toDateString();

        // Two seeded days inside last7 — series must carry exactly those, in
        // date order, and a zero-attribution day reads ROAS null (never 0).
        $this->seedCampaignDay($brand->id, 'meta', $dMin2, 'm1', 'Prospecting', 50, 0, 0, status: 'active', channelType: null);
        $this->seedCampaignDay($brand->id, 'meta', $d0, 'm1', 'Prospecting', 100, 400, 2, status: 'active');

        $details = $this->fetchPlatforms($brand)['meta']['campaignDetails'];
        $this->assertCount(1, $details);
        $d = $details[0];

        $this->assertSame('m1', $d['campaignId']);
        $this->assertSame('active', $d['status']);
        $this->assertNull($d['channelType']);
        // Window totals: 150 spend / 400 value → ROAS 2.67 → AdAudit 'steady';
        // 150 USD spend = exactly the SOLID_SPEND floor → 'solid', not 'early'.
        $this->assertSame('steady', $d['verdict']);
        $this->assertSame('solid', $d['confidence']);
        $this->assertEquals(150.0, $d['kpis']['spend']);
        $this->assertNull($d['kpis']['prevSpend']); // no prior rows — missing ≠ 0
        $this->assertEquals(2.67, $d['kpis']['roas']);
        $this->assertNull($d['kpis']['prevRoas']);
        $this->assertEquals(75.0, $d['kpis']['cpa']);  // 150 / 2
        $this->assertEquals(2.0, $d['kpis']['ctr']);   // 400 clicks / 20000 impr × 100 (2 days)
        $this->assertEquals(7.5, $d['kpis']['cpm']);   // 150 / 20000 × 1000
        $this->assertSame(2, $d['kpis']['purchases']);

        $this->assertSame([$dMin2, $d0], array_column($d['series'], 'date'));
        $this->assertEquals(50.0, $d['series'][0]['spend']);
        $this->assertNull($d['series'][0]['roas']);    // spent, zero attribution → null
        $this->assertEquals(100.0, $d['series'][1]['spend']);
        $this->assertEquals(4.0, $d['series'][1]['roas']);

        // ROAS 2.67 with solid evidence → clean bill: no issues at all.
        $this->assertSame([], $d['issues']);
    }

    public function test_roas_issue_rules_trigger_at_threshold_and_not_just_below(): void
    {
        $brand = $this->makeBrand();
        $this->connect($brand->id, 'meta');
        $day  = CarbonImmutable::now(self::TZ)->subDay();
        $cur  = $day->toDateString();
        $prev = $day->subDays(7)->toDateString();

        // nullrev: $60 ≥ MIN_SPEND with zero attribution → critical + early info.
        $this->seedCampaignDay($brand->id, 'meta', $cur, 'nullrev', 'NR', 60, 0, 0);
        // nullrev_below: $49 < MIN_SPEND → the null-revenue critical must NOT fire.
        $this->seedCampaignDay($brand->id, 'meta', $cur, 'nullrev_below', 'NRB', 49, 0, 0);
        // dead: ROAS 0.5 < DEAD_ROAS on solid spend → exactly one critical.
        $this->seedCampaignDay($brand->id, 'meta', $cur, 'dead', 'D', 200, 100, 2);
        // dead_boundary: ROAS exactly 1.0 is NOT dead — falls to the weak warn.
        $this->seedCampaignDay($brand->id, 'meta', $cur, 'dead_boundary', 'DB', 200, 200, 2);
        // scaling: ROAS 1.5 while spend grew 33.3% (> SCALING 20) → critical.
        $this->seedCampaignDay($brand->id, 'meta', $cur, 'scaling', 'S', 200, 300, 3);
        $this->seedCampaignDay($brand->id, 'meta', $prev, 'scaling', 'S', 150, 150, 1);
        // scaling_boundary: growth EXACTLY 20% is not "> SCALING" → warn, not critical.
        $this->seedCampaignDay($brand->id, 'meta', $cur, 'scaling_boundary', 'SB', 240, 360, 3);
        $this->seedCampaignDay($brand->id, 'meta', $prev, 'scaling_boundary', 'SB', 200, 100, 1);
        // weak_boundary: ROAS exactly 1.8 = WEAK_ROAS → no ROAS issue at all.
        $this->seedCampaignDay($brand->id, 'meta', $cur, 'weak_boundary', 'WB', 200, 360, 3);

        $details = collect($this->fetchPlatforms($brand)['meta']['campaignDetails'])->keyBy('campaignId');
        $titles  = static fn (array $d): array => array_column($d['issues'], 'title');

        $nr = $details['nullrev'];
        $this->assertSame('critical', $nr['issues'][0]['severity']);
        $this->assertSame('Spending with no attributed revenue', $nr['issues'][0]['title']);
        $this->assertStringContainsString("pixel isn't firing", $nr['issues'][0]['detail']);
        $this->assertStringContainsString('60', $nr['issues'][0]['detail']);
        // Ordered: critical first, then the early-evidence info (60 < 150).
        $this->assertSame('Early signal — under $150 spend in this window; verify before acting', $nr['issues'][1]['title']);
        $this->assertSame('info', $nr['issues'][1]['severity']);

        $this->assertNotContains('Spending with no attributed revenue', $titles($details['nullrev_below']));
        $this->assertSame([], array_filter($details['nullrev_below']['issues'], fn ($i) => $i['severity'] === 'critical'));

        $this->assertSame(
            [['severity' => 'critical', 'title' => 'Below 1× ROAS — every euro in returns less than a euro out']],
            array_map(fn ($i) => ['severity' => $i['severity'], 'title' => $i['title']], $details['dead']['issues']),
        );
        $this->assertSame('dead', $details['dead']['verdict']); // coherent with AdAudit

        $this->assertSame(['Under the 1.8× working threshold'], $titles($details['dead_boundary']));
        $this->assertSame('warn', $details['dead_boundary']['issues'][0]['severity']);

        $s = $details['scaling'];
        $this->assertSame('critical', $s['issues'][0]['severity']);
        $this->assertSame('Scaling a loss — budget grew 33.3% while ROAS sits under 1.8×', $s['issues'][0]['title']);
        $this->assertSame('scaling_loss', $s['verdict']); // coherent with AdAudit

        $this->assertSame(['Under the 1.8× working threshold'], $titles($details['scaling_boundary']));

        $this->assertSame([], $details['weak_boundary']['issues']);
        $this->assertSame('steady', $details['weak_boundary']['verdict']);
    }

    public function test_ctr_floor_and_early_signal_issue_rules(): void
    {
        $brand = $this->makeBrand();
        $this->connect($brand->id, 'meta');
        $cur = CarbonImmutable::now(self::TZ)->subDay()->toDateString();

        // Healthy ROAS (4×) + solid spend everywhere so only the rule under test fires.
        // ctr: 4 / 1000 = 0.4% < the 0.5% floor with ≥1000 impressions → warn.
        $this->seedCampaignDay($brand->id, 'meta', $cur, 'ctr', 'C', 200, 800, 4, impressions: 1000, clicks: 4);
        // ctr_boundary: exactly 0.5% is NOT under the floor.
        $this->seedCampaignDay($brand->id, 'meta', $cur, 'ctr_boundary', 'CB', 200, 800, 4, impressions: 1000, clicks: 5);
        // ctr_lowimpr: 0.3% but only 999 impressions — not enough delivery to judge.
        $this->seedCampaignDay($brand->id, 'meta', $cur, 'ctr_lowimpr', 'CL', 200, 800, 4, impressions: 999, clicks: 3);
        // early: $100 < SOLID_SPEND → info; early_boundary: exactly $150 → clean.
        $this->seedCampaignDay($brand->id, 'meta', $cur, 'early', 'E', 100, 400, 2);
        $this->seedCampaignDay($brand->id, 'meta', $cur, 'early_boundary', 'EB', 150, 600, 3);

        $details = collect($this->fetchPlatforms($brand)['meta']['campaignDetails'])->keyBy('campaignId');

        $this->assertSame(
            [['severity' => 'warn', 'title' => 'CTR under the 0.5% floor — creative or audience mismatch']],
            array_map(fn ($i) => ['severity' => $i['severity'], 'title' => $i['title']], $details['ctr']['issues']),
        );
        $this->assertSame([], $details['ctr_boundary']['issues']);
        $this->assertSame([], $details['ctr_lowimpr']['issues']);

        $this->assertSame(
            [['severity' => 'info', 'title' => 'Early signal — under $150 spend in this window; verify before acting']],
            array_map(fn ($i) => ['severity' => $i['severity'], 'title' => $i['title']], $details['early']['issues']),
        );
        $this->assertSame('early', $details['early']['confidence']); // coherent with AdAudit
        $this->assertSame([], $details['early_boundary']['issues']);
        $this->assertSame('solid', $details['early_boundary']['confidence']);
    }

    public function test_google_budget_cap_issue_at_threshold_and_not_below(): void
    {
        $brand = $this->makeBrand();
        $this->connect($brand->id, 'google');
        $day  = CarbonImmutable::now(self::TZ)->subDay();
        $d0   = $day->toDateString();
        $d1   = $day->subDay()->toDateString();

        // AVG(search_budget_lost_is) = 0.10 = exactly the HELM floor → info fires.
        $this->seedCampaignDay($brand->id, 'google', $d0, 'g_lost', 'GL', 100, 400, 2, channelType: 'search', budgetLostIs: 0.10);
        $this->seedCampaignDay($brand->id, 'google', $d1, 'g_lost', 'GL', 100, 400, 2, channelType: 'search', budgetLostIs: 0.10);
        // 0.0999 — Google's sub-10% floor value — is just below: no issue.
        $this->seedCampaignDay($brand->id, 'google', $d0, 'g_below', 'GB', 200, 800, 4, channelType: 'search', budgetLostIs: 0.0999);
        // Metric never reported (NULL every day) → no issue, never a 0% claim.
        $this->seedCampaignDay($brand->id, 'google', $d0, 'g_null', 'GN', 200, 800, 4, channelType: 'performance_max');

        $details = collect($this->fetchPlatforms($brand)['google']['campaignDetails'])->keyBy('campaignId');

        $this->assertSame(
            [['severity' => 'info', 'title' => 'Losing 10% of eligible impressions to budget cap']],
            array_map(fn ($i) => ['severity' => $i['severity'], 'title' => $i['title']], $details['g_lost']['issues']),
        );
        $this->assertSame('search', $details['g_lost']['channelType']);
        $this->assertSame([], $details['g_below']['issues']);
        $this->assertSame([], $details['g_null']['issues']);
    }

    public function test_creative_below_average_and_fatigue_issues_join_by_campaign(): void
    {
        $brand = $this->makeBrand();
        $this->connect($brand->id, 'meta');
        $day  = CarbonImmutable::now(self::TZ)->subDay();
        $cur  = $day->toDateString();
        $prev = $day->subDays(7)->toDateString();

        // Both campaigns healthy (4×/3×, ≥ $150) so only creative issues can fire.
        $this->seedCampaignDay($brand->id, 'meta', $cur, 'cx', 'CX', 200, 800, 4);
        $this->seedCampaignDay($brand->id, 'meta', $cur, 'cy', 'CY', 300, 900, 3);

        // cx: one $60 creative Meta ranks below average → counts; the $40 one is
        // under the MIN_SPEND evidence floor → ignored.
        $this->seedCreativeDay($brand->id, 'meta', $cur, 'ba1', 'cx', 60, 240, 1, qualityRanking: 'below_average_35');
        $this->seedCreativeDay($brand->id, 'meta', $cur, 'ba2', 'cx', 40, 160, 1, engagementRanking: 'below_average_20');
        // cy: ft1 fatigues (spend 120 ≥ $100, ROAS 2.0 → 1.4 = exactly −30%);
        // ft2 same drop but $99 < floor; ft3 only −29% — both must NOT count.
        $this->seedCreativeDay($brand->id, 'meta', $cur, 'ft1', 'cy', 120, 168, 2);
        $this->seedCreativeDay($brand->id, 'meta', $prev, 'ft1', 'cy', 100, 200, 2);
        $this->seedCreativeDay($brand->id, 'meta', $cur, 'ft2', 'cy', 99, 99, 1);
        $this->seedCreativeDay($brand->id, 'meta', $prev, 'ft2', 'cy', 100, 200, 2);
        $this->seedCreativeDay($brand->id, 'meta', $cur, 'ft3', 'cy', 120, 170.4, 2);
        $this->seedCreativeDay($brand->id, 'meta', $prev, 'ft3', 'cy', 100, 200, 2);

        $details = collect($this->fetchPlatforms($brand)['meta']['campaignDetails'])->keyBy('campaignId');
        $titles  = static fn (array $d): array => array_column($d['issues'], 'title');

        $this->assertSame(['1 creative(s) ranked below average by the platform'], $titles($details['cx']));
        $this->assertSame('warn', $details['cx']['issues'][0]['severity']);
        $this->assertSame(['1 creative(s) fatiguing — ROAS/CTR down ≥30% vs the prior period'], $titles($details['cy']));
        $this->assertSame('warn', $details['cy']['issues'][0]['severity']);
    }
}
