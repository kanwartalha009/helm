<?php

declare(strict_types=1);

namespace App\Services\AdsLibrary;

use App\Models\AdLibraryAd;
use App\Models\Brand;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Competitor gap map (GO-3.4, master plan §6.4).
 *
 * Joins two things nobody else can join:
 *   - what competitors in this brand's NICHE are actively running, by market  → PROXY
 *   - what this brand is actually spending, by market                          → VERIFIED
 *
 * and names the gaps: "5 competitors are live in FR with 12 video concepts; you have no
 * FR spend at all."
 *
 * ══ THE TWO SIDES ARE NEVER MIXED ══ (master plan §0 law 1)
 * Competitor activity is Proxy — public Ad Library signals (presence, concept counts,
 * formats). It contains NO spend and NO performance, because the EU Ad Library does not
 * expose them for commercial ads. Our own side is Verified — real money from our own ad
 * accounts. Every row carries both labels separately, and a competitor's "12 concepts"
 * must never be read as "12 concepts' worth of spend".
 *
 * What a gap is NOT: proof of an opportunity. Competitors being in a market means they
 * chose to be there — not that it works. This surface exists to raise a QUESTION for the
 * strategist (GO-4 sizes the answer), and it says so.
 */
class GapMap
{
    private const LOOKBACK_DAYS = 30;

    /**
     * @return array<string, mixed>
     */
    public function forBrand(Brand $brand): array
    {
        $niche = $brand->niche;

        // No niche → no peer group → nothing honest to say. Guessing a brand's
        // competitors from its name is exactly the kind of invention this product doesn't do.
        if ($niche === null || $niche === '') {
            return [
                'status' => 'no_niche',
                'note'   => 'Set this brand’s niche in Settings — the gap map compares it against competitors in the same niche, and Helm will not guess who those are.',
                'rows'   => [],
            ];
        }

        // ── Competitor side (PROXY — public Ad Library signals, no spend exists) ──
        $ads = AdLibraryAd::query()
            ->where('niche', $niche)
            ->where('is_active', true)
            ->get(['ad_archive_id', 'page_id', 'page_name', 'countries', 'media_type', 'concept_hash']);

        if ($ads->isEmpty()) {
            return [
                'status' => 'no_corpus',
                'niche'  => $niche,
                'note'   => 'No competitor ads stored for this niche yet. Track competitor pages on the Ads Library → Market tab, and the nightly refresh fills this in.',
                'rows'   => [],
            ];
        }

        /** @var array<string, array<string, mixed>> $byMarket */
        $byMarket = [];

        foreach ($ads as $ad) {
            $countries = is_array($ad->countries) ? $ad->countries : [];
            foreach ($countries as $c) {
                $market = strtoupper((string) $c);
                if ($market === '') {
                    continue;
                }

                $byMarket[$market] ??= ['concepts' => [], 'pages' => [], 'formats' => []];
                // Collapse to CONCEPTS, not raw ads — one advertiser running 40 variants
                // of one idea is not 40 ideas, and counting them that way would overstate
                // the market's activity (the same variant-spam problem the feed solves).
                $byMarket[$market]['concepts'][(string) $ad->concept_hash] = true;
                $byMarket[$market]['pages'][(string) $ad->page_id] = (string) ($ad->page_name ?? $ad->page_id);
                if ($ad->media_type) {
                    $f = (string) $ad->media_type;
                    $byMarket[$market]['formats'][$f] = ($byMarket[$market]['formats'][$f] ?? 0) + 1;
                }
            }
        }

        // ── Our side (VERIFIED — real money from our own ad accounts) ──
        $tz    = $brand->timezone ?: 'UTC';
        $since = CarbonImmutable::now($tz)->subDays(self::LOOKBACK_DAYS)->toDateString();

        $ownSpend = DB::table('meta_breakdown_daily')
            ->where('brand_id', $brand->id)
            ->where('breakdown_type', 'country')
            ->where('date', '>=', $since)
            ->groupBy('segment_key')
            ->selectRaw('segment_key, COALESCE(SUM(spend * COALESCE(fx_rate_to_usd, 1)), 0) AS spend_usd')
            ->pluck('spend_usd', 'segment_key');

        $ownByMarket = [];
        foreach ($ownSpend as $key => $usd) {
            $ownByMarket[strtoupper((string) $key)] = (float) $usd;
        }
        $ownTotal = array_sum($ownByMarket);

        // ── The join ──
        $rows = [];
        foreach ($byMarket as $market => $m) {
            $conceptCount = count($m['concepts']);
            $pageCount    = count($m['pages']);
            // Our own spend in that market. NULL (not 0) when we have no country
            // breakdown data at all — "we don't know" and "we spend nothing" differ.
            $own = $ownByMarket[$market] ?? ($ownByMarket === [] ? null : 0.0);
            $sharePct = ($own !== null && $ownTotal > 0.0) ? round($own / $ownTotal * 100, 1) : null;

            arsort($m['formats']);

            $rows[] = [
                'market'            => $market,
                // PROXY side — presence only. No spend exists for commercial EU ads.
                'competitorConcepts' => $conceptCount,
                'competitorPages'   => $pageCount,
                'competitorNames'   => array_values(array_slice($m['pages'], 0, 5)),
                'topFormats'        => array_keys($m['formats']),
                'proxyLabel'        => 'Proxy — public signals (presence and formats only; the EU Ad Library exposes no competitor spend)',
                // VERIFIED side — our own money.
                'ownSpendUsd'       => $own !== null ? round($own, 2) : null,
                'ownSharePct'       => $sharePct,
                'verifiedLabel'     => 'Verified — our data',
                'gap'               => $this->classify($own, $conceptCount),
            ];
        }

        // Biggest competitor presence where we're weakest, first.
        usort($rows, static function (array $a, array $b): int {
            $rank = ['absent' => 0, 'underweight' => 1, 'present' => 2, 'unknown' => 3];

            return [$rank[$a['gap']], -$a['competitorConcepts']] <=> [$rank[$b['gap']], -$b['competitorConcepts']];
        });

        return [
            'status'   => 'ok',
            'niche'    => $niche,
            'lookbackDays' => self::LOOKBACK_DAYS,
            'rows'     => $rows,
            // Said on every render. A gap is a question, not an answer.
            'note'     => 'Competitor activity is public Ad Library data: presence, concept counts and formats. It contains '
                . 'no spend and no performance — the EU Ad Library does not publish those for commercial ads, and Helm will '
                . 'not estimate them. A market where competitors are active is a QUESTION worth asking, not proof that it works: '
                . 'they chose to be there, which is not the same as it paying off.',
        ];
    }

    /**
     * Absent  — competitors are active there and we spend nothing.
     * Underweight — we're there, but with under 5% of our budget against a busy market.
     * Present — we're meaningfully in it.
     * Unknown — we have no country breakdown at all, so we cannot claim either way.
     */
    private function classify(?float $ownSpend, int $competitorConcepts): string
    {
        if ($ownSpend === null) {
            return 'unknown';   // no breakdown data → say so rather than imply absence
        }
        if ($ownSpend <= 0.0) {
            return 'absent';
        }
        if ($competitorConcepts >= 5 && $ownSpend > 0.0 && $ownSpend < 100.0) {
            return 'underweight';
        }

        return 'present';
    }
}
