<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Smoke tests for Phase 1.5 RBAC + the brand-manager dashboard scope shipped
 * 2026-06-19. Guards the load-bearing rules: limited roles only ever see the
 * brands assigned to them, admins/managers can grant access from either side,
 * and limited roles cannot manage a brand's team.
 */
final class RbacAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_requires_authentication(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
    }

    public function test_team_member_dashboard_shows_only_assigned_brands(): void
    {
        $assigned = Brand::factory()->create();
        $other    = Brand::factory()->create();

        $tm = User::factory()->teamMember()->create();
        $tm->accessibleBrands()->attach($assigned->id);

        Sanctum::actingAs($tm);

        $rows  = $this->getJson('/api/dashboard')->assertOk()->json('rows');
        $slugs = collect($rows)->pluck('brand.slug')->all();

        $this->assertContains($assigned->slug, $slugs);
        $this->assertNotContains($other->slug, $slugs);
    }

    public function test_manager_dashboard_shows_all_brands(): void
    {
        $a = Brand::factory()->create();
        $b = Brand::factory()->create();

        Sanctum::actingAs(User::factory()->manager()->create());

        $rows  = $this->getJson('/api/dashboard')->assertOk()->json('rows');
        $slugs = collect($rows)->pluck('brand.slug')->all();

        $this->assertContains($a->slug, $slugs);
        $this->assertContains($b->slug, $slugs);
    }

    public function test_admin_can_grant_brand_access_to_a_user(): void
    {
        $admin = User::factory()->masterAdmin()->create();
        $tm    = User::factory()->teamMember()->create();
        $brand = Brand::factory()->create();

        Sanctum::actingAs($admin);

        $this->patchJson("/api/users/{$tm->id}", ['brand_ids' => [$brand->id]])->assertOk();

        $this->assertDatabaseHas('brand_user_access', [
            'user_id'  => $tm->id,
            'brand_id' => $brand->id,
        ]);
    }

    public function test_admin_can_assign_users_to_a_brand(): void
    {
        $admin = User::factory()->masterAdmin()->create();
        $tm    = User::factory()->teamMember()->create();
        $brand = Brand::factory()->create();

        Sanctum::actingAs($admin);

        $this->putJson("/api/brands/{$brand->slug}/users", ['user_ids' => [$tm->id]])->assertOk();

        $this->assertDatabaseHas('brand_user_access', [
            'user_id'  => $tm->id,
            'brand_id' => $brand->id,
        ]);
    }

    public function test_team_member_cannot_manage_a_brand_team(): void
    {
        $brand = Brand::factory()->create();
        $tm    = User::factory()->teamMember()->create();
        // Grant access so the route's access.brand middleware passes; the policy
        // (admin/manager only) is what must still block the write.
        $tm->accessibleBrands()->attach($brand->id);

        Sanctum::actingAs($tm);

        $this->putJson("/api/brands/{$brand->slug}/users", ['user_ids' => []])->assertForbidden();
    }

    public function test_limited_user_cannot_open_an_unassigned_brand(): void
    {
        $assigned = Brand::factory()->create();
        $other    = Brand::factory()->create();

        $tm = User::factory()->teamMember()->create();
        $tm->accessibleBrands()->attach($assigned->id);

        Sanctum::actingAs($tm);

        $this->getJson("/api/brands/{$other->slug}")->assertNotFound();
        $this->getJson("/api/brands/{$assigned->slug}")->assertOk();
    }

    public function test_master_admin_without_mfa_is_flagged_required(): void
    {
        Sanctum::actingAs(User::factory()->masterAdmin()->create());

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('mfaRequired', true);
    }

    public function test_non_admin_is_not_mfa_required(): void
    {
        Sanctum::actingAs(User::factory()->manager()->create());

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('mfaRequired', false);
    }
}
