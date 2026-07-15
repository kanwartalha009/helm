<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AdCreativeDaily;
use App\Models\Brand;
use App\Models\BrandStyle;
use App\Models\ProductCatalog;
use App\Services\Llm\LlmManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * GO-4.4 — assembles a brand's moodboard/style profile (master plan §7.4) and
 * owns the confirm gate.
 *
 * The parts, and where each honestly comes from:
 *   - winners: top creatives by USD ROAS with meaningful spend (ad_creative_daily)
 *     — Verified, the brand's OWN best-performing ads with their thumbnails.
 *   - palette: dominant colours binned (PaletteExtractor, pure-PHP GD) from those
 *     winner thumbnails — a SUGGESTION, fetched best-effort, [] when nothing is
 *     fetchable, never a fabricated colour.
 *   - toneWords: LLM prose over the brand's own product titles/types only
 *     (D-016, key-gated) — a SUGGESTION, [] when no LLM key. No customer data
 *     is ever sent (only public catalog copy).
 *   - doDont / refs: operator-authored.
 *
 * Nothing auto-confirms. `save(confirm:true)` requires an operator; `confirmed()`
 * is the ONE method GO-5 will call to decide whether it may generate against this
 * style — a draft returns null there.
 */
final class BrandStyleService
{
    /** USD spend floor before a creative counts as a "winner" worth referencing. */
    private const WINNER_MIN_SPEND_USD = 50.0;

    public function __construct(private readonly LlmManager $llm)
    {
    }

    /**
     * Current profile for the brand: the saved row rendered as an array, or a
     * fresh scaffold (status 'none') when nothing has been saved yet. Always
     * carries the live `winners` list so the moodboard can show them even before
     * a style is saved. No external calls here — fast and test-safe.
     *
     * @return array<string, mixed>
     */
    public function resolve(Brand $brand): array
    {
        $style   = BrandStyle::query()->where('brand_id', $brand->id)->first();
        $winners = $this->winners($brand);

        if ($style === null) {
            return [
                'status'     => 'none',
                'palette'    => [],
                'toneWords'  => [],
                'doDont'     => ['do' => [], 'dont' => []],
                'refs'       => [],
                'winners'    => $winners,
                'confirmedBy' => null,
                'confirmedAt' => null,
            ];
        }

        return [
            'status'      => $style->status,
            'palette'     => $style->palette ?? [],
            'toneWords'   => $style->tone_words ?? [],
            'doDont'      => $style->do_dont ?? ['do' => [], 'dont' => []],
            'refs'        => $style->refs ?? [],
            'winners'     => $winners,
            'confirmedBy' => $style->confirmed_by,
            'confirmedAt' => optional($style->confirmed_at)->toIso8601String(),
        ];
    }

    /**
     * Top creatives by USD ROAS with meaningful spend and a thumbnail — the
     * brand's own verified winners. Trailing 90 days.
     *
     * @return array<int, array{adId: string, adName: ?string, thumbnailUrl: string, roas: float, spend: float}>
     */
    public function winners(Brand $brand, int $limit = 8): array
    {
        $tz    = $brand->timezone ?: 'UTC';
        $end   = CarbonImmutable::now($tz)->toDateString();
        $start = CarbonImmutable::now($tz)->subDays(90)->toDateString();

        $rows = AdCreativeDaily::query()
            ->where('brand_id', $brand->id)
            ->whereBetween('date', [$start, $end])
            ->whereNotNull('thumbnail_url')
            ->where('thumbnail_url', '!=', '')
            ->groupBy('ad_id')
            ->selectRaw("ad_id,
                MAX(ad_name) AS ad_name,
                MAX(thumbnail_url) AS thumbnail_url,
                COALESCE(SUM(spend * COALESCE(fx_rate_to_usd, 1)), 0) AS spend_usd,
                COALESCE(SUM(spend), 0) AS spend_native,
                COALESCE(SUM(conversion_value * COALESCE(fx_rate_to_usd, 1)), 0) AS value_usd")
            ->get();

        $winners = [];
        foreach ($rows as $r) {
            $spendUsd = (float) $r->spend_usd;
            if ($spendUsd < self::WINNER_MIN_SPEND_USD) {
                continue; // below the floor — not a meaningful winner
            }
            $roas = $spendUsd > 0.0 ? round(((float) $r->value_usd) / $spendUsd, 2) : null;
            if ($roas === null) {
                continue;
            }
            $winners[] = [
                'adId'         => (string) $r->ad_id,
                'adName'       => $r->ad_name !== null ? (string) $r->ad_name : null,
                'thumbnailUrl' => (string) $r->thumbnail_url,
                'roas'         => $roas,
                'spend'        => round((float) $r->spend_native, 2),
            ];
        }

        usort($winners, static fn (array $a, array $b): int => $b['roas'] <=> $a['roas']);

        return array_slice($winners, 0, $limit);
    }

    /**
     * Best-effort palette suggestion: fetch up to N winner thumbnails and bin
     * their dominant colours. Bounded + fault-isolated — a slow/expired
     * thumbnail URL never breaks the request; it just contributes nothing.
     *
     * @return array<int, array{hex: string, weight: float}>
     */
    public function suggestPalette(Brand $brand, int $maxImages = 6): array
    {
        $winners = $this->winners($brand, $maxImages);
        if ($winners === []) {
            return [];
        }

        $bytes = [];
        foreach ($winners as $w) {
            try {
                $resp = Http::timeout(4)->get($w['thumbnailUrl']);
                if ($resp->successful()) {
                    $body = $resp->body();
                    if ($body !== '') {
                        $bytes[] = $body;
                    }
                }
            } catch (Throwable $e) {
                Log::info('brand_style.thumbnail_fetch_failed', ['ad_id' => $w['adId'], 'error' => $e->getMessage()]);
                // keep going — a missing thumbnail is not an error, just less input
            }
        }

        return (new PaletteExtractor())->fromImages($bytes);
    }

    /**
     * LLM-drafted tone words from the brand's OWN product copy (titles +
     * product types). Key-gated (D-016): returns [] when no LLM key is on file,
     * making NO call. Never sends customer data — only public catalog copy.
     *
     * @return array<int, string>
     */
    public function draftTone(Brand $brand): array
    {
        if (! $this->llm->enabled()) {
            return [];
        }

        $titles = ProductCatalog::query()
            ->where('brand_id', $brand->id)
            ->whereNotNull('title')
            ->orderByDesc('total_inventory')
            ->limit(30)
            ->pluck('product_type', 'title');

        if ($titles->isEmpty()) {
            return [];
        }

        $copy = collect($titles)
            ->map(static fn ($type, $title): string => trim($title . ($type ? " ({$type})" : '')))
            ->take(30)
            ->implode("\n");

        $system = 'You are a brand strategist. Given a store\'s product names, reply with 4-8 single-word or two-word'
            . ' tone-of-voice descriptors (e.g. "warm", "playful", "premium", "minimal"). Reply ONLY with a comma-separated'
            . ' list, no sentences, no numbering.';

        try {
            $raw = $this->llm->client()->complete($system, [
                ['role' => 'user', 'content' => "Product names:\n{$copy}"],
            ], 120);
        } catch (Throwable $e) {
            Log::info('brand_style.tone_draft_failed', ['brand_id' => $brand->id, 'error' => $e->getMessage()]);

            return [];
        }

        $words = array_values(array_filter(array_map(
            static fn (string $w): string => trim($w, " \t\n\r\0\x0B.-\"'"),
            preg_split('/[,\n]+/', $raw) ?: [],
        ), static fn (string $w): bool => $w !== '' && mb_strlen($w) <= 24));

        return array_slice(array_values(array_unique($words)), 0, 8);
    }

    /**
     * Save the brand's style. `confirm` flips it to the confirmed state (with
     * who + when) — the operator-review gate §7.4 requires; without it the row
     * stays a draft. Palette/tone/etc. are whatever the operator submitted
     * (they've reviewed the suggestions), stored verbatim.
     *
     * @param array{palette?: array, toneWords?: array, doDont?: array, refs?: array} $data
     */
    public function save(Brand $brand, array $data, ?int $userId, bool $confirm): BrandStyle
    {
        $style = BrandStyle::query()->firstOrNew(['brand_id' => $brand->id]);
        $style->brand_id     = $brand->id;
        $style->workspace_id = $brand->workspace_id ?? $style->workspace_id;
        $style->palette      = array_values($data['palette'] ?? ($style->palette ?? []));
        $style->tone_words   = array_values($data['toneWords'] ?? ($style->tone_words ?? []));
        $style->do_dont      = $data['doDont'] ?? ($style->do_dont ?? ['do' => [], 'dont' => []]);
        $style->refs         = array_values($data['refs'] ?? ($style->refs ?? []));

        if ($confirm) {
            $style->status       = 'confirmed';
            $style->confirmed_by = $userId;
            $style->confirmed_at = now();
        } elseif (! $style->exists) {
            $style->status = 'draft';
        }
        // An already-confirmed style that's re-saved WITHOUT confirm stays
        // confirmed — editing a bullet shouldn't silently un-approve the brand's
        // style. Re-confirmation is an explicit action.

        $style->save();

        return $style;
    }

    /**
     * The GO-5 GATE. Returns the confirmed style, or null when the brand has no
     * style or only a draft. GO-5 creative generation calls this and refuses to
     * proceed on null — "confirm this brand's style first".
     */
    public function confirmed(Brand $brand): ?BrandStyle
    {
        $style = BrandStyle::query()->where('brand_id', $brand->id)->first();

        return $style !== null && $style->isConfirmed() ? $style : null;
    }
}
