<?php

declare(strict_types=1);

namespace App\Platforms\Meta;

use App\Models\Brand;
use App\Models\PlatformConnection;
use App\Platforms\Contracts\MetricSnapshot;
use App\Platforms\Contracts\PlatformAdapter;
use App\Services\PlatformCredentialService;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

/**
 * Meta adapter. Uses one platform-level System User token that covers every
 * ad account under the agency's Business Manager.
 *
 * Reads `PlatformCredentialService::get('meta', 'system_user_token')` at call time.
 */
final class MetaAdapter implements PlatformAdapter
{
    public function __construct(
        private readonly MetaClient $client,
        private readonly InsightsFetcher $insights,
        private readonly PlatformCredentialService $credentials,
    ) {}

    public function key(): string
    {
        return 'meta';
    }

    public function label(): string
    {
        return 'Meta Ads';
    }

    public function authUrl(Brand $brand): string
    {
        // Meta is platform-level — no per-brand OAuth. The agency picks an ad
        // account via listAvailableAccounts() / attachAccount() instead.
        throw new RuntimeException('Meta uses a platform-level System User token, not per-brand OAuth. Connect an account via attachAccount().');
    }

    /** @param array<string, mixed> $payload */
    public function handleCallback(Brand $brand, array $payload): PlatformConnection
    {
        throw new RuntimeException('Meta has no OAuth callback — accounts are attached via attachAccount().');
    }

    /**
     * Every ad account the System User token can see. The token is granted
     * access to accounts as they are added to the agency Business Manager,
     * so me/adaccounts returns exactly the attachable set.
     *
     * @return array<int, array{external_id: string, name: string, currency: string}>
     */
    public function listAvailableAccounts(PlatformConnection $conn): array
    {
        $rows = $this->client->paged('me/adaccounts', [
            'fields' => 'account_id,name,currency,account_status',
            'limit'  => 200,
        ]);

        $accounts = [];
        foreach ($rows as $a) {
            $externalId = isset($a['id']) && is_string($a['id']) && $a['id'] !== ''
                ? $a['id']
                : InsightsFetcher::normalizeAccountId((string) ($a['account_id'] ?? ''));

            $accounts[] = [
                'external_id' => $externalId,
                'name'        => (string) ($a['name'] ?? $externalId),
                'currency'    => (string) ($a['currency'] ?? ''),
            ];
        }

        return $accounts;
    }

    public function attachAccount(PlatformConnection $conn, string $externalId): void
    {
        $accountId = InsightsFetcher::normalizeAccountId($externalId);

        // Confirm the token can actually see this account, and capture its
        // name + currency so the dashboard can label it and convert to USD.
        $account = $this->client->get($accountId, ['fields' => 'name,currency,account_status']);

        $metadata = $conn->metadata ?? [];
        $metadata['currency']       = (string) ($account['currency'] ?? ($metadata['currency'] ?? ''));
        $metadata['account_status'] = $account['account_status'] ?? null;

        $conn->forceFill([
            'platform'     => 'meta',
            'external_id'  => $accountId,
            'display_name' => (string) ($account['name'] ?? $accountId),
            'metadata'     => $metadata,
            'status'       => 'active',
            'last_error'   => null,
        ])->save();
    }

    public function fetchDay(PlatformConnection $conn, CarbonImmutable $date): MetricSnapshot
    {
        return $this->insights->fetch($conn, $date);
    }

    public function healthCheck(PlatformConnection $conn): bool
    {
        try {
            // Cheapest call that proves the System User token still works.
            $me = $this->client->get('me', ['fields' => 'id']);

            return ! empty($me['id']);
        } catch (Throwable) {
            return false;
        }
    }
}
