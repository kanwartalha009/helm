import { useState, type CSSProperties } from 'react';
import { Link } from 'react-router-dom';
import { DataCoverageCard } from '@/components/brands/DataCoverageCard';
import { Button, Card, Chip, PageEmptyState } from '@/components/ui';
import { useBrandDetail, useBrandProducts, useSetProductCost } from '@/hooks/useApiData';
import type { BrandProductRow } from '@/hooks/useApiData';
import { formatMoney } from '@/lib/formatters';
import { toast } from '@/stores/toastStore';

const PERIODS: { key: string; label: string }[] = [
  { key: 'last7', label: 'Last 7 days' },
  { key: 'last30', label: 'Last 30 days' },
  { key: 'last90', label: 'Last 90 days' },
  { key: 'mtd', label: 'MTD' },
];

/**
 * Product performance table + controls for ONE brand — commerce_daily_metrics
 * (product dimension) aggregated server-side with prior-window deltas, ABC grade,
 * cover days, and Phase-5 mapped ad spend / ROAS + the losing_on_ads flag. Native
 * currency. Extracted from BrandProductsPage so it renders both under a brand
 * (`/brands/:slug/products`) and inline on the top-level Products hub after a
 * brand is chosen — same body, one source of truth.
 */
export function BrandProductsView({ slug }: { slug?: string }) {
  const [period, setPeriod] = useState('last30');
  const [search, setSearch] = useState('');
  const [sort, setSort] = useState('revenue');

  const { data: detail } = useBrandDetail(slug);
  const brand = detail?.brand;
  const { data, isLoading } = useBrandProducts(slug, period, search, sort);

  const brandName = brand?.name ?? 'this brand';
  const currency = data?.currency ?? brand?.baseCurrency ?? 'EUR';
  const snapshotStale = data?.inventorySnapshotAt
    ? (Date.now() - new Date(data.inventorySnapshotAt).getTime()) / 86_400_000 > 3
    : false;

  return (
    <>
      <DataCoverageCard slug={slug} compact />

      <div className="filter-bar mb-16" style={{ marginTop: 8 }}>
        {PERIODS.map((p) => (
          <Chip key={p.key} active={period === p.key} onClick={() => setPeriod(p.key)}>
            {p.label}
          </Chip>
        ))}
        <span style={{ flex: 1 }} />
        <input
          className="input"
          type="text"
          placeholder="Search products…"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          style={{ maxWidth: 280 }}
        />
      </div>

      {isLoading && <div className="muted" style={{ padding: 24 }}>Loading products…</div>}

      {data && !data.hasData && !search && (
        <PageEmptyState
          title="No product-level data on file yet"
          body={
            <>
              Daily syncs keep product revenue current going forward, but history needs a one-time backfill. Ask for
              <code style={{ margin: '0 4px' }}>shopify:backfill-commerce</code> to be run for this brand, then this
              page fills itself.
            </>
          }
          primary={
            slug ? (
              <Link to={`/brands/${slug}`}>
                <Button variant="secondary">Back to {brandName}</Button>
              </Link>
            ) : undefined
          }
        />
      )}

      {data && (data.hasData || search) && (
        <>
          <Card style={{ overflow: 'auto' }}>
            <table className="data-table">
              <thead>
                <tr>
                  <th style={{ width: '22%' }}>Product</th>
                  <th title="Shopify ABC method: A = top ~80% of revenue">Grade</th>
                  <SortTh label="Revenue" col="revenue" sort={sort} setSort={setSort} />
                  <th className="num">Share</th>
                  <SortTh label="vs prior" col="delta" sort={sort} setSort={setSort} />
                  <th className="num">Orders</th>
                  <SortTh label="Units" col="units" sort={sort} setSort={setSort} />
                  <th className="num">AOV</th>
                  <th className="num" title="Mapped ad spend (window) — spend on ads whose landing URL is this product">Ad spend</th>
                  <th className="num" title="Product revenue ÷ mapped ad spend. Unmapped spend (Shopping/PMax) is excluded, so this reads high.">ROAS</th>
                  <th className="num" title="Unit cost. Your manual cost wins over Shopify's. Click to set or correct one.">Cost</th>
                  <SortTh label="Margin" col="margin" sort={sort} setSort={setSort} />
                  <SortTh label="Refund %" col="refunds" sort={sort} setSort={setSort} />
                  <SortTh label={snapshotStale ? 'Cover *' : 'Cover'} col="cover" sort={sort} setSort={setSort} amber={snapshotStale} />
                  <th>Flags</th>
                </tr>
              </thead>
              <tbody>
                {data.rows.map((r) => (
                  <tr key={r.key}>
                    <td><div style={{ fontWeight: 500 }}>{r.title}</div></td>
                    <td>{r.abc ? <span style={gradeStyle(r.abc)}>{r.abc}</span> : <span className="muted">—</span>}</td>
                    <td className="num"><span className="metric-primary num">{formatMoney(r.revenue, currency)}</span></td>
                    <td className="num">{r.sharePct !== null ? `${r.sharePct}%` : '—'}</td>
                    <td className="num" style={r.deltaPct !== null ? { color: r.deltaPct >= 0 ? 'var(--success, #1f6f5c)' : 'var(--danger, #b3261e)' } : undefined}>
                      {r.deltaPct !== null ? `${r.deltaPct > 0 ? '+' : ''}${r.deltaPct}%` : '—'}
                    </td>
                    <td className="num">{r.orders.toLocaleString()}</td>
                    <td className="num">{r.units.toLocaleString()}</td>
                    <td className="num">{r.aov !== null ? formatMoney(r.aov, currency) : '—'}</td>
                    <td className="num">{r.adSpend !== null ? formatMoney(r.adSpend, currency) : '—'}</td>
                    <td className="num"><CostCell slug={slug} row={r} currency={currency} /></td>
                    <td className="num">
                      {r.contributionMargin === null ? (
                        <span className="muted" title="No cost basis — set a unit cost or the brand's gross margin %.">—</span>
                      ) : (
                        <span
                          style={{ color: r.contributionMargin < 0 ? 'var(--danger, #b3261e)' : undefined }}
                          title={r.costSource === 'brand_margin'
                            ? 'Estimated from the brand gross-margin %, not a per-unit cost.'
                            : `Revenue − COGS (${r.costSource} cost) − mapped ad spend.`}
                        >
                          {formatMoney(r.contributionMargin, currency)}
                          {r.contributionMarginPct !== null && (
                            <span className="muted text-xs"> · {r.contributionMarginPct}%</span>
                          )}
                        </span>
                      )}
                    </td>
                    <td className="num" style={r.refundRatePct !== null && r.refundRatePct > 5 ? { color: 'var(--warning)' } : undefined}>
                      {r.refundRatePct !== null ? `${r.refundRatePct}%` : '—'}
                    </td>
                    <td className="num">{r.coverDays !== null ? `${r.coverDays}d` : '—'}</td>
                    <td>
                      {r.flags.length === 0 ? <span className="muted">—</span> : (
                        <span className="flex" style={{ gap: 4, flexWrap: 'wrap' }}>
                          {r.flags.map((f) => (
                            <span key={f.key} title={f.detail} style={flagStyle(f.severity)}>{f.label}</span>
                          ))}
                        </span>
                      )}
                    </td>
                  </tr>
                ))}
                {data.rows.length === 0 && (
                  <tr><td colSpan={15} className="muted" style={{ padding: 18 }}>No products match “{search}”.</td></tr>
                )}
              </tbody>
            </table>
          </Card>

          <div className="flex items-center justify-between mt-24">
            <div className="text-xs muted">
              {data.rows.length} product{data.rows.length === 1 ? '' : 's'} · window total{' '}
              {formatMoney(data.totalRevenue, currency)}
              {data.inventorySnapshotAt && (
                <> · stock snapshot {data.inventorySnapshotAt}{snapshotStale && <span style={{ color: 'var(--warning)' }}> (stale)</span>}</>
              )}
              {' · '}
              {data.lastPulledAt ? `pulled ${new Date(data.lastPulledAt).toLocaleDateString()}` : 'pull time unknown'}
            </div>
          </div>

          {data.adSpend.mappedPct !== null && (
            <div className="text-xs muted mt-8" style={{ maxWidth: 760 }}>
              {data.adSpend.mappedPct}% of ad spend this window is mapped to products via landing URLs — unmapped spend
              (e.g. Google Shopping/PMax) is excluded, so product ROAS reads high. Blended truth lives on the dashboard.
            </div>
          )}

          {/* GO-1.2 — no cost basis at all: say so plainly rather than showing a
              margin column of dashes with no explanation. */}
          {data.costs && !data.costs.hasBasis && (
            <div className="text-xs muted mt-8" style={{ maxWidth: 760 }}>
              No cost basis for this brand yet, so margin is “—”. Set a unit cost on any product below, or a
              gross-margin % in brand Settings. Helm never guesses a cost — an unknown cost is shown as unknown,
              never as zero.
            </div>
          )}
          {data.costs?.hasBasis && (
            <div className="text-xs muted mt-8" style={{ maxWidth: 760 }}>{data.costs.formula}</div>
          )}
        </>
      )}
    </>
  );
}

/**
 * Unit-cost cell with an inline setter (GO-1.2). Shows the cost and where it came
 * from: a real per-unit cost (manual / Shopify) or nothing at all. A brand-margin
 * estimate is NOT shown here as a unit cost — that would dress a brand-wide rate up
 * as a measured per-product number; the Margin column carries the estimate instead.
 *
 * Saving is effective-dated server-side (from today), so correcting a cost never
 * rewrites the margin of a window that has already been reported to a client.
 */
function CostCell({ slug, row, currency }: { slug?: string; row: BrandProductRow; currency: string }) {
  const setCost = useSetProductCost(slug);
  const [editing, setEditing] = useState(false);
  const [value, setValue] = useState(row.unitCost !== null ? String(row.unitCost) : '');

  // The product key the API costs by is the Shopify handle; the table's key is the
  // product title. The server lower-cases and matches, so send the title-derived key.
  const save = () => {
    const n = Number(value);
    if (!Number.isFinite(n) || n < 0) return;
    setCost.mutate(
      { product_key: row.key, unit_cost: n },
      { onSuccess: () => { setEditing(false); toast.success('Cost saved', `${row.title}: ${formatMoney(n, currency)} per unit.`); },
        onError: () => toast.error('Could not save the cost', 'Admins and managers only.') },
    );
  };

  if (editing) {
    return (
      <span className="flex items-center gap-8" style={{ justifyContent: 'flex-end' }}>
        <input
          className="input"
          type="number"
          min={0}
          step="0.01"
          autoFocus
          value={value}
          onChange={(e) => setValue(e.target.value)}
          onKeyDown={(e) => { if (e.key === 'Enter') save(); if (e.key === 'Escape') setEditing(false); }}
          style={{ maxWidth: 90, padding: '2px 6px' }}
        />
        <button type="button" className="text-xs" style={{ background: 'none', border: 0, cursor: 'pointer', color: 'var(--accent)' }} disabled={setCost.isPending} onClick={save}>save</button>
      </span>
    );
  }

  return (
    <button
      type="button"
      title={row.costSource === 'manual' ? 'Your manual cost (overrides Shopify). Click to change.'
        : row.costSource === 'shopify' ? 'Cost from Shopify. Click to override.'
        : 'No unit cost known. Click to set one.'}
      style={{ background: 'none', border: 0, cursor: 'pointer', font: 'inherit', color: 'inherit' }}
      onClick={() => setEditing(true)}
    >
      {row.unitCost !== null ? formatMoney(row.unitCost, currency) : <span className="muted">set</span>}
      {row.costSource === 'manual' && <span className="muted text-xs"> ·m</span>}
    </button>
  );
}

function SortTh({ label, col, sort, setSort, amber }: { label: string; col: string; sort: string; setSort: (s: string) => void; amber?: boolean }) {
  const active = sort === col;
  return (
    <th
      className="num"
      style={{ cursor: 'pointer', userSelect: 'none', color: amber ? 'var(--warning)' : active ? 'var(--text-primary)' : undefined }}
      onClick={() => setSort(col)}
    >
      {label}{active ? ' ↓' : ''}
    </th>
  );
}

function gradeStyle(g: string): CSSProperties {
  const map: Record<string, [string, string]> = { A: ['#e3efe7', '#1c6b45'], B: ['#f1efe8', '#5f5e5a'], C: ['#f4e4e1', '#a83a31'] };
  const [bg, fg] = map[g] ?? ['#f1efe8', '#5f5e5a'];
  return { background: bg, color: fg, fontWeight: 600, fontSize: 11, padding: '2px 8px', borderRadius: 5 };
}

function flagStyle(sev: string): CSSProperties {
  const map: Record<string, [string, string]> = { critical: ['#f4e4e1', '#a83a31'], warn: ['#faeeda', '#8a6d1f'], info: ['#eceae2', '#5f5e5a'] };
  const [bg, fg] = map[sev] ?? ['#eceae2', '#5f5e5a'];
  return { background: bg, color: fg, fontSize: 10.5, fontWeight: 500, padding: '2px 7px', borderRadius: 5, whiteSpace: 'nowrap' };
}
