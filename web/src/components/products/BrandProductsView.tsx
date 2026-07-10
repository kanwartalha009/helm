import { useState, type CSSProperties } from 'react';
import { Link } from 'react-router-dom';
import { DataCoverageCard } from '@/components/brands/DataCoverageCard';
import { Button, Card, Chip, PageEmptyState } from '@/components/ui';
import { useBrandDetail, useBrandProducts } from '@/hooks/useApiData';
import { formatMoney } from '@/lib/formatters';

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
                    <td className="num">{r.roas !== null ? `${r.roas.toFixed(2)}×` : '—'}</td>
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
                  <tr><td colSpan={13} className="muted" style={{ padding: 18 }}>No products match “{search}”.</td></tr>
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
        </>
      )}
    </>
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
