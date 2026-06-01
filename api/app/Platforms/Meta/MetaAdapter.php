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
        $this->attachAccounts($conn, [$externalId]);
    }

    /**
     * Attach one or more ad accounts (all under the org Business Manager) to a
     * brand. Their daily metrics are blended into the brand's single Meta row
     * at sync time — see InsightsFetcher. `external_id` holds the primary
     * account; `metadata.ad_account_ids` holds the full selected list.
     *
     * @param array<int, string> $externalIds
     */
    public function attachAccounts(PlatformConnection $conn, array $externalIds): void
    {
        $ids = [];
        foreach ($externalIds as $raw) {
            $id = InsightsFetcher::normalizeAccountId((string) $raw);
            if ($id !== 'act_' && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        if ($ids === []) {
            throw new RuntimeException('No ad accounts provided to attach.');
        }

        // Confirm the System User token can see each account, and capture its
        // name + currency so the dashboard can label and convert it.
        $names      = [];
        $currencies = [];
        foreach ($ids as $id) {
            $account     = $this->client->get($id, ['fields' => 'name,currency,account_status']);
            $names[$id]  = (string) ($account['name'] ?? $id);
            if (! empty($account['currency'])) {
                $currencies[] = strtoupper((string) $account['currency']);
            }
        }
        $currencies = array_values(array_unique($currencies));

        $metadata = $conn->metadata ?? [];
        $metadata['ad_account_ids'] = $ids;
        $metadata['account_names']  = $names;
        $metadata['currencies']     = $currencies;
        // Informational: the blended row's currency is the shared one if every
        // account agrees, else USD (InsightsFetcher converts at fetch time).
        $metadata['currency'] = count($currencies) === 1 ? $currencies[0] : 'USD';

        // Meta carries no per-brand secret — the org System User token does the
        // work — but credentials is NOT NULL, so persist an empty encrypted bag.
        $conn->credentials = $conn->credentials ?: [];

        $conn->forceFill([
            'platform'     => 'meta',
            'external_id'  => $ids[0],
            'display_name' => count($ids) === 1 ? ($names[$ids[0]] ?? $ids[0]) : count($ids) . ' ad accounts',
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
