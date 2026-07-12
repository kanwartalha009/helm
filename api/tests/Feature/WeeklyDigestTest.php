<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Anomaly;
use App\Models\Brand;
use App\Models\User;
use App\Platforms\Slack\SlackClient;
use App\Services\Digest\SlackBlocks;
use App\Services\Digest\WeeklyDigest;
use App\Services\Ledger\Ledger;
use App\Services\PlatformCredentialService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GO-3.5 — the weekly digest.
 *
 * Two properties are load-bearing:
 *   1. **An honest empty.** A quiet week says "quiet week" and stops — it does not pad
 *      itself with vanity metrics. A digest that always has something to say is one
 *      people stop opening, and then the week it matters, nobody reads it.
 *   2. **Failure tolerance.** Slack being down, rate-limited, or revoked must never fail
 *      the scheduled run. A chat integration is a nice-to-have; it does not get to break
 *      the cron or page anyone.
 *
 * And the digest reports what Helm got WRONG this week, not just what it got right.
 */
final class WeeklyDigestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-15 09:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function brand(): Brand
    {
        return Brand::factory()->create(['status' => 'active', 'timezone' => 'UTC', 'niche' => 'jewelry']);
    }

    public function test_a_quiet_week_says_so_and_does_not_pad_itself(): void
    {
        $this->brand();   // a brand, but nothing happened

        $d = app(WeeklyDigest::class)->compose();

        $this->assertTrue($d['empty']);
        $this->assertStringContainsString('Quiet week', $d['emptyNote']);

        // And it renders as ONE line in Slack — no vanity metrics to look busy.
        $blocks = app(SlackBlocks::class)->build($d);
        $this->assertCount(2, $blocks);                    // header + the quiet-week line
        $this->assertSame('header', $blocks[0]['type']);
        $this->assertStringContainsString('Quiet week', $blocks[1]['text']['text']);
    }

    public function test_it_composes_recommendations_anomalies_and_the_track_record(): void
    {
        $b = $this->brand();

        app(Ledger::class)->record($b, 'ad_audit', 'pause', 'brand', 'meta', 'Pause 3 losing campaigns', ['rule' => 't']);
        Anomaly::create([
            'brand_id' => $b->id, 'date' => '2026-06-14', 'kind' => 'roas_drop', 'subject' => '',
            'severity' => 'critical', 'evidence' => ['rule' => 'roas_drop'],
        ]);

        $d = app(WeeklyDigest::class)->compose();

        $this->assertFalse($d['empty']);
        $this->assertSame(1, $d['sections']['newRecommendations']['count']);
        $this->assertSame(1, $d['sections']['anomalies']['count']);
        $this->assertSame('Pause 3 losing campaigns', $d['sections']['newRecommendations']['rows'][0]['title']);

        $blocks = app(SlackBlocks::class)->build($d);
        $json   = json_encode($blocks);
        $this->assertStringContainsString('new recommendation', (string) $json);
        $this->assertStringContainsString('open anomaly', (string) $json);
    }

    public function test_the_digest_reports_what_helm_got_wrong_this_week(): void
    {
        // An engine that only reports its wins in its own weekly email is running a
        // marketing campaign, not a feedback loop.
        $b = $this->brand();
        $ledger = app(Ledger::class);
        $user = User::factory()->create();

        $lost = $ledger->record($b, 'ad_audit', 'scale', 'brand', 'meta', 'Scale meta', ['rule' => 't'], 'solid', outcomeMetric: 'roas', baselineValue: 3.0);
        $ledger->transition($lost, 'accepted', $user);
        $ledger->measure($lost, 'worsened', value14d: 1.0);

        $d = app(WeeklyDigest::class)->compose();

        $this->assertSame(1, $d['sections']['trackRecord']['measuredThisWeek']);
        $this->assertSame(1, $d['sections']['trackRecord']['worsenedThisWeek']);
        $this->assertSame(0, $d['sections']['trackRecord']['improvedThisWeek']);

        // The loss is in the Slack payload, not buried.
        $json = (string) json_encode(app(SlackBlocks::class)->build($d));
        $this->assertStringContainsString('worsened', $json);
    }

    public function test_competitor_movement_carries_its_proxy_label(): void
    {
        $b = $this->brand();
        app(Ledger::class)->record($b, 'ad_audit', 'fix', 'brand', 'meta', 'x', ['rule' => 't']);

        $d = app(WeeklyDigest::class)->compose();
        $this->assertStringContainsString('Proxy', $d['sections']['competitorMovement']['label']);
    }

    // ── Slack delivery ───────────────────────────────────────────────────────────

    private function configureSlack(): void
    {
        app(PlatformCredentialService::class)->set('slack', 'webhook_url', 'https://hooks.slack.com/services/T/B/xxx');
    }

    public function test_no_webhook_is_not_an_error(): void
    {
        // The Slack install is Kanwar's to do. Not having done it must never look like a
        // broken cron — the in-app digest works regardless.
        $this->brand();
        Http::fake();

        $this->artisan('digest:weekly')->assertExitCode(0);
        Http::assertNothingSent();
    }

    public function test_it_posts_block_kit_when_a_webhook_is_configured(): void
    {
        $b = $this->brand();
        app(Ledger::class)->record($b, 'ad_audit', 'pause', 'brand', 'meta', 'Pause it', ['rule' => 't']);
        $this->configureSlack();

        Http::fake(['hooks.slack.com/*' => Http::response('ok', 200)]);

        $this->artisan('digest:weekly')->assertExitCode(0);

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return str_contains($request->url(), 'hooks.slack.com')
                && isset($body['blocks'])
                && $body['blocks'][0]['type'] === 'header';
        });
    }

    public function test_a_slack_outage_never_fails_the_scheduled_run(): void
    {
        // Slack down / webhook revoked / rate-limited: all tolerated. A nice-to-have does
        // not get to break the cron.
        $b = $this->brand();
        app(Ledger::class)->record($b, 'ad_audit', 'pause', 'brand', 'meta', 'Pause it', ['rule' => 't']);
        $this->configureSlack();

        Http::fake(['hooks.slack.com/*' => Http::response('no_service', 500)]);

        $this->artisan('digest:weekly')->assertExitCode(0);   // still green
    }

    public function test_rate_limiting_is_tolerated(): void
    {
        $b = $this->brand();
        $this->configureSlack();
        Http::fake(['hooks.slack.com/*' => Http::response('', 429, ['Retry-After' => '30'])]);

        $res = app(SlackClient::class)->post([['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => 'x']]]);

        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('rate-limited', $res['message']);
    }

    public function test_the_in_app_digest_endpoint_works_without_slack(): void
    {
        // Slack is optional DELIVERY, not the feature.
        $b = $this->brand();
        app(Ledger::class)->record($b, 'ad_audit', 'pause', 'brand', 'meta', 'Pause it', ['rule' => 't']);

        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));
        $res = $this->getJson('/api/digest')->assertOk()->json();

        $this->assertFalse($res['empty']);
        $this->assertSame(1, $res['sections']['newRecommendations']['count']);
    }
}
