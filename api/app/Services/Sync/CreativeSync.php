<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Models\AdCreativeDaily;
use App\Models\PlatformConnection;
use App\Platforms\Meta\MetaCreativeFetcher;
use App\Platforms\TikTok\CreativeFetcher as TikTokCreativeFetcher;
use App\Services\Currency\FxService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Upserts ad-level (creative) daily rows into ad_creative_daily. ONE entry point used by BOTH the
 * daily sync (from = to = the day) and the ranged backfill commands — the AdSetSync pattern.
 *
 * ══ WHY THIS EXISTS ══
 * Creatives were the ONLY dataset not in SyncBrandDayJob (`grep -c Creative` on that file returned
 * 0). They were written exclusively by `meta:backfill-creatives` / `tiktok:backfill-creatives`, so
 * the Creatives tab silently went stale the moment a backfill finished — and worse,
 * `ad_creative_daily.thumbnail_url` is a SHORT-LIVED Meta CDN link, so the previews rot too. The
 * table's own migration comment says "expires — refreshed each sync", which simply wasn't true.
 *
 * Putting the write path in a service means the daily sync, the manual "Sync now" buttons (both
 * fan out SyncBrandDayJob) and the backfill commands all write through the same code. Two copies
 * of an upsert is two chances to drift.
 *
 * Best-effort by contract: a platform hiccup logs and returns 0, never failing the day's main sync.
 */
class CreativeSync
{
    public function __construct(
        private readonly MetaCreativeFetcher $meta,
        private readonly TikTokCreativeFetcher $tiktok,
        private readonly FxService $fx,
    ) {}

    /** Creatives are platform-specific: only Meta and TikTok have an ad-creative grain. */
    private const PLATFORMS = ['meta', 'tiktok'];

    /**
     * @return int rows written
     */
    public function syncRange(PlatformConnection $conn, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $platform = $conn->platform;
        if (! in_array($platform, self::PLATFORMS, true)) {
            return 0;
        }

        try {
            $fetched = $platform === 'meta'
                ? $this->meta->fetchCreativeRange($conn, $from, $to)
                : $this->tiktok->fetchCreativeRange($conn, $from, $to);
        } catch (Throwable $e) {
            Log::warning('sync.creatives.failed', [
                'brand_id' => $conn->brand_id,
                'platform' => $platform,
                'from'     => $from->toDateString(),
                'to'       => $to->toDateString(),
                'error'    => $e->getMessage(),
            ]);

            return 0;
        }

        if ($fetched === []) {
            return 0;
        }

        $brand    = $conn->brand;
        $currency = (string) ($brand->base_currency ?: 'USD');

        /** @var array<string, float|null> $fxCache */
        $fxCache = [];
        $records = [];

        foreach ($fetched as $r) {
            $date = (string) ($r['date'] ?? '');
            $adId = (string) ($r['ad_id'] ?? '');
            if ($date === '' || $adId === '') {
                continue;
            }

            // Native money + the day's fx snapshot (spec rule 7), stamped per ROW date because a
            // ranged pull spans days. Cached per (currency, date).
            $rowCcy = strtoupper((string) ($r['currency'] ?? $currency));
            $fxKey  = "{$rowCcy}|{$date}";
            $fxRate = $fxCache[$fxKey] ??= $this->fx->cachedToUsd($rowCcy, CarbonImmutable::parse($date));

            $records[] = [
                'brand_id'           => (int) $conn->brand_id,
                'platform'           => $platform,
                'date'               => $date,
                'ad_id'              => mb_substr($adId, 0, 64),
                'ad_name'            => mb_substr((string) ($r['ad_name'] ?? ''), 0, 255),
                'body_text'          => isset($r['body_text']) && $r['body_text'] !== null
                    ? mb_substr((string) $r['body_text'], 0, 2000) : null,
                'campaign_id'        => mb_substr((string) ($r['campaign_id'] ?? ''), 0, 64) ?: null,
                'thumbnail_url'      => $r['thumbnail_url'] ?? null,
                'media_type'         => in_array($r['media_type'] ?? 'image', ['image', 'video'], true)
                    ? $r['media_type'] : 'image',
                'spend'              => (float) ($r['spend'] ?? 0),
                'impressions'        => (int) ($r['impressions'] ?? 0),
                'clicks'             => (int) ($r['clicks'] ?? 0),
                'video_3s'           => (int) ($r['video_3s'] ?? 0),
                'thruplays'          => (int) ($r['thruplays'] ?? 0),
                'add_to_cart'        => (int) ($r['add_to_cart'] ?? 0),
                // Meta-only relevance rankings; TikTok returns none, and null must STAY null —
                // the UI's warning badge keys off the 'below' prefix, so an unranked ad is not
                // a badly-ranked one.
                'quality_ranking'    => $r['quality_ranking'] ?? null,
                'engagement_ranking' => $r['engagement_ranking'] ?? null,
                'conversion_ranking' => $r['conversion_ranking'] ?? null,
                'conversions'        => (int) ($r['conversions'] ?? 0),
                'conversion_value'   => (float) ($r['conversion_value'] ?? 0),
                'currency'           => $rowCcy,
                'fx_rate_to_usd'     => $fxRate,
                'is_complete'        => true,
                'pulled_at'          => now(),
            ];
        }

        if ($records === []) {
            return 0;
        }

        // thumbnail_url IS in the update set on purpose: Meta's CDN links expire, so every sync
        // must refresh them or the previews go blank on ads that are still running.
        foreach (array_chunk($records, 500) as $chunk) {
            AdCreativeDaily::upsert(
                $chunk,
                ['brand_id', 'platform', 'date', 'ad_id'],
                [
                    'ad_name', 'body_text', 'campaign_id', 'thumbnail_url', 'media_type',
                    'spend', 'impressions', 'clicks', 'video_3s', 'thruplays', 'add_to_cart',
                    'quality_ranking', 'engagement_ranking', 'conversion_ranking',
                    'conversions', 'conversion_value', 'currency', 'fx_rate_to_usd',
                    'is_complete', 'pulled_at',
                ],
            );
        }

        return count($records);
    }
}
