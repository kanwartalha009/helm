<?php

declare(strict_types=1);

namespace App\Platforms\Meta;

/**
 * ONE definition of "which Meta campaign objectives count as awareness" —
 * mom's S16 (monthly-report-v2-mom.md §M3) is the only reader today, but the
 * same rule would apply anywhere else in Helm that ever needs to know
 * "is this campaign an awareness campaign" — never a second copy of this list.
 *
 * Meta reworked its objective taxonomy in 2022 (Outcome-Driven Ads
 * Experience/ODAX): new campaigns report `OUTCOME_AWARENESS`; campaigns
 * created before the migration (or never touched since) can still report a
 * legacy value. Both eras are treated as "awareness" here so a brand's older
 * campaigns don't silently drop out of S16's concentration read.
 *
 * CAVEAT (same honesty discipline as the S1 customer_type probe): this
 * sandbox has no live Meta Marketing API access, so the exact string values
 * Meta returns for `objective` on a campaign-level Insights row have not
 * been verified against a real response this pass. If Meta's live values
 * differ from this list, campaigns simply fail to match "awareness" and S16
 * stays honestly `needs_source` rather than mis-showing zero concentration —
 * it fails closed, never fakes a number. Confirm against a real payload
 * (`meta:diagnose-campaigns` or similar) before relying on this in a client
 * meeting.
 */
final class MetaObjectives
{
    /** @var array<int, string> */
    private const AWARENESS = [
        // Post-ODAX (2022+)
        'OUTCOME_AWARENESS',
        // Legacy / pre-ODAX
        'BRAND_AWARENESS',
        'REACH',
    ];

    public static function isAwareness(?string $objective): bool
    {
        if ($objective === null || $objective === '') {
            return false;
        }

        return in_array(strtoupper($objective), self::AWARENESS, true);
    }

    /** @return array<int, string> */
    public static function awarenessValues(): array
    {
        return self::AWARENESS;
    }
}
