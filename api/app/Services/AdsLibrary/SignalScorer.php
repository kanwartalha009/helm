<?php

declare(strict_types=1);

namespace App\Services\AdsLibrary;

use App\Models\AdLibraryAd;
use Carbon\CarbonImmutable;

/**
 * Materialises the disclosed "Helm Signal Score" for market ads (Ads Library
 * Phase 2.6). Deterministic, no LLM. The score is a SORT KEY, never presented as
 * performance (commercial EU ads expose no spend/impressions).
 *
 *   signal = w_long·longevity_pctl + w_reach·reach_pctl + w_var·variants_pctl
 *
 * Percentiles are computed WITHIN a niche corpus (last-`corpus_window_days`) so
 * scores compare like with like; weights come from config/adslibrary.php and are
 * shown verbatim in the UI tooltip. Longevity punishes new ads — the "Rising"
 * sort (reach ÷ longevity) surfaces them separately at read time.
 *
 * Product lens (D-022): this ranks the SHARED public corpus; it never blends one
 * tenant's first-party outcomes into another tenant's view (that's the winners
 * library, which stays per-tenant).
 */
class SignalScorer
{
    public function materialize(?CarbonImmutable $today = null): int
    {
        $today = $today ?? CarbonImmutable::now();
        $cfg   = (array) config('adslibrary.score', []);
        $wLong = (float) ($cfg['longevity_weight'] ?? 0.45);
        $wReach = (float) ($cfg['reach_weight'] ?? 0.30);
        $wVar  = (float) ($cfg['variants_weight'] ?? 0.25);
        $windowDays = (int) config('adslibrary.corpus_window_days', 90);
        $floor = $today->subDays($windowDays)->toDateString();

        // Corpus = ads active OR delivered within the window. Grouped by niche so
        // percentiles compare within a niche.
        $ads = AdLibraryAd::query()
            ->where(fn ($q) => $q->where('is_active', true)->orWhere('delivery_start', '>=', $floor))
            ->get(['id', 'niche', 'concept_hash', 'delivery_start', 'delivery_stop', 'eu_total_reach', 'is_active']);

        $byNiche = [];
        foreach ($ads as $ad) {
            $byNiche[(string) ($ad->niche ?? '__none')][] = $ad;
        }

        $updated = 0;
        foreach ($byNiche as $group) {
            // Live-variant count per concept (a real testing-velocity signal).
            $conceptCount = [];
            foreach ($group as $ad) {
                if ($ad->is_active) {
                    $conceptCount[(string) $ad->concept_hash] = ($conceptCount[(string) $ad->concept_hash] ?? 0) + 1;
                }
            }

            $long = $reach = $vars = [];
            foreach ($group as $ad) {
                $days = $this->longevityDays($ad->delivery_start, $ad->delivery_stop, $today);
                $long[$ad->id]  = $days;
                $reach[$ad->id] = (int) ($ad->eu_total_reach ?? 0);
                $vars[$ad->id]  = $conceptCount[(string) $ad->concept_hash] ?? 0;
            }
            $pLong  = $this->percentiles($long);
            $pReach = $this->percentiles($reach);
            $pVar   = $this->percentiles($vars);

            foreach ($group as $ad) {
                $signal = $wLong * $pLong[$ad->id] + $wReach * $pReach[$ad->id] + $wVar * $pVar[$ad->id];
                AdLibraryAd::query()->whereKey($ad->id)->update([
                    'longevity_days' => $long[$ad->id],
                    'signal_score'   => round($signal, 4),
                ]);
                $updated++;
            }
        }

        return $updated;
    }

    public function longevityDays(mixed $start, mixed $stop, CarbonImmutable $today): int
    {
        if (! $start) {
            return 0;
        }
        $from = CarbonImmutable::parse((string) $start);
        $to   = $stop ? CarbonImmutable::parse((string) $stop) : $today;

        return max(0, (int) $from->diffInDays($to));
    }

    /**
     * Fraction-below percentile in [0,1] for each key — monotonic, ties share the
     * same value. A singleton corpus scores 0 (nothing to compare against).
     *
     * @param array<int|string, int|float> $values
     * @return array<int|string, float>
     */
    private function percentiles(array $values): array
    {
        $n = count($values);
        if ($n <= 1) {
            return array_map(static fn () => 0.0, $values);
        }
        $sorted = array_values($values);
        sort($sorted);

        $out = [];
        foreach ($values as $k => $v) {
            $below = 0;
            foreach ($sorted as $s) {
                if ($s < $v) {
                    $below++;
                }
            }
            $out[$k] = round($below / ($n - 1), 6);
        }

        return $out;
    }
}
