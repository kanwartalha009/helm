<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Platforms\MetaAdLibrary\AdLibraryClient;
use App\Services\PlatformCredentialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Ads Library Phase 0 — the per-workspace token connectivity check. The client is
 * the ONLY place Ad Library HTTP happens (guardrail); here it's Http-faked. Key
 * guarantee: a failing call surfaces Meta's message but NEVER the token.
 */
final class AdLibraryClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_not_configured_when_no_token_saved(): void
    {
        $res = app(AdLibraryClient::class)->test();

        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('No Ad Library token', $res['message']);
    }

    public function test_valid_token_with_data_envelope_is_ok(): void
    {
        app(PlatformCredentialService::class)->set('meta_adlib', 'access_token', 'GOODTOKEN');
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => []], 200)]);

        $res = app(AdLibraryClient::class)->test();

        $this->assertTrue($res['ok']);
    }

    public function test_api_error_surfaces_message_but_never_the_token(): void
    {
        app(PlatformCredentialService::class)->set('meta_adlib', 'access_token', 'SECRET-BAD-TOKEN');
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token.']], 400)]);

        $res = app(AdLibraryClient::class)->test();

        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('Invalid OAuth', $res['message']);
        $this->assertStringNotContainsString('SECRET-BAD-TOKEN', $res['message']);
    }

    public function test_meta_adlib_is_in_the_credential_schema(): void
    {
        $schema = app(PlatformCredentialService::class)->schema();

        $this->assertArrayHasKey('meta_adlib', $schema);
        $this->assertSame('access_token', $schema['meta_adlib'][0]['key']);
        $this->assertTrue($schema['meta_adlib'][0]['sensitive']);
    }

    public function test_hourly_budget_raises_rate_limit_when_spent(): void
    {
        config(['adslibrary.call_budget_per_hour' => 1]);
        app(PlatformCredentialService::class)->set('meta_adlib', 'access_token', 'GOODTOKEN');
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => []], 200)]);

        $client = new AdLibraryClient(app(PlatformCredentialService::class));
        $client->get(['search_terms' => 'a', 'ad_reached_countries' => '["ES"]']); // 1st = ok
        $this->assertSame(1, $client->callsUsed());

        $this->expectException(\App\Platforms\Support\PlatformRateLimitedException::class);
        $client->get(['search_terms' => 'b', 'ad_reached_countries' => '["ES"]']); // 2nd over budget
    }
}
