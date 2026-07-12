<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdLibraryAd;
use App\Services\AdsLibrary\SignalScorer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ads Library Phase 2.6 — Signal Score materialization. The score is a disclosed
 * sort key: within a niche corpus, higher longevity + reach + variants ⇒ higher
 * score. Percentiles compare like-with-like within the niche.
 */
final class SignalScorerTest extends TestCase
{
    use RefreshDatabase;

    private function ad(string $id, array $o = []): AdLibraryAd
    {
        return AdLibraryAd::create(array_merge([
            'ad_archive_id' => $id, 'page_id' => 'P', 'niche' => 'footwear',
            'concept_hash' => sha1($id), 'is_active' => true,
            'delivery_start' => '2026-06-01', 'eu_total_reach' => 1000,
        ], $o));
    }

    public function test_higher_longevity_and_reach_scores_higher(): void
    {
        $today = CarbonImmutable::parse('2026-07-01');

        // Long-running, high reach.
        $strong = $this->ad('STRONG', ['delivery_start' => '2026-01-01', 'eu_total_reach' => 500000]);
        // Fresh, low reach.
        $weak = $this->ad('WEAK', ['delivery_start' => '2026-06-28', 'eu_total_reach' => 100]);

        $updated = app(SignalScorer::class)->materialize($today);
        $this->assertSame(2, $updated);

        $strong->refresh();
        $weak->refresh();

        $this->assertNotNull($strong->signal_score);
        $this->assertGreaterThan((float) $weak->signal_score, (float) $strong->signal_score);
        $this->assertGreaterThan(0, $strong->longevity_days);
    }

    public function test_longevity_days_uses_stop_when_present(): void
    {
        $today = CarbonImmutable::parse('2026-07-01');
        $scorer = app(SignalScorer::class);

        // 30 days delivered (start→stop), not counted to today.
        $this->assertSame(30, $scorer->longevityDays('2026-06-01', '2026-07-01', $today));
        // Still running → counts to today.
        $this->assertSame(30, $scorer->longevityDays('2026-06-01', null, $today));
        // No start → 0 (never guessed).
        $this->assertSame(0, $scorer->longevityDays(null, null, $today));
    }
}
