<?php

declare(strict_types=1);

namespace App\Platforms\Shopify;

use App\Models\PlatformConnection;
use App\Support\LandingPathMapper;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Sessions by traffic type, per landing entity, per day (Bosco item B — probe verdict B1).
 *
 * ShopifyQL:
 *     FROM sessions SHOW sessions GROUP BY landing_page_path, traffic_type
 *     ORDER BY sessions DESC LIMIT <n> OFFSET <n> SINCE <day> UNTIL <day>
 *
 * ══ THE TWO THINGS THAT MAKE THIS HONEST ══
 *
 * 1. IT PAGES, IT DOES NOT CAP. The spec proposed "top-N landing paths per day, log a
 *    truncation note". Measured on a real store: capping product rows at 300 captured only
 *    74.3% of product-page sessions, and every one of the missing 4,393 sat in the TAIL —
 *    i.e. the low-traffic products an inventory tool exists to surface. A cap doesn't lose
 *    data at random; it loses exactly the data Bosco is looking for. The probe proved OFFSET
 *    works, so this pages until a short page comes back and takes the whole day.
 *
 * 2. IT RECONCILES, AND FAILS CLOSED IF IT CAN'T. Every day is checked against a second,
 *    cheap ShopifyQL call (`GROUP BY traffic_type`, 4 rows) that returns the store's own
 *    total. If the paged sum doesn't match, the day is written `is_complete = false` and every
 *    read surface renders "—". A short row set that LOOKS complete is the failure mode that
 *    matters here: it would quietly under-report a product's sessions, and nobody would know.
 *    Missing is missing; it is never zero, and never a number we can't stand behind.
 *
 * Rows come back with the landing path already resolved to a product / collection / 'other'
 * (LandingPathMapper) and summed, because raw landing paths have unbounded cardinality — see
 * the migration.
 */
class SessionTrafficFetcher
{
    /** The API version that exposes `shopifyqlQuery` — same as RevenueFetcher. */
    private const SHOPIFYQL_API_VERSION = '2026-04';

    /** Rows per page. ShopifyQL honours LIMIT + OFFSET (probe-verified 2026-07-12). */
    private const PAGE_SIZE = 1000;

    /**
     * Hard stop so a pathological store cannot spin forever. 25 pages × 1000 = 25,000 rows/day;
     * the busiest brand measured produced under 5,000. Hitting this ceiling is NOT treated as
     * success — the day fails reconciliation and is stored incomplete.
     */
    private const MAX_PAGES = 25;

    /**
     * Shopify's traffic types, verified against a full YEAR of a real store (2026-07-12):
     * paid 3,117,263 · direct 2,599,142 · unknown 757,967 · organic 457,105 · unattributed 7.
     * They sum EXACTLY to the store total (6,931,484).
     *
     * `unattributed` is 0.0001% of traffic and does not appear in a 30-day sample at all —
     * which is precisely why it must be in a named constant rather than inferred from a probe.
     */
    public const TRAFFIC_TYPES = ['paid', 'direct', 'organic', 'unknown', 'unattributed'];

    /**
     * One day of session/traffic-type rows for a brand, aggregated by landing entity.
     *
     * @return array{rows: array<int, array<string, mixed>>, isComplete: bool, storeTotal: int|null, pagedTotal: int}
     */
    public function fetchDay(PlatformConnection $conn, string $day): array
    {
        $client = $this->makeClient($conn);

        // The truth we reconcile against: Shopify's own store-level split for the day.
        // Four rows, one cheap call. Null = we could not establish a truth → fail closed.
        $storeTotal = $this->storeTotalForDay($client, $day);

        $raw        = [];
        $pagedTotal = 0;
        $offset     = 0;
        $truncated  = false;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $rows = $this->page($client, $day, $offset);

            if ($rows === null) {
                // Transport/parse failure mid-page: we cannot know what we missed.
                return $this->incomplete($raw, $storeTotal, $pagedTotal);
            }

            foreach ($rows as $r) {
                $raw[]       = $r;
                $pagedTotal += $r['sessions'];
            }

            if (count($rows) < self::PAGE_SIZE) {
                break;             // short page = the tail. Done, nothing left behind.
            }

            $offset += self::PAGE_SIZE;

            if ($page === self::MAX_PAGES - 1) {
                $truncated = true; // we stopped early — say so, don't pretend.
            }
        }

        if ($truncated) {
            Log::warning('shopify.session_traffic.page_ceiling_hit', [
                'brand_id' => $conn->brand_id,
                'date'     => $day,
                'rows'     => count($raw),
            ]);

            return $this->incomplete($raw, $storeTotal, $pagedTotal);
        }

        // Reconciliation. Sessions are integers straight from Shopify, so this is an exact
        // equality — no tolerance window, because any gap means rows are missing.
        $isComplete = $storeTotal !== null && $storeTotal === $pagedTotal;

        if (! $isComplete) {
            Log::warning('shopify.session_traffic.reconcile_failed', [
                'brand_id'    => $conn->brand_id,
                'date'        => $day,
                'store_total' => $storeTotal,
                'paged_total' => $pagedTotal,
            ]);
        }

        // A traffic type we don't know about would be counted in `pagedTotal` (so it still
        // reconciles) but would have nowhere to go in the read model — it would be lost
        // SILENTLY, with the day looking green. `unattributed` was exactly that bug: 7 sessions
        // a year, invisible in a 30-day probe. So a sixth type fails the day loudly instead.
        $unknownTypes = [];
        foreach ($raw as $r) {
            $t = strtolower(trim((string) $r['traffic_type']));
            if ($t !== '' && ! in_array($t, self::TRAFFIC_TYPES, true)) {
                $unknownTypes[$t] = true;
            }
        }
        if ($unknownTypes !== []) {
            Log::warning('shopify.session_traffic.unknown_traffic_type', [
                'brand_id' => $conn->brand_id,
                'date'     => $day,
                'types'    => array_keys($unknownTypes),
            ]);
            $isComplete = false;
        }

        return [
            'rows'       => $this->aggregate($raw, $isComplete),
            'isComplete' => $isComplete,
            'storeTotal' => $storeTotal,
            'pagedTotal' => $pagedTotal,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $raw
     * @return array{rows: array<int, array<string, mixed>>, isComplete: bool, storeTotal: int|null, pagedTotal: int}
     */
    private function incomplete(array $raw, ?int $storeTotal, int $pagedTotal): array
    {
        return [
            'rows'       => $this->aggregate($raw, false),
            'isComplete' => false,
            'storeTotal' => $storeTotal,
            'pagedTotal' => $pagedTotal,
        ];
    }

    /**
     * Resolve each landing path to its entity and SUM. This is where /es/products/jay,
     * /fr/products/jay and /collections/best-sellers/products/jay all become one `jay` row —
     * and where thousands of one-off checkout URLs collapse into a single 'store-wide' row per
     * traffic type. Nothing is discarded: the unmapped tail lands in 'other', so
     * Σ(rows) still equals the store total.
     *
     * @param array<int, array<string, mixed>> $raw
     * @return array<int, array<string, mixed>>
     */
    private function aggregate(array $raw, bool $isComplete): array
    {
        /** @var array<string, array<string, mixed>> $bucket */
        $bucket = [];

        foreach ($raw as $r) {
            $path = (string) $r['path'];
            $type = $this->normaliseTrafficType((string) $r['traffic_type']);
            if ($type === null) {
                continue;
            }

            $entity = LandingPathMapper::resolve($path);
            $key    = $entity['type'] . "\0" . $entity['key'] . "\0" . $type;

            if (! isset($bucket[$key])) {
                $bucket[$key] = [
                    'entity_type'  => $entity['type'],
                    'entity_key'   => $entity['key'],
                    'traffic_type' => $type,
                    'sessions'     => 0,
                    'is_complete'  => $isComplete,
                ];
            }

            $bucket[$key]['sessions'] += (int) $r['sessions'];
        }

        return array_values($bucket);
    }

    /**
     * Shopify's five traffic types: paid, direct, organic, unknown, unattributed.
     *
     * `unattributed` is REAL but vanishingly rare — 7 sessions in a full year on a store doing
     * 6.9M (0.0001%). A 30-day probe does not see it, which is exactly how it got left out of
     * the first cut of this file. Rare is not absent: dropping it would silently break the
     * invariant this whole class exists to defend (Σ rows = the store's own total), and the
     * reconciliation check would NOT have caught it, because `pagedTotal` is summed from the
     * raw rows BEFORE this filter runs. A row we cannot classify is a row we lose.
     *
     * An unrecognised value now poisons the day deliberately: it is returned as-is, fails the
     * allowlist at write time, and the day is marked incomplete — the right outcome for
     * "Shopify added a sixth type under us".
     *
     * @return string|null null ONLY for a genuinely empty value
     */
    private function normaliseTrafficType(string $type): ?string
    {
        $t = strtolower(trim($type));

        return $t === '' ? null : $t;
    }

    /**
     * The store's own session total for the day, from the 4-row traffic-type split. This is
     * the number the paged rows must add up to. Null on any failure — a reconciliation we
     * cannot perform is a reconciliation that FAILED.
     */
    private function storeTotalForDay(ShopifyClient $client, string $day): ?int
    {
        $ql = 'FROM sessions SHOW sessions GROUP BY traffic_type '
            . "SINCE {$day} UNTIL {$day} LIMIT 50";

        $table = $this->runQuery($client, $ql);
        if ($table === null) {
            return null;
        }

        [$columns, $rows] = $table;
        $idx = $this->columnIndex($columns, 'sessions');
        if ($idx === null) {
            return null;
        }

        $total = 0;
        foreach ($rows as $row) {
            $total += (int) ($row[$idx] ?? 0);
        }

        return $total;
    }

    /**
     * One page of (landing_page_path, traffic_type, sessions). NULL on failure — the caller
     * must treat that as "the day is incomplete", never as "the page was empty".
     *
     * @return array<int, array{path: string, traffic_type: string, sessions: int}>|null
     */
    private function page(ShopifyClient $client, string $day, int $offset): ?array
    {
        $ql = 'FROM sessions SHOW sessions GROUP BY landing_page_path, traffic_type '
            . 'ORDER BY sessions DESC '
            . 'LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . $offset . ' '
            . "SINCE {$day} UNTIL {$day}";

        $table = $this->runQuery($client, $ql);
        if ($table === null) {
            return null;
        }

        [$columns, $rows] = $table;

        $pathIdx = $this->columnIndex($columns, 'landing_page_path');
        $typeIdx = $this->columnIndex($columns, 'traffic_type');
        $sessIdx = $this->columnIndex($columns, 'sessions');

        if ($pathIdx === null || $typeIdx === null || $sessIdx === null) {
            // An empty page legitimately returns no columns (probe-observed at OFFSET past the
            // end). Distinguish that from a malformed response by checking the row set.
            return $rows === [] ? [] : null;
        }

        $out = [];
        foreach ($rows as $row) {
            $path = (string) ($row[$pathIdx] ?? '');
            if ($path === '') {
                continue;
            }
            $out[] = [
                'path'         => $path,
                'traffic_type' => (string) ($row[$typeIdx] ?? ''),
                'sessions'     => (int) ($row[$sessIdx] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * @param array<int, mixed> $columns
     */
    private function columnIndex(array $columns, string $name): ?int
    {
        foreach ($columns as $i => $c) {
            if ((string) (is_array($c) ? ($c['name'] ?? '') : '') === $name) {
                return (int) $i;
            }
        }

        return null;
    }

    /**
     * @return array{0: array<int, mixed>, 1: array<int, mixed>}|null
     */
    private function runQuery(ShopifyClient $client, string $ql): ?array
    {
        $gql = <<<'GQL'
query ($q: String!) {
  shopifyqlQuery(query: $q) {
    tableData { columns { name } rows }
    parseErrors
  }
}
GQL;

        try {
            $data = $client->graphql($gql, ['q' => $ql], self::SHOPIFYQL_API_VERSION);
        } catch (Throwable $e) {
            Log::warning('shopify.session_traffic.request_failed', ['error' => $e->getMessage(), 'ql' => $ql]);

            return null;
        }

        $resp = $data['shopifyqlQuery'] ?? null;
        if (! is_array($resp)) {
            return null;
        }
        if (! empty($resp['parseErrors'])) {
            $pe = $resp['parseErrors'];
            Log::warning('shopify.session_traffic.parse_error', [
                'parseErrors' => is_array($pe) ? json_encode($pe) : (string) $pe,
                'ql'          => $ql,
            ]);

            return null;
        }

        $columns = $resp['tableData']['columns'] ?? [];
        $rows    = $resp['tableData']['rows'] ?? [];

        if (! is_array($columns) || ! is_array($rows)) {
            return null;
        }

        return [$columns, $rows];
    }

    private function makeClient(PlatformConnection $conn): ShopifyClient
    {
        $accessToken = (string) ($conn->credentials['access_token'] ?? '');
        if ($accessToken === '') {
            throw new RuntimeException('Shopify connection is missing access_token.');
        }

        return new ShopifyClient((string) $conn->external_id, $accessToken);
    }
}
