<?php

declare(strict_types=1);

namespace App\Reports\Mom;

use App\Reports\Mom\Contracts\MomSection;
use App\Reports\Mom\Sections\SAudienceMixSection;
use App\Reports\Mom\Sections\SAwarenessCountrySection;
use App\Reports\Mom\Sections\SBestSellersSection;
use App\Reports\Mom\Sections\SCategoriesSection;
use App\Reports\Mom\Sections\SCountryRevenueSection;
use App\Reports\Mom\Sections\SCountryRoasSection;
use App\Reports\Mom\Sections\SExSection;
use App\Reports\Mom\Sections\SFinancialMatrixSection;
use App\Reports\Mom\Sections\SFunnelCountrySection;
use App\Reports\Mom\Sections\SFunnelLandingSection;
use App\Reports\Mom\Sections\SGenderMixSection;
use App\Reports\Mom\Sections\SGoalsSection;
use App\Reports\Mom\Sections\SKlaviyoSection;
use App\Reports\Mom\Sections\SLandingSpendVsSellersSection;
use App\Reports\Mom\Sections\SNewVsReturningSection;
use App\Reports\Mom\Sections\SNextStepsSection;
use App\Reports\Mom\Sections\SNovedadesSection;
use App\Reports\Mom\Sections\SPlacementMixSection;
use App\Reports\Mom\Sections\SPriorYearLookbackSection;
use App\Reports\Mom\Sections\SSalesEvolutionSection;
use App\Reports\Mom\Sections\SSessionsCrSection;
use App\Reports\Mom\Sections\STierRevenueSection;
use Illuminate\Contracts\Container\Container;

/**
 * M2 (monthly-report-v2-mom.md §M2): resolves a mom section builder by key —
 * mirrors ReportRegistry's pattern, scoped to the sections THIS program has
 * actually built. A key present in config/momreport.php's LAYOUT catalog but
 * absent here is real (the customizer can enable/reorder it, M4 lands the UI),
 * it just isn't buildable yet — has() tells the controller so it can degrade
 * honestly ('not_built_yet') instead of a raw 404 or a 500.
 *
 * Add one line here per section as M2/M3 build it. Nothing else in the request
 * path needs to change — the controller and route are already generic over key.
 */
final class MomSectionRegistry
{
    /** @var array<string, class-string<MomSection>> */
    private const MAP = [
        'S-EX'    => SExSection::class,
        'S-GOALS' => SGoalsSection::class,
        // M2 continuation (money + market sections):
        'S2'  => SSalesEvolutionSection::class,
        'S3'  => SNewVsReturningSection::class, // honest shell — customer_type probe not run
        'S4'  => STierRevenueSection::class,
        'S5'  => SCountryRevenueSection::class,
        'S6'  => SCountryRoasSection::class,
        'S9'  => SSessionsCrSection::class,
        'S10' => SFunnelCountrySection::class,
        'S11' => SFunnelLandingSection::class,
        'S12' => SPriorYearLookbackSection::class,
        'S1'  => SFinancialMatrixSection::class, // built without the customer_type-probe columns, per the spec's own fallback rule
        'S7'  => SCategoriesSection::class,
        'S8'  => SBestSellersSection::class,
        // M3 (Meta mechanics sections):
        'S13' => SAudienceMixSection::class,
        'S14' => SPlacementMixSection::class,
        'S15' => SGenderMixSection::class,
        'S18' => SKlaviyoSection::class,
        'S17' => SLandingSpendVsSellersSection::class, // unblocked via the product_catalog handle<->title bridge
        // M5 addendum — S16 unblocked: ad_campaign_daily_metrics gained an
        // `objective` column, and meta_breakdown_daily gained a new
        // 'awareness_country' axis (CampaignSync::syncMetaAwarenessCountry /
        // meta:backfill-awareness-country) built specifically to unblock it.
        'S16' => SAwarenessCountrySection::class,
        // M4 (editorial layer):
        'S0'  => SNextStepsSection::class,
        'S19' => SNovedadesSection::class,
    ];

    /**
     * M5 (monthly-report-v2-mom.md §M5) — "per-section 'Backfill this data'
     * chips → same backfill-dataset endpoint (dataset mapped per section)."
     * Maps a section key to the `BrandDataCoverageController`/
     * `BackfillBrandDatasetJob` dataset key that would fill its gap — null
     * for sections whose gap ISN'T a sync gap (S-GOALS/S3 need a target/probe,
     * not a backfill; S0/S19 are pure editorial content). Read by
     * `MomSectionController::show()` and attached to a 'needs_source'
     * response so the frontend can render the right CTA without its own copy
     * of this mapping.
     *
     * @var array<string, string>
     */
    private const DATASET_MAP = [
        'S-EX' => 'history',
        'S1'   => 'history',
        'S2'   => 'history',
        'S4'   => 'commerce',
        'S5'   => 'commerce',
        'S6'   => 'commerce',
        'S7'   => 'commerce',
        'S8'   => 'commerce',
        'S9'   => 'sessions',
        'S10'  => 'sessions',
        'S11'  => 'sessions',
        'S12'  => 'history',
        'S13'  => 'breakdowns',
        'S14'  => 'breakdowns',
        'S15'  => 'breakdowns',
        'S17'  => 'campaigns', // ad_product_daily rides the 'campaigns' dataset (see BackfillBrandDatasetJob)
        'S18'  => 'email',
        'S16'  => 'campaigns', // needs ad_campaign_daily_metrics.objective, which the campaigns dataset backfills first (see BackfillBrandDatasetJob ordering)
    ];

    public function __construct(private readonly Container $app)
    {
    }

    public function has(string $key): bool
    {
        return isset(self::MAP[$key]);
    }

    /** The backfill-dataset key that would fill this section's gap, or null when its gap isn't a sync gap. */
    public function datasetFor(string $key): ?string
    {
        return self::DATASET_MAP[$key] ?? null;
    }

    public function for(string $key): MomSection
    {
        if (! $this->has($key)) {
            throw new \RuntimeException("mom section not built yet: {$key}");
        }

        return $this->app->make(self::MAP[$key]);
    }

    /** @return array<int, string> every section key with a real builder, in no particular order */
    public function builtKeys(): array
    {
        return array_keys(self::MAP);
    }
}
