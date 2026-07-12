<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdBoard;
use App\Models\AdBoardItem;
use App\Models\AdBrief;
use App\Models\AdCreativeDaily;
use App\Models\AdLibraryAd;
use App\Models\Brand;
use App\Models\ProductCatalog;
use App\Services\AdsLibrary\TagBenchmarks;
use App\Services\Llm\LlmManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Boards + briefs (Ads Library Phase 4). Save internal winners or market ads to a
 * board, tag them, and assemble a Verified-data-fed creative brief. Boarded
 * INTERNAL ads persist a local thumbnail (CDN urls expire); market ads stay text +
 * permalink (no media stored, per ToS). Benchmarks + brief hooks read the OWN
 * account only (D-022: no cross-tenant pooling).
 */
class AdBoardsController extends Controller
{
    public function index(): JsonResponse
    {
        $boards = AdBoard::query()->withCount('items')->orderByDesc('id')->get()->map(fn (AdBoard $b) => [
            'id' => $b->id, 'name' => $b->name, 'brandId' => $b->brand_id, 'niche' => $b->niche, 'itemCount' => $b->items_count,
        ]);

        return response()->json(['boards' => $boards]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:160'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'niche'    => ['nullable', 'string', 'max:48'],
        ]);
        $board = AdBoard::create([
            'name' => $data['name'], 'brand_id' => $data['brand_id'] ?? null, 'niche' => $data['niche'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['id' => $board->id], 201);
    }

    public function show(AdBoard $board, TagBenchmarks $benchmarks): JsonResponse
    {
        $items = $board->items()->get();
        $internalIds = $items->where('source', 'internal')->pluck('ref_id')->all();
        $marketIds   = $items->where('source', 'market')->pluck('ref_id')->all();

        $creatives = AdCreativeDaily::query()->whereIn('ad_id', $internalIds)
            ->orderByDesc('date')->get()->groupBy('ad_id')->map->first();
        $ads = AdLibraryAd::query()->whereIn('ad_archive_id', $marketIds)->get()->keyBy('ad_archive_id');

        $rows = $items->map(function (AdBoardItem $it) use ($creatives, $ads) {
            $base = ['id' => $it->id, 'source' => $it->source, 'refId' => $it->ref_id, 'note' => $it->note, 'tags' => (array) ($it->tags ?? [])];
            if ($it->source === 'internal') {
                $c = $creatives[$it->ref_id] ?? null;
                return $base + [
                    'name'      => $c->ad_name ?? $it->ref_id,
                    'thumbnail' => $it->thumb_path ? Storage::disk('public')->url($it->thumb_path) : ($c->thumbnail_url ?? null),
                    'mediaType' => $c->media_type ?? null,
                    'bodyText'  => $c->body_text ?? null,
                    'badge'     => 'Verified',
                ];
            }
            $a = $ads[$it->ref_id] ?? null;
            return $base + [
                'name'      => $a->page_name ?? $it->ref_id,
                'permalink' => $a->permalink ?? null,
                'bodyText'  => $a && is_array($a->creative_bodies) ? ($a->creative_bodies[0] ?? null) : null,
                'badge'     => 'Proxy',
            ];
        })->values();

        return response()->json([
            'board' => ['id' => $board->id, 'name' => $board->name, 'brandId' => $board->brand_id, 'niche' => $board->niche],
            'items' => $rows,
            'benchmarks' => $benchmarks->forAdIds($this->tagToInternalAdIds($items)),
        ]);
    }

    public function addItem(Request $request, AdBoard $board): JsonResponse
    {
        $data = $request->validate([
            'source' => ['required', 'in:internal,market'],
            'ref_id' => ['required', 'string', 'max:64'],
            'note'   => ['nullable', 'string', 'max:2000'],
            'tags'   => ['nullable', 'array'],
            'tags.*' => ['string', 'max:60'],
        ]);

        $thumbPath = $data['source'] === 'internal' ? $this->persistThumb($data['ref_id']) : null;

        $item = AdBoardItem::updateOrCreate(
            ['board_id' => $board->id, 'source' => $data['source'], 'ref_id' => $data['ref_id']],
            [
                'note'     => $data['note'] ?? null,
                'tags'     => $data['tags'] ?? [],
                'thumb_path' => $thumbPath,
                'position' => (int) $board->items()->max('position') + 1,
                'added_by' => $request->user()->id,
            ],
        );

        return response()->json(['id' => $item->id], 201);
    }

    public function updateItem(Request $request, AdBoard $board, AdBoardItem $item): JsonResponse
    {
        abort_unless($item->board_id === $board->id, 404);
        $data = $request->validate([
            'note'   => ['sometimes', 'nullable', 'string', 'max:2000'],
            'tags'   => ['sometimes', 'array'],
            'tags.*' => ['string', 'max:60'],
        ]);
        $item->update($data);

        return response()->json(['ok' => true]);
    }

    public function removeItem(AdBoard $board, AdBoardItem $item): JsonResponse
    {
        abort_unless($item->board_id === $board->id, 404);
        $item->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Optional LLM tag suggestion for one board item (D-016 privacy boundary).
     * Feeds the model TEXT ONLY — market ads use their public creative text; our
     * own internal ads pass the ad NAME only, never metrics or spend. The model is
     * constrained to the config taxonomy and its output is intersected back to it,
     * so it can only ever return known tags. Suggestions are ALWAYS operator-
     * confirmed in the UI — this endpoint writes nothing. Returns enabled:false
     * (never an error) when no LLM key is on file, so the button degrades quietly.
     */
    public function suggestTags(AdBoard $board, AdBoardItem $item, LlmManager $llm): JsonResponse
    {
        abort_unless($item->board_id === $board->id, 404);

        /** @var array<int, string> $taxonomy */
        $taxonomy = array_values(array_unique(array_merge(...array_values((array) config('adslibrary.tags', [])))));

        if (! $llm->enabled()) {
            return response()->json([
                'enabled'   => false,
                'suggested' => [],
                'taxonomy'  => $taxonomy,
                'note'      => 'Add an LLM key at Settings → Platform keys → AI / LLM to get tag suggestions. You can still tag by hand.',
            ]);
        }

        // Gather TEXT ONLY, respecting the privacy boundary.
        $text = '';
        if ($item->source === 'market') {
            $a = AdLibraryAd::query()->where('ad_archive_id', $item->ref_id)->first();
            $bodies = is_array($a?->creative_bodies) ? $a->creative_bodies : [];
            $titles = is_array($a?->link_titles) ? $a->link_titles : [];
            $text   = trim(implode("\n", array_slice([...$titles, ...$bodies], 0, 6)));
        } else {
            // Internal ad: NAME only — never metrics (D-016).
            $c    = AdCreativeDaily::query()->where('ad_id', $item->ref_id)->orderByDesc('date')->first();
            $text = trim((string) ($c->ad_name ?? ''));
        }

        if ($text === '') {
            return response()->json(['enabled' => true, 'suggested' => [], 'taxonomy' => $taxonomy, 'note' => 'No creative text on file for this ad — tag it by hand.']);
        }

        try {
            $raw = $llm->client()->complete(
                'You label advertising creatives with tags. Choose ONLY from this exact list of allowed tags: '
                . implode(', ', $taxonomy)
                . '. Return a JSON array of the tags that clearly apply (0 to 5 of them). Output the JSON array and nothing else.',
                [['role' => 'user', 'content' => "Ad text:\n" . mb_substr($text, 0, 1200)]],
                maxTokens: 120,
            );
            $suggested = $this->parseTagArray($raw, $taxonomy);
        } catch (Throwable) {
            return response()->json(['enabled' => true, 'suggested' => [], 'taxonomy' => $taxonomy, 'note' => 'The LLM call did not complete — try again or tag by hand.']);
        }

        return response()->json(['enabled' => true, 'suggested' => $suggested, 'taxonomy' => $taxonomy]);
    }

    /**
     * Parse the model's reply into a clean tag list: decode the JSON array,
     * lowercase-match every element against the taxonomy (so the model can NEVER
     * invent a tag), de-dupe, cap at 5.
     *
     * @param array<int, string> $taxonomy
     * @return array<int, string>
     */
    private function parseTagArray(string $raw, array $taxonomy): array
    {
        $start = strpos($raw, '[');
        $end   = strrpos($raw, ']');
        if ($start === false || $end === false || $end < $start) {
            return [];
        }
        $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);
        if (! is_array($decoded)) {
            return [];
        }

        $allowed = [];
        foreach ($taxonomy as $t) {
            $allowed[strtolower($t)] = $t;
        }

        $out = [];
        foreach ($decoded as $el) {
            $key = strtolower(trim((string) $el));
            if (isset($allowed[$key]) && ! in_array($allowed[$key], $out, true)) {
                $out[] = $allowed[$key];
            }
        }

        return array_slice($out, 0, 5);
    }

    /** POST assemble a brief from the board — editable blocks fed by verified data. */
    public function createBrief(Request $request, AdBoard $board, TagBenchmarks $benchmarks): JsonResponse
    {
        $data = $request->validate([
            'title'      => ['nullable', 'string', 'max:200'],
            'product'    => ['nullable', 'string', 'max:191'], // product handle for the facts block
        ]);
        $items = $board->items()->get();
        $brand = $board->brand_id ? Brand::find($board->brand_id) : null;

        $productFacts = null;
        if ($brand && ! empty($data['product'])) {
            $pc = ProductCatalog::query()->where('brand_id', $brand->id)->where('handle', $data['product'])->first();
            if ($pc) {
                $productFacts = [
                    'title' => $pc->title, 'handle' => $pc->handle, 'inventory' => $pc->total_inventory,
                    'lowStock' => (int) $pc->total_inventory > 0 && (int) $pc->total_inventory < 50,
                ];
            }
        }

        $brief = AdBrief::create([
            'board_id' => $board->id,
            'brand_id' => $board->brand_id,
            'title'    => $data['title'] ?? ($board->name . ' — brief'),
            'status'   => 'draft',
            'created_by' => $request->user()->id,
            'blocks'   => [
                'objective'    => '',
                'audience'     => $brand ? ('Prefill from ' . $brand->name . ' — refine with the market targeting on the board.') : '',
                'referenceAds' => $items->map(fn (AdBoardItem $i) => ['source' => $i->source, 'refId' => $i->ref_id, 'tags' => (array) ($i->tags ?? [])])->values()->all(),
                'provenHooks'  => $benchmarks->forAdIds($this->tagToInternalAdIds($items)),
                'productFacts' => $productFacts,
                'deliverables' => [],
                'notes'        => '',
            ],
        ]);

        return response()->json(['id' => $brief->id], 201);
    }

    public function showBrief(AdBrief $brief): JsonResponse
    {
        return response()->json([
            'id' => $brief->id, 'boardId' => $brief->board_id, 'brandId' => $brief->brand_id,
            'title' => $brief->title, 'status' => $brief->status, 'blocks' => $brief->blocks,
        ]);
    }

    public function updateBrief(Request $request, AdBrief $brief): JsonResponse
    {
        $data = $request->validate([
            'title'  => ['sometimes', 'string', 'max:200'],
            'status' => ['sometimes', 'in:draft,ready,shipped'],
            'blocks' => ['sometimes', 'array'],
        ]);
        $brief->update($data);

        return response()->json(['ok' => true]);
    }

    /** @param \Illuminate\Support\Collection<int, AdBoardItem> $items @return array<string, list<string>> */
    private function tagToInternalAdIds($items): array
    {
        $map = [];
        foreach ($items->where('source', 'internal') as $it) {
            foreach ((array) ($it->tags ?? []) as $tag) {
                $map[(string) $tag][] = (string) $it->ref_id;
            }
        }

        return $map;
    }

    /**
     * Download an internal ad's current CDN thumbnail to the public disk (CDN urls
     * expire → a board of last quarter's winners would rot). Best-effort; returns
     * the stored path or null. This is the agency's OWN media — no Ad Library ToS.
     */
    private function persistThumb(string $adId): ?string
    {
        $c = AdCreativeDaily::query()->where('ad_id', $adId)->orderByDesc('date')->first();
        $url = $c?->thumbnail_url;
        if (! $url) {
            return null;
        }
        try {
            $res = Http::timeout(15)->get($url);
            if (! $res->successful()) {
                return null;
            }
            $path = 'adlib-thumbs/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $adId) . '.jpg';
            Storage::disk('public')->put($path, $res->body());

            return $path;
        } catch (Throwable) {
            return null;
        }
    }
}
