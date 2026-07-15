<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\CreativeDraft;
use App\Models\PlatformCredential;
use App\Models\User;
use App\Services\BrandStyleService;
use App\Services\Creative\CreativeBrief;
use App\Services\Llm\AnthropicClient;
use App\Services\Llm\LlmClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GO-5.1 — Creative testing engine (master plan §8). Covers the flagship
 * refusal (unconfirmed style → no generation, no LLM call), grounded generation
 * with a faked LLM, the allowlist boundary (no customer/internal data reaches
 * the model), the draft lifecycle, and RBAC.
 */
class CreativeStudioTest extends TestCase
{
    use RefreshDatabase;

    /** The last fake LLM client bound — so tests can inspect what it received. */
    private ?object $fakeLlm = null;

    private function makeBrand(): Brand
    {
        return Brand::factory()->create(['base_currency' => 'EUR', 'timezone' => 'Europe/Madrid', 'status' => 'active']);
    }

    private function actingMasterAdmin(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));
    }

    private function putLlmKeyOnFile(): void
    {
        (new PlatformCredential())->forceFill([
            'platform' => 'llm', 'key' => 'anthropic_api_key', 'value' => 'test-key-not-real', 'status' => 'active',
        ])->save();
    }

    private function fakeLlm(string $response): void
    {
        $fake = new class($response) implements LlmClient {
            public array $calls = [];
            public function __construct(private readonly string $response) {}
            public function provider(): string { return 'anthropic'; }
            public function model(): string { return 'fake-model-1'; }
            public function complete(string $system, array $messages, ?int $maxTokens = null): string
            {
                $this->calls[] = compact('system', 'messages');
                return $this->response;
            }
        };
        $this->fakeLlm = $fake;
        app()->instance(AnthropicClient::class, $fake);
    }

    private function confirmStyle(Brand $brand): void
    {
        app(BrandStyleService::class)->save($brand, [
            'toneWords' => ['warm', 'premium'],
            'palette'   => [['hex' => '#112233', 'weight' => 0.5]],
            'doDont'    => ['do' => ['use whitespace'], 'dont' => ['shout']],
        ], 1, confirm: true);
    }

    private const GOOD_JSON = '{"copy":[{"headline":"Wrap up warm","body":"Silk that lasts."}],"hooks":["POV: your softest winter","One scarf, every outfit"],"ugcScripts":[{"title":"Unboxing","script":"Open the box, feel the silk, wrap it on."}]}';

    public function test_generate_refuses_when_style_is_not_confirmed(): void
    {
        $this->actingMasterAdmin();
        $brand = $this->makeBrand();
        $this->putLlmKeyOnFile();
        $this->fakeLlm(self::GOOD_JSON);

        // Only a DRAFT style (not confirmed) → must refuse.
        app(BrandStyleService::class)->save($brand, ['toneWords' => ['warm']], 1, confirm: false);

        $this->postJson("/api/brands/{$brand->slug}/creative/generate")
            ->assertStatus(422)
            ->assertJsonPath('reason', 'unconfirmed_style');

        // The refusal must happen BEFORE any model call.
        $this->assertSame([], $this->fakeLlm->calls);
        $this->assertSame(0, CreativeDraft::where('brand_id', $brand->id)->count());
    }

    public function test_generate_with_confirmed_style_persists_grounded_drafts(): void
    {
        $this->actingMasterAdmin();
        $brand = $this->makeBrand();
        $this->putLlmKeyOnFile();
        $this->fakeLlm(self::GOOD_JSON);
        $this->confirmStyle($brand);
        DB::table('product_catalog')->insert([
            'brand_id' => $brand->id, 'handle' => 'silk-scarf', 'title' => 'Silk Scarf', 'product_type' => 'Accessories',
            'variant_count' => 1, 'total_inventory' => 20, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $res = $this->postJson("/api/brands/{$brand->slug}/creative/generate", ['n' => 2])
            ->assertCreated();

        $this->assertSame(4, $res->json('generated')); // 1 copy + 2 hooks + 1 ugc
        $this->assertSame(1, CreativeDraft::where('brand_id', $brand->id)->where('kind', 'copy')->count());
        $this->assertSame(2, CreativeDraft::where('brand_id', $brand->id)->where('kind', 'hook')->count());
        $this->assertSame(1, CreativeDraft::where('brand_id', $brand->id)->where('kind', 'ugc_script')->count());
        // Provenance stored on every draft.
        $this->assertSame('fake-model-1', CreativeDraft::where('brand_id', $brand->id)->first()->model);
    }

    public function test_the_llm_only_ever_sees_allowlisted_fields(): void
    {
        $this->actingMasterAdmin();
        $brand = $this->makeBrand();
        $this->putLlmKeyOnFile();
        $this->fakeLlm(self::GOOD_JSON);
        $this->confirmStyle($brand);
        // A product row carries internal columns (id, brand_id) — none may leak.
        DB::table('product_catalog')->insert([
            'brand_id' => $brand->id, 'handle' => 'silk-scarf', 'title' => 'Silk Scarf', 'product_type' => 'Accessories',
            'variant_count' => 1, 'total_inventory' => 20, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson("/api/brands/{$brand->slug}/creative/generate")->assertCreated();

        $sent = json_encode($this->fakeLlm->calls);
        $this->assertStringNotContainsString('brand_id', $sent);
        $this->assertStringNotContainsString('"id"', $sent);
        $this->assertStringNotContainsString('handle', $sent);
        $this->assertStringNotContainsString('customer', $sent);

        // The brief the model actually received is exactly the whitelist.
        $userMsg = $this->fakeLlm->calls[0]['messages'][0]['content'];
        $brief = json_decode(substr($userMsg, strpos($userMsg, '{')), true);
        $this->assertSame(['name', 'type', 'stock'], array_keys($brief['products'][0]));
        $this->assertEqualsCanonicalizing(
            ['brand', 'tone', 'palette', 'do', 'dont', 'products', 'provenHooks', 'moment', 'currency'],
            array_keys($brief),
        );
    }

    public function test_allowlisted_payload_shape_is_a_strict_whitelist(): void
    {
        $brief = new CreativeBrief(
            brandName: 'Acme',
            toneWords: ['warm'],
            paletteHex: ['#112233'],
            doDont: ['do' => ['x'], 'dont' => ['y']],
            products: [['name' => 'Scarf', 'type' => 'Accessories', 'stock' => 5]],
            hookBenchmarks: [['tag' => 'pov', 'medianRoas' => 2.5, 'medianCtr' => 1.2]],
            momentLabel: 'BFCM',
            currency: 'EUR',
        );

        $payload = $brief->toLlmPayload();
        $this->assertSame(['name', 'type', 'stock'], array_keys($payload['products'][0]));
        $this->assertSame(['tag', 'medianRoas', 'medianCtr'], array_keys($payload['provenHooks'][0]));
    }

    public function test_draft_lifecycle_is_forward_only(): void
    {
        $this->actingMasterAdmin();
        $brand = $this->makeBrand();
        $this->putLlmKeyOnFile();
        $this->fakeLlm(self::GOOD_JSON);
        $this->confirmStyle($brand);
        $this->postJson("/api/brands/{$brand->slug}/creative/generate")->assertCreated();

        $draft = CreativeDraft::where('brand_id', $brand->id)->where('kind', 'copy')->first();

        // Approve + edit content.
        $this->putJson("/api/brands/{$brand->slug}/creative/drafts/{$draft->id}", [
            'status'  => 'approved',
            'content' => ['headline' => 'Edited', 'body' => 'New body'],
        ])->assertOk()->assertJsonPath('draft.status', 'approved');

        // Forward-only: approved → draft is rejected (stays approved).
        $this->putJson("/api/brands/{$brand->slug}/creative/drafts/{$draft->id}", ['status' => 'draft'])->assertOk();
        $this->assertSame('approved', $draft->fresh()->status);
        $this->assertSame('Edited', $draft->fresh()->content['headline']);

        // Discard.
        $this->deleteJson("/api/brands/{$brand->slug}/creative/drafts/{$draft->id}")->assertOk();
        $this->assertNull($draft->fresh());
    }

    public function test_rbac_read_visible_write_admin_only(): void
    {
        $brand = $this->makeBrand();
        $this->confirmStyle($brand);
        $tm = User::factory()->create(['role' => 'team_member']);
        $brand->users()->attach($tm->id);
        Sanctum::actingAs($tm);

        $this->getJson("/api/brands/{$brand->slug}/creative/drafts")->assertOk();
        $this->postJson("/api/brands/{$brand->slug}/creative/generate")->assertForbidden();
    }
}
