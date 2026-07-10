<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\PlatformCredential;
use App\Models\ReportNarrative;
use App\Models\User;
use App\Reports\Contracts\ReportFilters;
use App\Services\Llm\AnthropicClient;
use App\Services\Llm\BrandDataScope;
use App\Services\Llm\LlmClient;
use App\Services\Llm\LlmException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The D-016 LLM layer: narrative generation/editing, chat, the role gate,
 * key-missing behavior, and — most importantly — the privacy boundary of
 * BrandDataScope (aggregates only, forever).
 */
final class LlmLayerTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();
        $this->brand = Brand::factory()->create(['timezone' => 'UTC', 'base_currency' => 'EUR', 'status' => 'active']);
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['role' => 'master_admin']);
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function putKeyOnFile(): void
    {
        (new PlatformCredential())->forceFill([
            'platform' => 'llm',
            'key'      => 'anthropic_api_key',
            'value'    => 'test-key-not-real',
            'status'   => 'active',
        ])->save();
    }

    private function fakeLlm(string|\Closure $response): void
    {
        $fake = new class($response) implements LlmClient {
            public array $calls = [];

            public function __construct(private readonly string|\Closure $response)
            {
            }

            public function provider(): string
            {
                return 'anthropic';
            }

            public function model(): string
            {
                return 'fake-model-1';
            }

            public function complete(string $system, array $messages, ?int $maxTokens = null): string
            {
                $this->calls[] = compact('system', 'messages');
                $r = $this->response;

                return is_string($r) ? $r : $r($system, $messages);
            }
        };

        app()->instance(AnthropicClient::class, $fake);
    }

    private const GOOD_DRAFT = '{"observations":"- Revenue held steady.","actions":"- Shift budget to the winner.","plan":"- Week 1: pause losers.","ideas":"- Test a bundle offer."}';

    /* ---- narrative -------------------------------------------------- */

    public function test_generate_narrative_stores_draft_and_returns_blocks(): void
    {
        $this->actingAsAdmin();
        $this->putKeyOnFile();
        $this->fakeLlm(self::GOOD_DRAFT);

        $res = $this->postJson("/api/brands/{$this->brand->slug}/reports/overall-performance/narrative", [
            'period' => 'last30', 'compare' => 'previous',
        ])->assertCreated();

        $res->assertJsonPath('narrative.blocks.observations', '- Revenue held steady.')
            ->assertJsonPath('narrative.isEdited', false)
            ->assertJsonPath('narrative.provider', 'anthropic')
            ->assertJsonPath('narrative.model', 'fake-model-1');

        $this->assertDatabaseHas('report_narratives', [
            'brand_id'    => $this->brand->id,
            'report_type' => 'overall-performance',
            'period_key'  => 'last30|previous',
        ]);
    }

    public function test_generate_without_key_is_422_not_500(): void
    {
        $this->actingAsAdmin();

        $this->postJson("/api/brands/{$this->brand->slug}/reports/overall-performance/narrative", [])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains((string) $m, 'No LLM key'));
    }

    public function test_malformed_model_output_is_502_with_message(): void
    {
        $this->actingAsAdmin();
        $this->putKeyOnFile();
        $this->fakeLlm('sorry, I cannot help with that');

        $this->postJson("/api/brands/{$this->brand->slug}/reports/overall-performance/narrative", [])
            ->assertStatus(502)
            ->assertJsonPath('message', fn ($m) => str_contains((string) $m, 'regenerate'));
    }

    public function test_edits_win_over_draft_and_survive_in_report_payload(): void
    {
        $this->actingAsAdmin();
        $this->putKeyOnFile();
        $this->fakeLlm(self::GOOD_DRAFT);

        $this->postJson("/api/brands/{$this->brand->slug}/reports/overall-performance/narrative", [])->assertCreated();

        $edited = [
            'observations' => 'Bosco rewrote this.',
            'actions'      => '- Keep budget flat.',
            'plan'         => '- Do nothing rash.',
            'ideas'        => '- Ask the client first.',
        ];
        $this->patchJson("/api/brands/{$this->brand->slug}/reports/overall-performance/narrative", [
            'blocks' => $edited,
        ])->assertOk()->assertJsonPath('narrative.isEdited', true);

        // The report payload carries the EDITED copy (draft still available).
        $this->getJson("/api/brands/{$this->brand->slug}/reports/overall-performance?period=last30&compare=previous")
            ->assertOk()
            ->assertJsonPath('narrative.blocks.observations', 'Bosco rewrote this.')
            ->assertJsonPath('narrative.draftBlocks.observations', '- Revenue held steady.')
            ->assertJsonPath('llm.enabled', true);

        // Regenerating resets the edits — a fresh draft is a fresh review.
        $this->postJson("/api/brands/{$this->brand->slug}/reports/overall-performance/narrative", [])->assertCreated();
        $this->assertNull(ReportNarrative::sole()->edited_blocks);
    }

    public function test_narrative_and_chat_are_admin_manager_only(): void
    {
        // A team_member WITH access to the brand — so the brand resolves
        // (the global scope would 404 an unassigned user before the role
        // gate even runs) and the 403 below is genuinely the role middleware.
        $tm = User::factory()->create(['role' => 'team_member']);
        $this->brand->users()->attach($tm->id);
        Sanctum::actingAs($tm);
        $this->putKeyOnFile();
        $this->fakeLlm(self::GOOD_DRAFT);

        $this->postJson("/api/brands/{$this->brand->slug}/reports/overall-performance/narrative", [])
            ->assertForbidden();
        $this->postJson("/api/brands/{$this->brand->slug}/ask", ['message' => 'How did we do?'])
            ->assertForbidden();
    }

    /* ---- chat -------------------------------------------------------- */

    public function test_chat_answers_from_scoped_data_with_history(): void
    {
        $this->actingAsAdmin();
        $this->putKeyOnFile();
        $this->fakeLlm(fn (string $system, array $messages) => 'You asked: ' . end($messages)['content']);

        $res = $this->postJson("/api/brands/{$this->brand->slug}/ask", [
            'message' => 'What was revenue?',
            'period'  => 'last7',
            'history' => [
                ['role' => 'user', 'content' => 'hi'],
                ['role' => 'assistant', 'content' => 'hello'],
            ],
        ])->assertOk();

        $res->assertJsonPath('reply', 'You asked: What was revenue?')
            ->assertJsonPath('provider', 'anthropic');
    }

    /* ---- privacy boundary -------------------------------------------- */

    public function test_scope_payload_is_aggregates_only(): void
    {
        // Seed one complete day so the payload has data flowing through it.
        DB::table('daily_metrics')->insert([
            'brand_id' => $this->brand->id, 'platform' => 'shopify',
            'date' => now('UTC')->subDay()->toDateString(),
            'total_sales' => 100, 'orders' => 3, 'refunds_amount' => 5,
            'currency' => 'EUR', 'fx_rate_to_usd' => 1.1, 'is_complete' => true, 'pulled_at' => now(),
        ]);

        $payload = app(BrandDataScope::class)->build(
            $this->brand,
            ReportFilters::fromArray(['period' => 'last30', 'compare' => 'previous']),
        );

        // Top-level surface is a fixed allowlist — widening it must fail here
        // and force a conscious D-016 privacy review.
        $this->assertSame(
            ['brand', 'period', 'totals', 'priorTotals', 'dailySeries', 'topProducts', 'topCountries', 'topCategories', 'adsAudit', 'deadInventory'],
            array_keys($payload),
        );
        // The brand block carries identity only — no tokens, ids, or domains.
        $this->assertSame(['name', 'currency', 'timezone'], array_keys($payload['brand']));

        // Nothing credential- or customer-shaped anywhere in the payload.
        $json = strtolower((string) json_encode($payload));
        foreach (['token', 'secret', 'api_key', 'email', 'password', 'customer_', 'address'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json, "forbidden substring: {$forbidden}");
        }
    }

    /* ---- deep-analytics pages ---------------------------------------- */

    public function test_products_endpoint_aggregates_shares_and_deltas(): void
    {
        $this->actingAsAdmin();
        $y = now('UTC')->subDay()->toDateString();
        $inPrior = now('UTC')->subDays(35)->toDateString();

        $row = fn (string $date, string $key, float $total, float $refunds = 0) => [
            'brand_id' => $this->brand->id, 'date' => $date,
            'dimension_type' => 'product', 'dimension_key' => $key, 'dimension_label' => strtoupper($key),
            'orders' => 2, 'units' => 4, 'total_sales' => $total, 'refunds_amount' => $refunds,
            'currency' => 'EUR', 'is_complete' => true, 'pulled_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ];
        DB::table('commerce_daily_metrics')->insert([
            $row($y, 'ring', 300, 30),
            $row($y, 'necklace', 100),
            $row($inPrior, 'ring', 150),
        ]);

        $res = $this->getJson("/api/brands/{$this->brand->slug}/products?period=last30")->assertOk();

        $res->assertJsonPath('hasData', true)
            ->assertJsonPath('rows.0.key', 'ring')
            ->assertJsonPath('rows.0.revenue', 330)     // 300 + 30 refunds added back
            ->assertJsonPath('rows.0.refundRatePct', 9.1)
            ->assertJsonPath('rows.0.prevRevenue', 150)
            ->assertJsonPath('rows.0.deltaPct', 120)
            ->assertJsonPath('rows.1.key', 'necklace')
            ->assertJsonPath('rows.1.prevRevenue', null)
            ->assertJsonPath('totalRevenue', 430);

        // share: 330 / 430
        $this->assertEqualsWithDelta(76.7, $res->json('rows.0.sharePct'), 0.11);

        $this->getJson("/api/brands/{$this->brand->slug}/products?period=last30&search=neck")
            ->assertOk()->assertJsonCount(1, 'rows');
    }

    public function test_audit_findings_compose_rules_engines_only(): void
    {
        $this->actingAsAdmin();

        // No data at all → the critical no-data finding.
        $this->getJson("/api/brands/{$this->brand->slug}/audit-findings")
            ->assertOk()
            ->assertJsonPath('findings.0.severity', 'critical')
            ->assertJsonPath('findings.0.area', 'data');

        // Fresh complete data + dead stock → freshness clears, inventory flags.
        DB::table('daily_metrics')->insert([
            'brand_id' => $this->brand->id, 'platform' => 'shopify',
            'date' => now('UTC')->subDay()->toDateString(),
            'total_sales' => 500, 'orders' => 9, 'refunds_amount' => 0,
            'currency' => 'EUR', 'fx_rate_to_usd' => 1.1, 'is_complete' => true, 'pulled_at' => now(),
        ]);
        DB::table('inventory_snapshots')->insert([
            'brand_id' => $this->brand->id, 'captured_on' => now('UTC')->toDateString(),
            'dimension_type' => 'product', 'dimension_key' => 'dusty-ring', 'dimension_label' => 'Dusty ring',
            'ending_units' => 40, 'units_sold' => 0, 'window_days' => 90, 'pulled_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $res  = $this->getJson("/api/brands/{$this->brand->slug}/audit-findings")->assertOk();
        $byArea = collect($res->json('findings'))->groupBy('area');

        $this->assertFalse(isset($byArea['data'][0]) && $byArea['data'][0]['severity'] === 'critical', 'freshness should be clear');
        $this->assertTrue(isset($byArea['inventory']), 'dead stock must surface');
        $this->assertStringContainsString('zero sales', $byArea['inventory'][0]['title']);
    }
}
