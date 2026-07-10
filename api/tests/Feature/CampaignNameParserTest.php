<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\WorkspaceSetting;
use App\Services\Rules\CampaignNameParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The naming-convention seam (spec §4 Phase 5, item 4): OFF with no rules,
 * case-insensitive matching when configured, and malformed lines skipped rather
 * than fatal. This locks the parsing contract before the feature is wired in.
 */
final class CampaignNameParserTest extends TestCase
{
    use RefreshDatabase;

    public function test_off_by_default(): void
    {
        $p = new CampaignNameParser();
        $this->assertFalse($p->enabled());
        $this->assertSame([], $p->rules());
        $this->assertNull($p->handleFor('Black Friday 2026'));
    }

    public function test_matches_configured_rules_case_insensitively(): void
    {
        WorkspaceSetting::setValue(CampaignNameParser::SETTING_KEY, implode("\n", [
            'BLACK[-_ ]?FRIDAY => bf-hoodie',
            '^NP_.*_SUMMER => summer-tee',
            'this line has no arrow',   // skipped
            '( => broken-regex',         // invalid regex → skipped
            '   => empty-pattern',       // empty pattern → skipped
        ]));

        $p = new CampaignNameParser();
        $this->assertTrue($p->enabled());
        $this->assertCount(2, $p->rules(), 'only the two valid rules survive');
        $this->assertSame('bf-hoodie', $p->handleFor('Black Friday 2026'));
        $this->assertSame('bf-hoodie', $p->handleFor('WINTER-black_friday-push'));
        $this->assertSame('summer-tee', $p->handleFor('NP_DE_SUMMER_2026'));
        $this->assertNull($p->handleFor('Evergreen prospecting'));
    }
}
