<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AdCreativeDaily;
use App\Models\Brand;
use App\Platforms\Meta\MetaClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Why are Meta creatives BLURRY, and why won't the video PLAY?
 *
 * Both symptoms come from the same place — what Meta actually returns for a creative — and
 * neither can be fixed by guessing. This prints the RAW truth for the top-spend ads:
 *
 *  - which image field is populated (image_url / video poster / feed image / thumbnail_url /
 *    link picture), and **the real pixel size of the image we ended up using**. Blurry means we
 *    are upscaling Meta's ~64px `thumbnail_url` into a ~250px card, so the size is the answer.
 *  - whether a `video_id` resolves at all, and what `GET /{video_id}` gives back for
 *    `source` (playback), `picture` and `thumbnails` (a proper high-res poster).
 *
 * `source` is the field the player needs. Meta only returns it to accounts that own the video;
 * a 200 response with NO `source` key is the normal, silent failure — no exception is thrown,
 * so nothing is logged, and the modal just says "Video preview unavailable". This command makes
 * that visible.
 *
 *   php artisan meta:diagnose-creatives nude-project
 *   php artisan meta:diagnose-creatives nude-project --show=5 --days=30
 */
class MetaDiagnoseCreativesCommand extends Command
{
    protected $signature = 'meta:diagnose-creatives '
        . '{brand : slug or id} '
        . '{--days=30 : window to pick the top-spend ads from} '
        . '{--show=5 : how many top-spend ads to probe}';

    protected $description = 'Print what Meta really returns for creative images + video sources (diagnoses blurry thumbnails and dead video previews).';

    public function handle(MetaClient $client): int
    {
        $brand = $this->resolveBrand();
        if (! $brand) {
            $this->error('Brand not found.');

            return self::FAILURE;
        }

        $conn = $brand->connections()->where('platform', 'meta')->where('status', 'active')->first();
        if (! $conn) {
            $this->error("{$brand->name} has no active Meta connection.");

            return self::FAILURE;
        }

        $days = max(1, (int) $this->option('days'));
        $show = max(1, (int) $this->option('show'));

        // Top spenders from what the Creatives grid itself reads, so we probe the exact ads
        // Kanwar is looking at — not a different sample that might behave differently.
        $ads = AdCreativeDaily::query()
            ->where('brand_id', $brand->id)
            ->where('platform', 'meta')
            ->where('date', '>=', now()->subDays($days)->toDateString())
            ->selectRaw('ad_id, MAX(ad_name) AS ad_name, MAX(media_type) AS media_type,
                         MAX(thumbnail_url) AS thumbnail_url, COALESCE(SUM(spend), 0) AS spend')
            ->groupBy('ad_id')
            ->orderByDesc('spend')
            ->limit($show)
            ->get();

        if ($ads->isEmpty()) {
            $this->warn("No Meta creative rows in the last {$days} days. Run: php artisan meta:backfill-creatives {$brand->slug}");

            return self::SUCCESS;
        }

        $this->line("Probing {$ads->count()} top-spend Meta ad(s) for {$brand->name}.");
        $this->newLine();

        foreach ($ads as $ad) {
            $this->line('──────────────────────────────────────────────');
            $this->line("ad_id {$ad->ad_id}  ·  " . mb_substr((string) $ad->ad_name, 0, 48));
            $this->line('  stored media_type : ' . ($ad->media_type ?: 'null'));
            $this->line('  stored thumbnail  : ' . ($ad->thumbnail_url ? mb_substr((string) $ad->thumbnail_url, 0, 90) : 'NULL — this is why the card says "No preview"'));

            // What size is the image we are ACTUALLY rendering? This is the blur answer.
            if ($ad->thumbnail_url) {
                $this->reportImageSize((string) $ad->thumbnail_url);
            }

            $this->probeCreative($client, (string) $ad->ad_id);
            $this->newLine();
        }

        $this->line('──────────────────────────────────────────────');
        $this->info('Paste this whole block back. It says exactly which image field to prefer');
        $this->info('and whether `source` is obtainable for playback on this account.');

        return self::SUCCESS;
    }

    /** HEAD/GET the image we render and report its real dimensions. Small = blurry, definitively. */
    private function reportImageSize(string $url): void
    {
        try {
            $bytes = @file_get_contents($url, false, stream_context_create([
                'http' => ['timeout' => 10, 'header' => "User-Agent: Helm-diagnose\r\n"],
            ]));
            if ($bytes === false) {
                $this->line('  rendered size     : could not fetch the image');

                return;
            }
            $info = @getimagesizefromstring($bytes);
            if ($info === false) {
                $this->line('  rendered size     : not a readable image');

                return;
            }
            $w = (int) $info[0];
            $h = (int) $info[1];
            // The card is ~250px wide at 4:5. Anything under ~500px on the long edge is
            // being upscaled and WILL look soft on a retina screen.
            $verdict = max($w, $h) < 500
                ? "  ← TOO SMALL. Upscaled into a ~250px card ⇒ this is the blur."
                : '  ← fine for the card';
            $this->line("  rendered size     : {$w}×{$h}px{$verdict}");
        } catch (Throwable $e) {
            $this->line('  rendered size     : error — ' . $e->getMessage());
        }
    }

    private function probeCreative(MetaClient $client, string $adId): void
    {
        try {
            $ad = $client->get($adId, [
                'fields' => 'creative{id,image_url,thumbnail_url,video_id,'
                    . 'object_story_spec{video_data{video_id,image_url},link_data{picture}},'
                    . 'asset_feed_spec{videos{video_id,thumbnail_url},images{url}}}',
            ]);
        } catch (Throwable $e) {
            $this->line('  /ad?fields=creative: FAILED — ' . $e->getMessage());

            return;
        }

        $cr = (array) ($ad['creative'] ?? []);
        if ($cr === []) {
            $this->line('  creative          : EMPTY (dark post, or the token cannot see this creative)');

            return;
        }

        $oss  = (array) ($cr['object_story_spec'] ?? []);
        $feed = (array) ($cr['asset_feed_spec'] ?? []);

        // Which image candidates exist, in the fetcher's own priority order.
        $this->line('  image candidates  :');
        foreach ([
            'creative.image_url'                 => $cr['image_url'] ?? null,
            'object_story_spec.video_data.image_url' => $oss['video_data']['image_url'] ?? null,
            'asset_feed_spec.images[0].url'      => $feed['images'][0]['url'] ?? null,
            'creative.thumbnail_url'             => $cr['thumbnail_url'] ?? null,
            'asset_feed_spec.videos[0].thumbnail_url' => $feed['videos'][0]['thumbnail_url'] ?? null,
            'object_story_spec.link_data.picture' => $oss['link_data']['picture'] ?? null,
        ] as $label => $val) {
            $this->line(sprintf('    %-42s %s', $label, is_string($val) && $val !== '' ? 'present' : '—'));
        }

        $vid = $oss['video_data']['video_id']
            ?? $cr['video_id']
            ?? ($feed['videos'][0]['video_id'] ?? null);

        if (! $vid) {
            $this->line('  video_id          : NONE — this ad is an image ad, so no video is expected.');

            return;
        }

        $this->line("  video_id          : {$vid}");

        // THE question for playback. `source` is what the modal player needs.
        try {
            $v = $client->get((string) $vid, ['fields' => 'source,picture,thumbnails{uri,width,height,is_preferred},permalink_url']);
        } catch (Throwable $e) {
            $this->line('  /video            : FAILED — ' . $e->getMessage());
            $this->line('                      (a permissions error here is the reason playback is dead)');

            return;
        }

        $src = $v['source'] ?? null;
        $this->line('  video.source      : ' . (is_string($src) && $src !== ''
            ? 'PRESENT ⇒ playback should work; if the modal still fails the bug is client-side'
            : 'ABSENT ⇒ THIS is why "Video preview unavailable". Meta returned 200 with no `source` key, so nothing was logged.'));

        $thumbs = $v['thumbnails']['data'] ?? [];
        if (is_array($thumbs) && $thumbs !== []) {
            $best = 0;
            $bw   = 0;
            $bh   = 0;
            foreach ($thumbs as $t) {
                $w = (int) ($t['width'] ?? 0);
                if ($w > $best) {
                    $best = $w;
                    $bw   = $w;
                    $bh   = (int) ($t['height'] ?? 0);
                }
            }
            $this->line("  video.thumbnails  : {$bw}×{$bh}px available ⇒ a sharp poster IS obtainable from this edge");
        } else {
            $this->line('  video.thumbnails  : none returned');
        }

        $this->line('  video.permalink   : ' . (isset($v['permalink_url']) ? 'present' : '—'));
    }

    private function resolveBrand(): ?Brand
    {
        $arg = (string) $this->argument('brand');

        if (is_numeric($arg)) {
            return Brand::query()->with('connections')->find((int) $arg);
        }

        $lower = strtolower(trim($arg));

        return Brand::query()->with('connections')
            ->whereRaw('LOWER(slug) = ?', [$lower])
            ->orWhereRaw('LOWER(name) = ?', [$lower])
            ->first()
            ?: Brand::query()->with('connections')
                ->where('name', 'like', '%' . $arg . '%')
                ->orWhere('slug', 'like', '%' . $arg . '%')
                ->first();
    }
}
