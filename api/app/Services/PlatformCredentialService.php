<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\PlatformCredential;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The single read/write surface for platform-level credentials. Every
 * adapter calls `get()` here instead of `env()` directly. Writes go through
 * `set()` or `rotate()` which handle audit-log entries.
 *
 * Read precedence: DB row with status='active' first, then env() fallback.
 * The env fallback keeps first-run bootstrapping working before the UI is touched.
 */
class PlatformCredentialService
{
    /**
     * Map of (platform, key) → env variable name for the fallback read.
     * Anything not in this map only resolves from the DB.
     */
    private const ENV_MAP = [
        'shopify' => [
            'partner_app_key'    => 'SHOPIFY_APP_KEY',
            'partner_app_secret' => 'SHOPIFY_APP_SECRET',
            'partner_app_scopes' => 'SHOPIFY_APP_SCOPES',
        ],
        'meta'   => ['system_user_token' => 'META_SYSTEM_USER_TOKEN'],
        'google' => [
            'refresh_token'      => 'GOOGLE_ADS_REFRESH_TOKEN',
            'client_id'          => 'GOOGLE_ADS_CLIENT_ID',
            'client_secret'      => 'GOOGLE_ADS_CLIENT_SECRET',
            'developer_token'    => 'GOOGLE_ADS_DEVELOPER_TOKEN',
            'login_customer_id'  => 'GOOGLE_ADS_LOGIN_CUSTOMER_ID',
        ],
        'tiktok' => ['bc_token' => 'TIKTOK_BC_TOKEN'],
        // D-016 (ratified 2026-07-10): LLM keys live here like any platform
        // credential — Settings UI row first, env fallback for bootstrap.
        'llm' => [
            'anthropic_api_key' => 'HELM_ANTHROPIC_API_KEY',
            'openai_api_key'    => 'HELM_OPENAI_API_KEY',
        ],
    ];

    /**
     * Sensible defaults for keys the operator does not need to type — the
     * service still resolves DB / env first, but falls back to these when both
     * are empty. Shopify scopes are the Helm canonical set per spec §05.
     */
    private const DEFAULTS = [
        'shopify' => [
            'partner_app_scopes' => 'read_orders,read_products,read_customers,read_inventory,read_analytics,read_reports',
        ],
    ];

    public function get(string $platform, string $key): string
    {
        $row = PlatformCredential::query()
            ->where('platform', $platform)
            ->where('key', $key)
            ->where('status', 'active')
            ->first();

        if ($row) {
            // Touch last_used_at without firing model events / audit log.
            DB::table('platform_credentials')
                ->where('id', $row->id)
                ->update(['last_used_at' => now()]);
            return $row->value;
        }

        $envKey = self::ENV_MAP[$platform][$key] ?? null;
        $envValue = $envKey ? env($envKey) : null;

        if ($envValue) {
            return $envValue;
        }

        $default = self::DEFAULTS[$platform][$key] ?? null;
        if ($default !== null) {
            return $default;
        }

        throw new RuntimeException(
            "Missing platform credential: {$platform}.{$key}. "
            . 'Add it at Settings → Platform keys.'
        );
    }

    public function has(string $platform, string $key): bool
    {
        if (PlatformCredential::query()
            ->where('platform', $platform)
            ->where('key', $key)
            ->where('status', 'active')
            ->exists()
        ) {
            return true;
        }

        $envKey = self::ENV_MAP[$platform][$key] ?? null;
        if ($envKey && env($envKey)) {
            return true;
        }

        // Keys with a baked-in default count as "present" — operators don't
        // need to fill them. Shopify OAuth scopes are the canonical example.
        return isset(self::DEFAULTS[$platform][$key]);
    }

    public function set(string $platform, string $key, string $value, ?string $label = null, ?array $metadata = null): PlatformCredential
    {
        return DB::transaction(function () use ($platform, $key, $value, $label, $metadata) {
            // Flip any existing active row to 'rotated' first.
            $existing = PlatformCredential::query()
                ->where('platform', $platform)
                ->where('key', $key)
                ->where('status', 'active')
                ->first();

            $created = PlatformCredential::create([
                'platform'           => $platform,
                'key'                => $key,
                'value'              => $value,
                'label'              => $label,
                'metadata'           => $metadata,
                'status'             => 'active',
                'created_by_user_id' => Auth::id(),
            ]);

            if ($existing) {
                $existing->update(['status' => 'rotated']);
                $this->writeAudit('credential.rotated', $platform, $key, $existing->id);
            } else {
                $this->writeAudit('credential.created', $platform, $key, $created->id);
            }

            return $created;
        });
    }

    public function revoke(int $credentialId): void
    {
        $row = PlatformCredential::findOrFail($credentialId);
        $row->update(['status' => 'revoked']);
        $this->writeAudit('credential.revoked', $row->platform, $row->key, $row->id);
    }

    private function writeAudit(string $action, string $platform, string $key, int $credentialId): void
    {
        AuditLog::create([
            'actor_user_id' => Auth::id(),
            'action'        => $action,
            'target_type'   => 'platform_credential',
            'target_id'     => $credentialId,
            'metadata'      => ['platform' => $platform, 'key' => $key],
            'ip'            => request()?->ip(),
            'user_agent'    => request()?->userAgent(),
        ]);
    }

    /** Schema of expected keys per platform — used by the UI to render the right fields. */
    public function schema(): array
    {
        return [
            'shopify' => [
                ['key' => 'partner_app_key',    'label' => 'API key',    'sensitive' => false],
                ['key' => 'partner_app_secret', 'label' => 'API secret', 'sensitive' => true],
            ],
            'meta' => [
                ['key' => 'system_user_token', 'label' => 'System User token', 'sensitive' => true],
            ],
            'google' => [
                ['key' => 'refresh_token',      'label' => 'Refresh token',         'sensitive' => true],
                ['key' => 'client_id',          'label' => 'OAuth client ID',       'sensitive' => false],
                ['key' => 'client_secret',      'label' => 'OAuth client secret',   'sensitive' => true],
                ['key' => 'developer_token',    'label' => 'Developer token',       'sensitive' => true],
                ['key' => 'login_customer_id',  'label' => 'MCC customer ID',       'sensitive' => false],
            ],
            'tiktok' => [
                ['key' => 'bc_token', 'label' => 'Business Center access token', 'sensitive' => true],
            ],
            // AI / LLM (D-016). Paste the key for the provider selected via
            // HELM_LLM_PROVIDER (default anthropic); the other key is optional.
            'llm' => [
                ['key' => 'anthropic_api_key', 'label' => 'Anthropic API key', 'sensitive' => true],
                ['key' => 'openai_api_key',    'label' => 'OpenAI API key',    'sensitive' => true],
            ],
        ];
    }

    /**
     * Run a live ping against the platform with the currently-active credentials.
     * Each adapter implements a healthCheck() that does the lightest call possible
     * (e.g. Meta: GET /me, Google: customers:listAccessibleCustomers, etc.).
     */
    public function test(string $platform): array
    {
        $schema = $this->schema();
        if (! isset($schema[$platform])) {
            return [
                'ok'      => false,
                'message' => "Unknown platform: {$platform}",
            ];
        }

        // LLM: one key (the active provider's) is enough, and the live test
        // is a completion ping, not an adapter healthCheck.
        if ($platform === 'llm') {
            $ping = app(\App\Services\Llm\LlmManager::class)->ping();

            return ['ok' => $ping['ok'], 'message' => $ping['message']];
        }

        // Check every expected key has a value.
        $missing = [];
        foreach ($schema[$platform] as $item) {
            if (! $this->has($platform, $item['key'])) {
                $missing[] = $item['key'];
            }
        }
        if ($missing) {
            return [
                'ok'      => false,
                'message' => 'Missing keys: ' . implode(', ', $missing),
            ];
        }

        // Shopify is per-brand. The partner-app key/secret can't be pinged on
        // their own — they're only valid in the context of an OAuth install.
        // If a real connection exists, ping that. Otherwise the test reports
        // "ready to install" so the operator doesn't see a misleading failure.
        if ($platform === 'shopify') {
            return $this->testShopify();
        }

        try {
            // Resolve the adapter from the registry. The adapter reads credentials
            // through this service so we don't pass anything in.
            /** @var \App\Platforms\PlatformRegistry $registry */
            $registry = app(\App\Platforms\PlatformRegistry::class);
            $adapter  = $registry->for($platform);

            // healthCheck() expects a PlatformConnection for per-brand platforms.
            // For platform-level checks (Meta BM, Google MCC, TikTok BC), we pass a
            // synthetic connection with platform set so the adapter can route.
            $synthetic = new \App\Models\PlatformConnection(['platform' => $platform]);
            $ok = $adapter->healthCheck($synthetic);

            return [
                'ok'      => (bool) $ok,
                'message' => $ok ? 'Connection successful.' : 'Health check returned false.',
            ];
        } catch (\Throwable $e) {
            return [
                'ok'      => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Shopify-specific test. If at least one brand has an active connection,
     * ping that shop to confirm key+secret are valid end-to-end. Otherwise,
     * just validate the shape of the partner-app key.
     */
    private function testShopify(): array
    {
        $key    = $this->get('shopify', 'partner_app_key');
        $secret = $this->get('shopify', 'partner_app_secret');

        // Shopify partner-app keys/secrets are 32-char hex. Reject obviously
        // malformed values before we let the operator hit Connect.
        if (! preg_match('/^[a-f0-9]{32}$/i', $key)) {
            return [
                'ok'      => false,
                'message' => 'API key looks malformed — expected a 32-character hex string from your Partner app.',
            ];
        }
        if (! preg_match('/^[a-f0-9]{32,64}$/i', $secret)) {
            return [
                'ok'      => false,
                'message' => 'API secret looks malformed — expected a hex string from your Partner app.',
            ];
        }

        $activeConn = \App\Models\PlatformConnection::query()
            ->where('platform', 'shopify')
            ->where('status', 'active')
            ->whereNotNull('credentials')
            ->first();

        if (! $activeConn) {
            return [
                'ok'      => true,
                'message' => 'Credentials configured. Install a store to verify end-to-end.',
            ];
        }

        try {
            /** @var \App\Platforms\PlatformRegistry $registry */
            $registry = app(\App\Platforms\PlatformRegistry::class);
            $adapter  = $registry->for('shopify');
            $ok = $adapter->healthCheck($activeConn);

            return [
                'ok'      => (bool) $ok,
                'message' => $ok
                    ? "Live ping to {$activeConn->external_id} succeeded."
                    : "Live ping to {$activeConn->external_id} failed — token may be revoked.",
            ];
        } catch (\Throwable $e) {
            return [
                'ok'      => false,
                'message' => $e->getMessage(),
            ];
        }
    }
}
