<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\BrandStyle;
use App\Models\User;
use App\Services\BrandStyleService;
use App\Services\PaletteExtractor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GO-4.4 — Moodboard / brand style (master plan §7.4). Covers the confirm gate
 * (the whole point — GO-5 refuses an unconfirmed style), winner ranking,
 * pure-PHP palette extraction, LLM-tone key-gating, the suggest path (palette
 * from fetched thumbnails), and RBAC.
 */
class BrandStyleTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Europe/Madrid';

    private function makeBrand(): Brand
    {
        return Brand::factory()->create(['base_currency' => 'EUR', 'timezone' => self::TZ, 'status' => 'active']);
    }

    private function actingMasterAdmin(): User
    {
        $u = User::factory()->create(['role' => 'master_admin']);
        Sanctum::actingAs($u);

        return $u;
    }

    private function seedCreative(int $brandId, string $adId, float $spend, float $value, string $thumb): void
    {
        DB::table('ad_creative_daily')->insert([
            'brand_id' => $brandId, 'platform' => 'meta', 'date' => CarbonImmutable::now(self::TZ)->subDays(5)->toDateString(),
            'ad_id' => $adId, 'ad_name' => "Ad {$adId}", 'thumbnail_url' => $thumb,
            'spend' => $spend, 'conversion_value' => $value, 'impressions' => 1000, 'clicks' => 50, 'conversions' => 5,
            'currency' => 'EUR', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private static function pngBytes(int $r, int $g, int $b): string
    {
        $im = imagecreatetruecolor(16, 16);
        imagefill($im, 0, 0, imagecolorallocate($im, $r, $g, $b));
        ob_start();
        imagepng($im);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        return $bytes;
    }

    public function test_palette_extractor_finds_the_dominant_colour(): void
    {
        $palette = (new PaletteExtractor())->fromImages([self::pngBytes(220, 30, 30)], 3);

        $this->assertNotEmpty($palette);
        $this->assertSame('#E02020', $palette[0]['hex']);
        $this->assertEqualsWithDelta(1.0, $palette[0]['weight'], 0.001);
    }

    public function test_show_returns_none_scaffold_with_live_winners_when_unsaved(): void
    {
        $this->actingMasterAdmin();
        $brand = $this->makeBrand();
        // Two creatives: one meaningful winner, one below the $50 floor.
        $this->seedCreative($brand->id, 'a1', 400, 1600, 'https://cdn.example/a1.jpg'); // ROAS 4.0
        $this->seedCreative($brand->id, 'a2', 10, 90, 'https://cdn.example/a2.jpg');     // below floor

        $res = $this->getJson("/api/brands/{$brand->slug}/style")->assertOk();

        $this->assertSame('none', $res->json('status'));
        $this->assertSame([], $res->json('palette'));
        $winners = $res->json('winners');
        $this->assertCount(1, $winners);
        $this->assertSame('a1', $winners[0]['adId']);
        $this->assertEquals(4.0, $winners[0]['roas']);
    }

    public function test_confirm_gate_null_until_confirmed_then_returns_style(): void
    {
        $this->actingMasterAdmin();
        $brand = $this->makeBrand();
        $svc = app(BrandStyleService::class);

        // Saved as draft → GO-5 gate is null.
        $svc->save($brand, ['toneWords' => ['warm', 'bold']], 1, confirm: false);
        $this->assertNull($svc->confirmed($brand));
        $this->assertSame('draft', BrandStyle::where('brand_id', $brand->id)->value('status'));

        // Confirmed → the gate returns the row.
        $svc->save($brand, ['toneWords' => ['warm', 'bold']], 7, confirm: true);
        $confirmed = $svc->confirmed($brand);
        $this->assertNotNull($confirmed);
        $this->assertTrue($confirmed->isConfirmed());
        $this->assertSame(7, $confirmed->confirmed_by);
    }

    public function test_confirmed_style_stays_confirmed_on_a_plain_edit(): void
    {
        $this->actingMasterAdmin();
        $brand = $this->makeBrand();
        $svc = app(BrandStyleService::class);

        $svc->save($brand, ['toneWords' => ['warm']], 3, confirm: true);
        // A later edit without confirm must not silently un-approve the style.
        $svc->save($brand, ['toneWords' => ['warm', 'premium']], 3, confirm: false);

        $this->assertNotNull($svc->confirmed($brand));
        $this->assertSame(['warm', 'premium'], BrandStyle::where('brand_id', $brand->id)->value('tone_words'));
    }

    public function test_tone_draft_is_key_gated_and_returns_empty_without_a_key(): void
    {
        $this->actingMasterAdmin();
        $brand = $this->makeBrand();
        DB::table('product_catalog')->insert([
            'brand_id' => $brand->id, 'handle' => 'silk-scarf', 'title' => 'Silk Scarf', 'product_type' => 'Accessories',
            'variant_count' => 1, 'total_inventory' => 10, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // No LLM key on file → no call, empty tone (honest, never fabricated).
        $this->assertSame([], app(BrandStyleService::class)->draftTone($brand));
    }

    public function test_suggest_extracts_palette_from_fetched_thumbnails(): void
    {
        $this->actingMasterAdmin();
        $brand = $this->makeBrand();
        $this->seedCreative($brand->id, 'a1', 400, 1600, 'https://cdn.example/a1.jpg');

        Http::fake(['cdn.example/*' => Http::response(self::pngBytes(10, 120, 200), 200)]);

        $res = $this->postJson("/api/brands/{$brand->slug}/style/suggest")->assertOk();

        $palette = $res->json('palette');
        $this->assertNotEmpty($palette);
        $this->assertSame('#2060E0', $palette[0]['hex']); // (10,120,200) binned to bucket midpoints
        $this->assertCount(1, $res->json('winners'));
    }

    public function test_save_and_confirm_over_http_then_show_reflects_it(): void
    {
        $this->actingMasterAdmin();
        $brand = $this->makeBrand();

        $this->putJson("/api/brands/{$brand->slug}/style", [
            'palette'   => [['hex' => '#112233', 'weight' => 0.5]],
            'toneWords' => ['minimal', 'premium'],
            'doDont'    => ['do' => ['use whitespace'], 'dont' => ['crowd the frame']],
            'confirm'   => true,
        ])->assertCreated()->assertJsonPath('status', 'confirmed');

        $res = $this->getJson("/api/brands/{$brand->slug}/style")->assertOk();
        $this->assertSame('confirmed', $res->json('status'));
        $this->assertSame(['minimal', 'premium'], $res->json('toneWords'));
        $this->assertSame('#112233', $res->json('palette.0.hex'));
    }

    public function test_rbac_read_visible_write_admin_only(): void
    {
        $brand = $this->makeBrand();
        $tm = User::factory()->create(['role' => 'team_member']);
        $brand->users()->attach($tm->id);
        Sanctum::actingAs($tm);

        // team_member can READ.
        $this->getJson("/api/brands/{$brand->slug}/style")->assertOk();
        // ...but not SUGGEST or SAVE.
        $this->postJson("/api/brands/{$brand->slug}/style/suggest")->assertForbidden();
        $this->putJson("/api/brands/{$brand->slug}/style", ['toneWords' => ['x']])->assertForbidden();
    }
}
