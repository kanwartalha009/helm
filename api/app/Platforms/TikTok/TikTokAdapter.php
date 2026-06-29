<?php

declare(strict_types=1);

namespace App\Platforms\TikTok;

use App\Models\Brand;
use App\Models\PlatformConnection;
use App\Platforms\Contracts\MetricSnapshot;
use App\Platforms\Contracts\PlatformAdapter;
use App\Services\PlatformCredentialService;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

/**
 * TikTok adapter. One Business Center owner token covers every advertiser under
 * the agency BC — no per-brand OAuth. A brand selects advertiser(s) under the BC
 * (mirrors the Meta / Google pickers). Credentials are DB-backed via
 * PlatformCredentialService (tiktok.bc_token).
 */
final class TikTokAdapter implements PlatformAdapter
{
    public function __construct(
        private readonly TikTokClient $client,
        private readonly ReportsFetcher $reports,
        private readonly PlatformCredentialService $credentials,
    ) {}

    public function key(): string
    {
        return 'tiktok';
    }

    public function label(): string
    {
        return 'TikTok Ads';
    }

    public function authUrl(Brand $brand): string
    {
        throw new RuntimeException('TikTok uses a platform-level Business Center token, not per-brand OAuth. Connect an advertiser via attachAccount().');
    }

    /** @param array<string, mixed> $payload */
    public function handleCallback(Brand $brand, array $payload): PlatformConnection
    {
        throw new RuntimeException('TikTok has no per-brand OAuth callback — advertisers are attached via attachAccount().');
    }

    /**
     * Every advertiser under the agency Business Center(s). We discover the BCs
     * the token owns via /bc/get/, then list each BC's advertisers as ASSETS via
     * /bc/asset/get/ with asset_type=ADVERTISER — there is NO /bc/advertiser/get/
     * endpoint (it 404s). bc/asset/get/ returns the advertiser IDs; names +
     * currency are enriched via /advertiser/info/ (best-effort — that needs the
     * Ad Account Management scope, so we fall back to the asset name / ID if the
     * call isn't permitted). Only the BC token is needed (no bc_id config).
     *
     * @return array<int, array{external_id: string, name: string, currency: string}>
     */
    public function listAvailableAccounts(PlatformConnection $conn): array
    {
        // advertiser_id => best-known name from the asset list (may be null).
        $assetNames = [];

        foreach ($this->client->paged('bc/get/') as $bc) {
            $bcId = (string) ($bc['bc_id'] ?? ($bc['bc_info']['bc_id'] ?? ''));
            if ($bcId === '') {
                continue;
            }

            foreach ($this->client->paged('bc/asset/get/', ['bc_id' => $bcId, 'asset_type' => 'ADVERTISER']) as $asset) {
                $id = (string) ($asset['asset_id'] ?? $asset['advertiser_id'] ?? '');
                if ($id === '') {
                    continue;
                }
                // Dedupe across BCs — an advertiser can appear under more than one.
                $assetNames[$id] = $asset['asset_name'] ?? $asset['advertiser_name'] ?? $asset['name'] ?? ($assetNames[$id] ?? null);
            }
        }

        if ($assetNames === []) {
            return [];
        }

        $details = $this->advertiserInfo(array_keys($assetNames));

        $out = [];
        foreach ($assetNames as $id => $assetName) {
            $info = $details[$id] ?? [];
            $out[] = [
                'external_id' => (string) $id,
                'name'        => (string) ($info['name'] ?? $assetName ?? $id),
                'currency'    => (string) ($info['currency'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Names + currency for a set of advertiser IDs via /advertiser/info/ (max 100
     * per call). Best-effort: a missing Ad Account Management scope makes this
     * throw, which we swallow so the picker still lists advertisers by ID.
     *
     * @param array<int, int|string> $advertiserIds
     * @return array<string, array{name: ?string, currency: ?string}>
     */
    private function advertiserInfo(array $advertiserIds): array
    {
        $out = [];
        foreach (array_chunk(array_values($advertiserIds), 100) as $chunk) {
            try {
                $resp = $this->client->get('advertiser/info/', [
                    'advertiser_ids' => json_encode(array_map('strval', $chunk)),
                    'fields'         => json_encode(['advertiser_id', 'name', 'currency']),
                ]);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('tiktok.advertiser_info_failed', ['error' => $e->getMessage()]);
                continue;
            }

            foreach (($resp['list'] ?? []) as $a) {
                $aid = (string) ($a['advertiser_id'] ?? '');
                if ($aid !== '') {
                    $out[$aid] = ['name' => $a['name'] ?? null, 'currency' => $a['currency'] ?? null];
                }
            }
        }

        return $out;
    }

    public function attachAccount(PlatformConnection $conn, string $externalId): void
    {
        $this->attachAccounts($conn, [$externalId]);
    }

    /**
     * Attach one or more advertiser IDs to a brand. Their daily metrics blend
     * into the brand's single TikTok row at sync time (see ReportsFetcher).
     * external_id holds the primary; metadata.advertiser_ids holds the full list.
     * Mirrors Meta/Google adapters.
     *
     * @param array<int, string> $externalIds
     */
    public function attachAccounts(PlatformConnection $conn, array $externalIds): void
    {
        $ids = [];
        foreach ($externalIds as $raw) {
            $id = trim((string) $raw);
            if ($id !== '' && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            throw new RuntimeException('No TikTok advertiser IDs provided to attach.');
        }

        // Look up names + currencies from the BC advertiser list so the dashboard
        // can label and currency-convert without another round-trip at render.
        $available = [];
        foreach ($this->listAvailableAccounts($conn) as $adv) {
            $available[$adv['external_id']] = $adv;
        }

        $names      = [];
        $currencies = [];
        foreach ($ids as $id) {
            $names[$id] = (string) ($available[$id]['name'] ?? $id);
            $ccy = strtoupper((string) ($available[$id]['currency'] ?? ''));
            if ($ccy !== '') {
                $currencies[] = $ccy;
            }
        }
        $currencies = array_values(array_unique($currencies));

        $metadata = $conn->metadata ?? [];
        $metadata['advertiser_ids'] = $ids;
        $metadata['account_names']  = $names;
        $metadata['currencies']     = $currencies;
        $metadata['currency']       = count($currencies) === 1 ? $currencies[0] : 'USD';

        // TikTok carries no per-brand secret — the BC token does the work — but
        // credentials is NOT NULL, so persist an empty encrypted bag.
        $conn->credentials = $conn->credentials ?: [];

        $conn->forceFill([
            'platform'     => 'tiktok',
            'external_id'  => $ids[0],
            'display_name' => count($ids) === 1 ? ($names[$ids[0]] ?? $ids[0]) : count($ids) . ' advertisers',
            'metadata'     => $metadata,
            'status'       => 'active',
            'last_error'   => null,
        ])->save();
    }

    public function fetchDay(PlatformConnection $conn, CarbonImmutable $date): MetricSnapshot
    {
        return $this->reports->fetch($conn, $date);
    }

    public function healthCheck(PlatformConnection $conn): bool
    {
        try {
            // Cheapest call that proves the BC token works.
            $data = $this->client->get('bc/get/', ['page' => 1, 'page_size' => 1]);

            return ! empty($data['list']);
        } catch (Throwable) {
            return false;
        }
    }
}
