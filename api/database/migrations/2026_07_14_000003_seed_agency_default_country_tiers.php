<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M1: seeds the agency-wide DEFAULT tier set (brand_id = NULL) per the spec's
 * "Seed migration: agency default T1/T2/T3 + 'Other' — fully editable."
 *
 * T1/T2/T3 seed with an EMPTY `countries` array — Bosco's real tier assignments
 * are Kanwar/Bosco-owed (spec's own admission: "Bosco defines the agency tier
 * sets... confirm"), so this migration does NOT invent which countries belong
 * to which tier. Empty tiers + the resolver's auto-"Other" bucketing (any country
 * not listed in a tier falls into a synthetic "Other" group at resolve time, never
 * a stored row) means every country reads as "Other" until someone assigns it in
 * Settings — honest, not a guess. Fully editable from day one via the tier CRUD
 * endpoints this program ships alongside this migration.
 *
 * Idempotent (safe to re-run / already-seeded is a no-op) and additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $defaults = [
            ['tier_key' => 'T1', 'label' => 'Tier 1', 'color' => '#2563eb', 'position' => 1],
            ['tier_key' => 'T2', 'label' => 'Tier 2', 'color' => '#7c3aed', 'position' => 2],
            ['tier_key' => 'T3', 'label' => 'Tier 3', 'color' => '#db2777', 'position' => 3],
        ];

        foreach ($defaults as $d) {
            $exists = DB::table('country_tiers')
                ->whereNull('brand_id')
                ->where('tier_key', $d['tier_key'])
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('country_tiers')->insert([
                'workspace_id' => null,
                'brand_id'     => null,
                'tier_key'     => $d['tier_key'],
                'label'        => $d['label'],
                'color'        => $d['color'],
                'countries'    => json_encode([]),
                'position'     => $d['position'],
                'updated_by'   => null,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('country_tiers')
            ->whereNull('brand_id')
            ->whereIn('tier_key', ['T1', 'T2', 'T3'])
            ->delete();
    }
};
