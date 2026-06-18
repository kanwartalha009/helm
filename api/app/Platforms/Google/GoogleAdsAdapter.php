<?php

declare(strict_types=1);

namespace App\Platforms\Google;

use App\Models\Brand;
use App\Models\PlatformConnection;
use App\Platforms\Contracts\MetricSnapshot;
use App\Platforms\Contracts\PlatformAdapter;
use App\Services\PlatformCredentialService;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

/**
 * Google Ads adapter. One MCC (manager) OAuth covers every linked client
 * account, so there is no per-brand OAuth — a brand simply selects a customer
 * ID under the MCC (mirrors Meta's ad-account picker). Credentials are
 * DB-backed via PlatformCredentialService (google.*).
 */
final class GoogleAdsAdapter implements PlatformAdapter
{
    public function __construct(
        private readonly GoogleAdsClient $client,
        private readonly ReportsFetcher $reports,
        private readonly PlatformCredentialService $credentials,
    ) {}

    public function key(): string
    {
        return 'google';
    }

    public function label(): string
    {
        return 'Google Ads';
    }

    public function authUrl(Brand $brand): string
    {
        throw new RuntimeException('Google Ads uses a platform-level MCC token, not per-brand OAuth. Connect a customer via attachAccount().');
    }

    /** @param array<string, mixed> $payload */
    public function handleCallback(Brand $brand, array $payload): PlatformConnection
    {
        throw new RuntimeException('Google Ads has no per-brand OAuth callback — accounts are attached via attachAccount().');
    }

    /**
     * Every leaf ad account under the MCC. We enumerate via a customer_client
     * query against the MCC (one call returns the whole tree with name +
     * currency) rather than listAccessibleCustomers, which only returns
     * directly-accessible customers. Manager accounts are filtered out.
     *
     * @return array<int, array{external_id: string, name: string, currency: string}>
     */
    public function listAvailableAccounts(PlatformConnection $conn): array
    {
        $mcc  = $this->mccId();
        $gaql = "SELECT customer_client.id, customer_client.descriptive_name, "
            . "customer_client.currency_code, customer_client.manager "
            . "FROM customer_client WHERE customer_client.status = 'ENABLED'";

        $accounts = [];
        foreach ($this->client->search($mcc, $gaql) as $row) {
            $cc = $row->getCustomerClient();
            if ($cc === null || $cc->getManager()) {
                continue; // skip the MCC itself + any sub-managers; keep leaf accounts
            }
            $id = (string) $cc->getId();
            if ($id === '') {
                continue;
            }
            $accounts[] = [
                'external_id' => $id,
                'name'        => (string) ($cc->getDescriptiveName() ?: $id),
                'currency'    => (string) $cc->getCurrencyCode(),
            ];
        }

        return $accounts;
    }

    public function attachAccount(PlatformConnection $conn, string $externalId): void
    {
        $this->attachAccounts($conn, [$externalId]);
    }

    /**
     * Attach one or more Google customer IDs to a brand. Their daily metrics are
     * blended into the brand's single Google row at sync time (see
     * ReportsFetcher). external_id holds the primary; metadata.customer_ids holds
     * the full list. Mirrors MetaAdapter::attachAccounts.
     *
     * @param array<int, string> $externalIds
     */
    public function attachAccounts(PlatformConnection $conn, array $externalIds): void
    {
        $ids = [];
        foreach ($externalIds as $raw) {
            $id = preg_replace('/\D/', '', (string) $raw) ?? '';
            if ($id !== '' && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            throw new RuntimeException('No Google Ads customer IDs provided to attach.');
        }

        // Look up names + currencies from the MCC client list so the dashboard
        // can label and currency-convert without another round-trip at render.
        $available = [];
        foreach ($this->listAvailableAccounts($conn) as $acct) {
            $available[$acct['external_id']] = $acct;
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
        $metadata['customer_ids']  = $ids;
        $metadata['account_names'] = $names;
        $metadata['currencies']    = $currencies;
        // Informational: blended row currency is the shared one if all accounts
        // agree, else USD (ReportsFetcher converts at fetch time).
        $metadata['currency'] = count($currencies) === 1 ? $currencies[0] : 'USD';

        // Google carries no per-brand secret — the MCC token does the work — but
        // credentials is NOT NULL, so persist an empty encrypted bag.
        $conn->credentials = $conn->credentials ?: [];

        $conn->forceFill([
            'platform'     => 'google',
            'external_id'  => $ids[0],
            'display_name' => count($ids) === 1 ? ($names[$ids[0]] ?? $ids[0]) : count($ids) . ' ad accounts',
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
            // Cheapest call that proves the MCC OAuth + developer token work.
            return $this->client->listAccessibleCustomers() !== [];
        } catch (Throwable) {
            return false;
        }
    }

    /** MCC customer ID (digits only) from the DB-backed credential. */
    private function mccId(): string
    {
        return preg_replace('/\D/', '', $this->credentials->get('google', 'login_customer_id')) ?? '';
    }
}
