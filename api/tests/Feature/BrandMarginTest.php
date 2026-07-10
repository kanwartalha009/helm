<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 0 of the product-audit build (spec §4 Phase 0): per-brand gross margin +
 * target CPA and the derived breakeven ROAS. Missing ≠ zero — null margin yields
 * a null breakeven so margin-based rules stay silently off, never guessed.
 */
final class BrandMarginTest extends TestCase
{
    use RefreshDatabase;

    private function actAsAdmin(): void
    {
        Sanctum::actingAs(User::factory()->masterAdmin()->create());
    }

    public function test_setting_margin_and_cpa_persists_and_returns_breakeven(): void
    {
        $this->actAsAdmin();
        $brand = Brand::factory()->create();

        $res = $this->patchJson("/api/brands/{$brand->slug}", [
            'gross_margin_pct' => 50,
            'target_cpa'       => 30,
        ]);

        $res->assertOk();
        $res->assertJsonPath('grossMarginPct', 50.0);
        $res->assertJsonPath('targetCpa', 30.0);
        $res->assertJsonPath('breakevenRoas', 2.0); // 1 ÷ 0.50

        $brand->refresh();
        $this->assertSame('50.00', (string) $brand->gross_margin_pct);
        $this->assertSame('30.00', (string) $brand->target_cpa);
    }

    public function test_null_margin_yields_null_breakeven(): void
    {
        $this->actAsAdmin();
        $brand = Brand::factory()->create(['gross_margin_pct' => null, 'target_cpa' => null]);

        $res = $this->patchJson("/api/brands/{$brand->slug}", ['group_tag' => 'x']);

        $res->assertOk();
        $res->assertJsonPath('grossMarginPct', null);
        $res->assertJsonPath('breakevenRoas', null);
    }

    public function test_breakeven_math(): void
    {
        $this->assertSame(2.0, Brand::factory()->make(['gross_margin_pct' => 50])->breakevenRoas());
        $this->assertSame(2.5, Brand::factory()->make(['gross_margin_pct' => 40])->breakevenRoas());
        $this->assertNull(Brand::factory()->make(['gross_margin_pct' => null])->breakevenRoas());
    }

    public function test_validation_bounds(): void
    {
        $this->actAsAdmin();
        $brand = Brand::factory()->create();

        $this->patchJson("/api/brands/{$brand->slug}", ['gross_margin_pct' => 0])->assertStatus(422);
        $this->patchJson("/api/brands/{$brand->slug}", ['gross_margin_pct' => 100])->assertStatus(422);
        $this->patchJson("/api/brands/{$brand->slug}", ['target_cpa' => -5])->assertStatus(422);
        // null is valid — it clears the value and switches margin rules off.
        $this->patchJson("/api/brands/{$brand->slug}", ['gross_margin_pct' => null])->assertOk();
    }
}
