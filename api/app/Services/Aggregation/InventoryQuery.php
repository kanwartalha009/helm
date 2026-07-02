<?php

declare(strict_types=1);

namespace App\Services\Aggregation;

use App\Models\AdProductDaily;
use App\Models\Brand;
use App\Models\CommerceDailyMetric;
use App\Models\ProductCatalog;
use Carbon\CarbonImmutable;

/**
 * Assembles the Inventory Intelligence report for one brand: per Shopify product,
 * joins current stock + variants (product_catalog), units + revenue
 * (commerce_daily_metrics, by product title) and Meta spend + ROAS + active ads
 * (ad_product_daily, by product handle) over a selectable window.
 *
 * The catalog is the bridge: ad spend keys by product HANDLE, commerce by product
 * TITLE, and only the catalog knows both. Revenue is Total sales + refunds (before
 * returns), Online Store only; units are gross ordered quantity; ROAS is blended
 * (revenue ÷ Meta spend) at the product level. All native brand currency.
 */
final class InventoryQuery
{
    /** Stock tiers → status. */
    private const ALERT_AT = 20;

    /** @param array<string, mixed> $params */
    public function run(Brand $brand, array $params): array
    {
        $tz     = $brand->timezone ?: 'UTC';
        $period = in_array($params['period'] ?? null, ['last7', 'last30', 'mtd', 'custom'], true)
            ? (string) $params['period'] : 'last7';

        [$from, $to, $pFrom, $pTo] = $this->window($period, $tz, $params['from'] ?? null, $params['to'] ?? null);

        $bid = $brand->id;
        $ccy = strtoupper((string) ($brand->base_currency ?: 'USD'));

        $products = ProductCatalog::query()->where('brand_id', $bid)->get();

        // Meta spend + peak active ads per product handle, this window.
        $adByHandle = AdProductDaily::query()
            ->where('brand_id', $bid)
            ->whereBetween('date', [$from, $to])
            ->selectRaw('product_key, COALESCE(SUM(spend), 0) AS spend, COALESCE(MAX(ads_count), 0) AS ads')
            ->groupBy('product_key')
            ->get()
            ->keyBy('product_key');

        // Units (gross) + revenue-before-returns per product title, this window + prior.
        $commByTitle = $this->commerce($bid, $from, $to);
        $commPrev    = $this->commerce($bid, $pFrom, $pTo);

        $rows = [];
        $sumSpend = 0.0;
        $sumRev   = 0.0;
        $sumUnits = 0;
        $sumUnitsPrev = 0;
        $sumStock = 0;
        $cPause = $cAlert = $cOk = 0;

        foreach ($products as $p) {
            $ad   = $adByHandle->get($p->handle);
            $c    = $commByTitle->get($p->title);
            $cp   = $commPrev->get($p->title);

            $spend  = (float) ($ad->spend ?? 0);
            $adsN   = (int) ($ad->ads ?? 0);
            $units  = (int) ($c->units ?? 0);
            $unitsP = (int) ($cp->units ?? 0);
            $revenue = (float) ($c->total_sales ?? 0) + (float) ($c->refunds ?? 0);
            $roas   = $spend > 0 ? round($revenue / $spend, 2) : null;
            $delta  = $unitsP > 0 ? (int) round(($units - $unitsP) / $unitsP * 100) : null;

            $stock  = (int) $p->total_inventory;
            $status = $stock <= 0 ? 'pause' : ($stock <= self::ALERT_AT ? 'alert' : 'ok');
            $action = match (true) {
                $status === 'pause' => 'out_of_stock',
                $status === 'alert' => 'low_stock',
                $spend <= 0         => 'no_spend',
                default             => 'ok',
            };

            $rows[] = [
                'handle'       => $p->handle,
                'title'        => $p->title,
                'variantCount' => (int) $p->variant_count,
                'variants'     => is_array($p->variants) ? $p->variants : [],
                'stock'        => $stock,
                'units'        => $units,
                'unitsPrev'    => $unitsP,
                'deltaPct'     => $delta,
                'spend'        => round($spend, 2),
                'revenue'      => round($revenue, 2),
                'roas'         => $roas,
                'ads'          => $adsN,
                'status'       => $status,
                'action'       => $action,
            ];

            $sumSpend     += $spend;
            $sumRev       += $revenue;
            $sumUnits     += $units;
            $sumUnitsPrev += $unitsP;
            $sumStock     += $stock;
            $status === 'pause' ? $cPause++ : ($status === 'alert' ? $cAlert++ : $cOk++);
        }

        // Biggest spenders first (matches the mockup's default sort).
        usort($rows, static fn (array $a, array $b): int => $b['spend'] <=> $a['spend']
            ?: strcasecmp((string) $a['title'], (string) $b['title']));

        // Meta spend NOT attributed to a single product — preserved, shown as a banner.
        $un = AdProductDaily::query()
            ->where('brand_id', $bid)
            ->whereIn('product_key', ['__collection', '__other'])
            ->whereBetween('date', [$from, $to])
            ->selectRaw("COALESCE(SUM(CASE WHEN product_key = '__collection' THEN spend ELSE 0 END), 0) AS coll,
                         COALESCE(SUM(CASE WHEN product_key = '__other' THEN spend ELSE 0 END), 0) AS other")
            ->first();
        $coll  = (float) ($un->coll ?? 0);
        $other = (float) ($un->other ?? 0);

        // Brand-level blended ROAS uses TOTAL Meta spend — spend attributed to a
        // product PLUS the unattributed (collection/dynamic) spend. Dropping the
        // unattributed €-figure from the denominator would flatter the headline
        // ROAS, so we don't. The product rows still sum only to attributed spend;
        // the gap is the unattributed banner, which is exactly why it exists.
        $totalSpend = $sumSpend + $coll + $other;

        return [
            'brand'    => ['id' => $brand->id, 'name' => $brand->name, 'slug' => $brand->slug, 'currency' => $ccy],
            'period'   => $period,
            'from'     => $from,
            'to'       => $to,
            'currency' => $ccy,
            'summary'  => [
                'products'        => $products->count(),
                'pause'           => $cPause,
                'alert'           => $cAlert,
                'ok'              => $cOk,
                'netStock'        => $sumStock,
                'units'           => $sumUnits,
                'unitsPrev'       => $sumUnitsPrev,
                'metaSpend'       => round($totalSpend, 2),
                'attributedSpend' => round($sumSpend, 2),
                'revenue'         => round($sumRev, 2),
                'roas'            => $totalSpend > 0 ? round($sumRev / $totalSpend, 2) : null,
            ],
            'unattributed' => ['collection' => round($coll, 2), 'other' => round($other, 2), 'total' => round($coll + $other, 2)],
            'products'     => $rows,
        ];
    }

    /** Per-product-title commerce sums (units gross, total_sales, refunds) over a window. */
    private function commerce(int $brandId, string $from, string $to): \Illuminate\Support\Collection
    {
        return CommerceDailyMetric::query()
            ->where('brand_id', $brandId)
            ->where('dimension_type', 'product')
            ->whereBetween('date', [$from, $to])
            ->selectRaw('dimension_key,
                COALESCE(SUM(units), 0)          AS units,
                COALESCE(SUM(total_sales), 0)    AS total_sales,
                COALESCE(SUM(refunds_amount), 0) AS refunds')
            ->groupBy('dimension_key')
            ->get()
            ->keyBy('dimension_key');
    }

    /**
     * [from, to, priorFrom, priorTo] date strings (brand tz). The window ends
     * yesterday (today is partial), and the prior window is the equal-length span
     * immediately before it — for the units Δ%.
     *
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private function window(string $period, string $tz, ?string $from, ?string $to): array
    {
        $now       = CarbonImmutable::now($tz);
        $yesterday = $now->subDay()->startOfDay();

        [$s, $e] = match ($period) {
            'last30' => [$yesterday->subDays(29), $yesterday],
            'mtd'    => [$now->startOfMonth(), $yesterday],
            'custom' => [
                CarbonImmutable::parse($from ?: $yesterday->subDays(6)->toDateString(), $tz)->startOfDay(),
                CarbonImmutable::parse($to ?: $yesterday->toDateString(), $tz)->startOfDay(),
            ],
            default  => [$yesterday->subDays(6), $yesterday], // last7
        };
        if ($s->greaterThan($e)) {
            $s = $e;
        }

        $len = (int) $s->diffInDays($e) + 1;
        $pe  = $s->subDay();
        $ps  = $pe->subDays($len - 1);

        return [$s->toDateString(), $e->toDateString(), $ps->toDateString(), $pe->toDateString()];
    }
}
