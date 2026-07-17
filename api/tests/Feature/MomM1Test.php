<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\CountryTier;
use App\Models\ReportLayout;
use App\Models\User;
use App\Services\CountryTiers;
use App\Services\ReportLayouts;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * M1 (monthly-report-v2-mom.md §M1): country tiers + report_layouts, the two
 * platform primitives this program's whole build sits on.
 *
 * Covers exactly the spec's own test list: "tier resolution precedence + Other
 * bucketing; layout resolution precedence; share snapshot immunity; RBAC."
 *
 * Share snapshot immunity is tested at the ReportLayouts::resolve() level, not
 * through an actual mom-report share flow — the 'mom' ReportType class doesn't
 * exist until M2. What matters for share safety is that resolve() returns a
 * plain value copy, not a live reference to the underlying rows; that property
 * is what ReportController::createShare will rely on once M2 wires shares for
 * 'mom', and it's fully testable now.
 */
class MomM1Test extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    private function makeBrand(): Brand
    {
        return Brand::factory()->create([
            'base_currency' => 'EUR',
            'timezone'      => 'Europe/Madrid',
            'status'        => 'active',
        ]);
    }

    // ---------------------------------------------------------------
    // Tier resolution precedence + Other bucketing
    // ---------------------------------------------------------------

    public function test_tier_resolution_falls_back_to_agency_default_then_prefers_brand_override(): void
    {
        // CORRECTED (M5 S1/HeatTable pass, 2026-07-15): a later migration
        // (2026_07_14_000003_seed_agency_default_country_tiers) ships a real
        // agency-default T1/T2/T3/Other set unconditionally — "no rows
        // anywhere yet" is no longer true on a fresh migrate, and reusing its
        // own T1/T2 keys below collided with the seed's rows (unique
        // constraint). Clearing the seeded agency defaults first restores
        // this test's original premise so it keeps testing the SAME
        // precedence logic in isolation from that seed.
        CountryTier::query()->whereNull('brand_id')->delete();

        $brand = $this->makeBrand();

        // No rows anywhere yet -> resolve() is empty, never an error.
        $this->assertSame([], app(CountryTiers::class)->resolve($brand));

        // Agency-wide default set (brand_id null).
        CountryTier::create(['brand_id' => null, 'tier_key' => 'T1', 'label' => 'Tier 1', 'color' => '#111111', 'countries' => ['US', 'CA'], 'position' => 0]);
        CountryTier::create(['brand_id' => null, 'tier_key' => 'T2', 'label' => 'Tier 2', 'color' => '#222222', 'countries' => ['ES'], 'position' => 1]);

        // The brand has no override yet -> reads the agency default.
        $resolved = app(CountryTiers::class)->resolve($brand);
        $this->assertSame('T1', $resolved['US']['tierKey']);
        $this->assertSame('T1', $resolved['CA']['tierKey']);
        $this->assertSame('T2', $resolved['ES']['tierKey']);
        $this->assertFalse(app(CountryTiers::class)->hasOverride($brand));

        // The brand gets its OWN override set -> it is used EXCLUSIVELY, the
        // agency default is not merged in even partially.
        CountryTier::create(['brand_id' => $brand->id, 'tier_key' => 'US-ONLY', 'label' => 'US only', 'color' => '#333333', 'countries' => ['US'], 'position' => 0]);

        $resolved = app(CountryTiers::class)->resolve($brand);
        $this->assertSame('US-ONLY', $resolved['US']['tierKey']);
        $this->assertArrayNotHasKey('ES', $resolved); // agency's ES tier is NOT inherited
        $this->assertTrue(app(CountryTiers::class)->hasOverride($brand));
    }

    public function test_tier_resolution_buckets_unassigned_countries_as_other(): void
    {
        $brand = $this->makeBrand();
        CountryTier::create(['brand_id' => $brand->id, 'tier_key' => 'T1', 'label' => 'Tier 1', 'color' => '#111111', 'countries' => ['US'], 'position' => 0]);

        $resolved = app(CountryTiers::class)->resolve($brand);

        // FR was never listed in any tier -> absent from the map, i.e. "Other" —
        // never dropped from the country list, never force-assigned a fake tier.
        $this->assertArrayHasKey('US', $resolved);
        $this->assertArrayNotHasKey('FR', $resolved);
    }

    public function test_replace_brand_tiers_is_atomic_and_uppercases_country_codes(): void
    {
        $brand = $this->makeBrand();
        app(CountryTiers::class)->replaceBrandTiers($brand, [
            ['tier_key' => 'T1', 'label' => 'Tier 1', 'color' => '#111111', 'countries' => ['us', 'ca']],
        ], null);

        $resolved = app(CountryTiers::class)->resolve($brand);
        $this->assertSame('T1', $resolved['US']['tierKey']);
        $this->assertArrayNotHasKey('us', $resolved);
        $this->assertSame(1, CountryTier::where('brand_id', $brand->id)->count());

        // Replacing again fully replaces — no leftover rows from the first save.
        app(CountryTiers::class)->replaceBrandTiers($brand, [
            ['tier_key' => 'T2', 'label' => 'Tier 2', 'color' => '#222222', 'countries' => ['FR']],
        ], null);
        $this->assertSame(1, CountryTier::where('brand_id', $brand->id)->count());
        $this->assertSame('T2', CountryTier::where('brand_id', $brand->id)->first()->tier_key);
    }

    // ---------------------------------------------------------------
    // Layout resolution precedence
    // ---------------------------------------------------------------

    public function test_report_layout_resolution_precedence_code_default_then_agency_default_then_brand_override(): void
    {
        $brand = $this->makeBrand();
        $svc   = app(ReportLayouts::class);

        // Nothing saved anywhere -> the CODE default (config/momreport.php),
        // starting with S-EX per REV2 R4 ("always first by default").
        $sections = $svc->resolve($brand, 'mom');
        $this->assertNotEmpty($sections);
        $this->assertSame('S-EX', $sections[0]['key']);
        $this->assertSame(0, $sections[0]['position']);

        // An unknown report type has no catalog -> empty, never a guessed layout.
        $this->assertSame([], $svc->resolve($brand, 'not-a-real-type'));

        // Agency-wide default layout saved -> used instead of the code default.
        $svc->save(null, 'mom', [
            ['key' => 'S1', 'enabled' => true, 'position' => 0, 'view' => 'table'],
            ['key' => 'S-EX', 'enabled' => false, 'position' => 1, 'view' => 'chart'],
        ], null);
        $sections = $svc->resolve($brand, 'mom');
        $this->assertSame('S1', $sections[0]['key']);
        $this->assertSame('table', $sections[0]['view']);
        $this->assertFalse($sections[1]['enabled']);

        // Brand override saved -> used instead of the agency default.
        $svc->save($brand, 'mom', [
            ['key' => 'S-GOALS', 'enabled' => true, 'position' => 0, 'view' => 'both'],
        ], null);
        $sections = $svc->resolve($brand, 'mom');
        $this->assertCount(1, $sections);
        $this->assertSame('S-GOALS', $sections[0]['key']);
        $this->assertTrue($svc->hasOverride($brand, 'mom'));

        // Clearing the override reverts to the agency default, not the code default.
        $svc->clearBrandLayout($brand, 'mom');
        $sections = $svc->resolve($brand, 'mom');
        $this->assertSame('S1', $sections[0]['key']);
    }

    public function test_resolve_uses_catalog_labels_even_when_a_saved_layout_dropped_them(): void
    {
        // Regression (Kanwar, 2026-07-15 — "on reload headings change to S1/S2"):
        // the customizer only persists key/enabled/position/view (no label), so a
        // saved layout stores label==key. resolve() must re-derive the human label
        // from the code catalog, or every heading reads as its raw key after a save.
        $brand = $this->makeBrand();
        $svc   = app(ReportLayouts::class);

        // Simulate exactly what the customizer sends: NO label field at all.
        $svc->save(null, 'mom', [
            ['key' => 'S2', 'enabled' => true, 'position' => 0, 'view' => 'chart'],
            ['key' => 'S1', 'enabled' => true, 'position' => 1, 'view' => 'both'],
        ], null);

        $sections = $svc->resolve($brand, 'mom');
        $this->assertSame('S2', $sections[0]['key']);
        $this->assertSame('Total sales evolution', $sections[0]['label']); // NOT "S2"
        $this->assertSame('Financial matrix', $sections[1]['label']);      // NOT "S1"

        // Agency-default read path resolves catalog labels too.
        $agency = $svc->agencyDefaultLayout('mom');
        $this->assertSame('Total sales evolution', $agency[0]['label']);
    }

    public function test_report_layout_resolve_is_a_pure_value_snapshot_not_a_live_reference(): void
    {
        // The property share-safety depends on: capturing resolve()'s output, then
        // mutating the underlying row, must NOT change the already-captured value —
        // otherwise a share link would silently reshuffle when the agency later
        // re-customizes the live layout (exactly what REV2's snapshot rule forbids).
        $brand = $this->makeBrand();
        $svc   = app(ReportLayouts::class);

        $svc->save($brand, 'mom', [
            ['key' => 'S1', 'enabled' => true, 'position' => 0, 'view' => 'table'],
        ], null);

        $snapshot = $svc->resolve($brand, 'mom');
        $this->assertSame('table', $snapshot[0]['view']);

        // Re-customize the LIVE layout after the snapshot was taken.
        $svc->save($brand, 'mom', [
            ['key' => 'S1', 'enabled' => true, 'position' => 0, 'view' => 'chart'],
        ], null);

        // The earlier snapshot is untouched — a plain PHP array, no ORM reference.
        $this->assertSame('table', $snapshot[0]['view']);
        // A fresh resolve() now sees the new value, proving it wasn't stale caching.
        $this->assertSame('chart', $svc->resolve($brand, 'mom')[0]['view']);
    }

    public function test_apply_to_all_brands_sets_the_agency_default_and_clears_every_brand_override(): void
    {
        // Kanwar, 2026-07-17 — "a button to apply agency default settings to every
        // brand": POST apply-to-all saves the posted layout as the agency default
        // AND drops every brand's own override, so all brands resolve to this one.
        $svc = app(ReportLayouts::class);
        $brandA = $this->makeBrand();
        $brandB = $this->makeBrand();

        // Both brands have their OWN (different) overrides.
        $svc->save($brandA, 'mom', [['key' => 'S2', 'enabled' => true, 'position' => 0, 'view' => 'chart']], null);
        $svc->save($brandB, 'mom', [['key' => 'S5', 'enabled' => true, 'position' => 0, 'view' => 'table']], null);

        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));
        $res = $this->postJson('/api/report-layouts/mom/apply-to-all', [
            'sections' => [
                ['key' => 'S1', 'enabled' => true, 'position' => 0, 'view' => 'table'],
                ['key' => 'S2', 'enabled' => true, 'position' => 1, 'view' => 'chart'],
            ],
        ])->assertOk();

        $this->assertSame(2, $res->json('brandsReset')); // both overrides removed

        // Neither brand has an override now, and both resolve to the applied layout.
        $this->assertFalse($svc->hasOverride($brandA->fresh(), 'mom'));
        $this->assertFalse($svc->hasOverride($brandB->fresh(), 'mom'));
        $this->assertSame('S1', $svc->resolve($brandA->fresh(), 'mom')[0]['key']);
        $this->assertSame('S1', $svc->resolve($brandB->fresh(), 'mom')[0]['key']);
    }

    public function test_apply_to_all_brands_is_master_admin_only(): void
    {
        $this->makeBrand();
        Sanctum::actingAs(User::factory()->create(['role' => 'manager']));
        $this->postJson('/api/report-layouts/mom/apply-to-all', [
            'sections' => [['key' => 'S1', 'enabled' => true, 'position' => 0, 'view' => 'table']],
        ])->assertForbidden();
    }

    // ---------------------------------------------------------------
    // RBAC
    // ---------------------------------------------------------------

    public function test_country_tiers_and_report_layouts_rbac(): void
    {
        $brand = $this->makeBrand();

        // team_member attached to the brand CAN read both, but must not WRITE either —
        // same split as targets (BrandPolicy::update = master_admin|manager).
        $tm = User::factory()->create(['role' => 'team_member']);
        $brand->users()->attach($tm->id);
        Sanctum::actingAs($tm);

        $this->getJson("/api/brands/{$brand->slug}/country-tiers")->assertOk();
        $this->putJson("/api/brands/{$brand->slug}/country-tiers", [
            'tiers' => [['tier_key' => 'T1', 'label' => 'Tier 1', 'color' => '#111111', 'countries' => ['US']]],
        ])->assertForbidden();

        $this->getJson("/api/brands/{$brand->slug}/report-layouts/mom")->assertOk();
        $this->putJson("/api/brands/{$brand->slug}/report-layouts/mom", [
            'sections' => [['key' => 'S1', 'enabled' => true, 'position' => 0, 'view' => 'table']],
        ])->assertForbidden();

        // Agency-default routes are master_admin only — a plain manager is forbidden.
        $manager = User::factory()->create(['role' => 'manager']);
        Sanctum::actingAs($manager);
        $this->getJson('/api/workspace-country-tiers')->assertForbidden();

        // master_admin can do everything.
        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));
        $this->putJson("/api/brands/{$brand->slug}/country-tiers", [
            'tiers' => [['tier_key' => 'T1', 'label' => 'Tier 1', 'color' => '#111111', 'countries' => ['US']]],
        ])->assertCreated();
        $this->putJson('/api/workspace-country-tiers', [
            'tiers' => [['tier_key' => 'T1', 'label' => 'Tier 1', 'color' => '#111111', 'countries' => []]],
        ])->assertCreated();
        $this->putJson('/api/report-layouts/mom/default', [
            'sections' => [['key' => 'S1', 'enabled' => true, 'position' => 0, 'view' => 'table']],
        ])->assertCreated();

        // Duplicate tier_key in one payload is rejected — a silently-ambiguous
        // tier assignment is worse than a validation error.
        $this->putJson("/api/brands/{$brand->slug}/country-tiers", [
            'tiers' => [
                ['tier_key' => 'T1', 'label' => 'Tier 1', 'color' => '#111111', 'countries' => ['US']],
                ['tier_key' => 'T1', 'label' => 'Tier 1 dup', 'color' => '#222222', 'countries' => ['CA']],
            ],
        ])->assertStatus(422);
    }
}
