<?php

declare(strict_types=1);

namespace App\Services\Rules;

use App\Models\AdCreativeDaily;
use App\Models\Brand;
use Carbon\CarbonImmutable;

/**
 * Seasonal-stale creative detector (GO-3.1, master plan §6.1) — the flagship rule.
 *
 * Finds ads that are STILL SPENDING MONEY on copy for a season that is over: Christmas
 * creative live in February, "soldes d'hiver" running in April, Black Friday hooks in
 * January. This is money burning on a hook the customer has already stopped believing.
 *
 * ══ THE TRIGGER IS A RULE. NEVER AN LLM. ══ (D-016; master plan §6.1)
 * A recommendation fires on exactly two facts, both checkable by hand:
 *      (1) the ad's text matches a season's keyword list, AND
 *      (2) today is later than that season's end + grace.
 * There is no model in this file, and there must never be one. An LLM may later enrich
 * the PROSE of an explanation — it can never be the reason an alert exists. A system
 * that can invent a reason to spend a client's attention will eventually invent one.
 *
 * Missing ≠ zero: an ad with no spend in the live window is not "fixed", it is simply
 * not live, and it is not flagged. There is nothing to save on an ad that isn't running.
 */
class SeasonalStale
{
    /**
     * @return array<int, array<string, mixed>> one row per stale live ad
     */
    public function forBrand(Brand $brand, ?CarbonImmutable $asOf = null): array
    {
        $tz    = $brand->timezone ?: 'UTC';
        $today = ($asOf ?? CarbonImmutable::now($tz))->startOfDay();

        $grace      = (int) config('seasons.grace_days', 7);
        $liveWindow = (int) config('seasons.live_window_days', 7);
        $seasons    = (array) config('seasons.seasons', []);

        // LIVE ads only: something that actually spent in the last 7 days. An ad with no
        // spend costs nothing and needs no advice.
        $since = $today->subDays($liveWindow - 1)->toDateString();

        $ads = AdCreativeDaily::query()
            ->where('brand_id', $brand->id)
            ->whereBetween('date', [$since, $today->toDateString()])
            ->groupBy('ad_id')
            ->selectRaw('ad_id,
                MAX(ad_name) AS ad_name,
                MAX(body_text) AS body_text,
                MAX(platform) AS platform,
                COALESCE(SUM(spend), 0) AS spend,
                COALESCE(SUM(spend * COALESCE(fx_rate_to_usd, 1)), 0) AS spend_usd')
            ->havingRaw('COALESCE(SUM(spend), 0) > 0')
            ->get();

        $out = [];

        foreach ($ads as $ad) {
            $haystack = $this->normalize(((string) $ad->ad_name) . ' ' . ((string) $ad->body_text));
            if (trim($haystack) === '') {
                continue; // no text to judge — say nothing rather than guess
            }

            foreach ($seasons as $key => $season) {
                $matched = $this->matchedTerms($haystack, (array) ($season['keywords'] ?? []));
                if ($matched === []) {
                    continue;
                }

                $endedOn = $this->mostRecentEnd($today, (string) $season['starts'], (string) $season['ends']);
                $staleFrom = $endedOn->addDays($grace);

                // In-season, or still inside the grace period → NOT stale. Winding a
                // campaign down over a few days is normal behaviour, not a mistake.
                if ($today->lessThanOrEqualTo($staleFrom)) {
                    continue;
                }

                $out[] = [
                    'adId'         => (string) $ad->ad_id,
                    'adName'       => (string) ($ad->ad_name ?? ''),
                    'platform'     => (string) ($ad->platform ?? ''),
                    'season'       => (string) $key,
                    'seasonLabel'  => (string) ($season['label'] ?? $key),
                    'matchedTerms' => $matched,          // exactly what fired it
                    'seasonEnded'  => $endedOn->toDateString(),
                    'staleSince'   => $staleFrom->toDateString(),
                    'daysStale'    => (int) $staleFrom->diffInDays($today),
                    'spend'        => round((float) $ad->spend, 2),
                    'spendUsd'     => round((float) $ad->spend_usd, 2),
                    'liveWindowDays' => $liveWindow,
                ];

                break; // one season per ad — the first (most specific) match is enough
            }
        }

        // Loudest first: the most money burning on the deadest hook.
        usort($out, static fn (array $a, array $b): int => $b['spendUsd'] <=> $a['spendUsd']);

        return $out;
    }

    /**
     * Which keyword terms actually appear. Returned in the evidence so the operator can
     * see precisely why the ad was flagged — "matched: navidad, reyes magos" is a claim
     * a human can check in two seconds. A score they cannot check is one they will not trust.
     *
     * @param array<string, array<int, string>> $keywords lang => terms
     * @return array<int, string>
     */
    private function matchedTerms(string $haystack, array $keywords): array
    {
        $hits = [];
        foreach ($keywords as $terms) {
            foreach ((array) $terms as $term) {
                $needle = $this->normalize((string) $term);
                if ($needle !== '' && str_contains($haystack, $needle)) {
                    $hits[] = (string) $term;
                }
            }
        }

        return array_values(array_unique($hits));
    }

    /**
     * The most recent date this season ENDED, relative to today. Handles seasons that
     * wrap the new year (Christmas: Nov 15 → Jan 6) — the end that matters is the one
     * that has already happened, not next year's.
     */
    private function mostRecentEnd(CarbonImmutable $today, string $starts, string $ends): CarbonImmutable
    {
        [$em, $ed] = array_map('intval', explode('-', $ends));

        $thisYear = $today->setDate($today->year, $em, $ed)->startOfDay();

        // If this year's end date hasn't arrived yet, the last one that DID happen was
        // a year ago. (A February check against a Christmas season ending Jan 6 uses
        // THIS year's Jan 6 — which is correct, and is exactly the flagship case.)
        return $thisYear->greaterThan($today) ? $thisYear->subYear() : $thisYear;
    }

    /** Lower-cased, accent-stripped, whitespace-collapsed — so "Noël" matches "noel". */
    private function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s));

        $from = ['á','à','â','ä','ã','é','è','ê','ë','í','ì','î','ï','ó','ò','ô','ö','õ','ú','ù','û','ü','ñ','ç'];
        $to   = ['a','a','a','a','a','e','e','e','e','i','i','i','i','o','o','o','o','o','u','u','u','u','n','c'];
        $s = str_replace($from, $to, $s);

        return (string) preg_replace('/\s+/', ' ', $s);
    }
}
