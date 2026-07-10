import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { AppLayout } from '@/components/shell/AppLayout';
import { Breadcrumb, Button, Card, Chip, PageEmptyState } from '@/components/ui';
import { useBrandDetail, useBrandProducts } from '@/hooks/useApiData';
import { formatMoney } from '@/lib/formatters';

const PERIODS: { key: string; label: string }[] = [
  { key: 'last7', label: 'Last 7 days' },
  { key: 'last30', label: 'Last 30 days' },
  { key: 'last90', label: 'Last 90 days' },
  { key: 'mtd', label: 'MTD' },
];

/**
 * Product performance — REAL data from commerce_daily_metrics (product
 * dimension), aggregated server-side per window with prior-window deltas.
 * Replaces the Phase-2 empty state (2026-07-10); shows an honest backfill
 * hint when the brand has no commerce rows yet. Native currency.
 */
export function BrandProductsPage() {
  const { slug } = useParams();
  const [period, setPeriod] = useState('last30');
  const [search, setSearch] = useState('');

  const { data: detail } = useBrandDetail(slug);
  const brand = detail?.brand;
  const { data, isLoading } = useBrandProducts(slug, period, search);

  const brandName = brand?.name ?? 'Brand';
  const brandInitials = brand?.initials ?? '··';
  const currency = data?.currency ?? brand?.baseCurrency ?? 'EUR';

  return (
    <AppLayout title="Product performance">
      <Breadcrumb
        crumbs={[
          { label: 'Brands', to: '/brands' },
          { label: brandName, to: `/brands/${slug}` },
          { label: 'Products' },
        ]}
      />

      <div className="page-header">
        <div className="flex items-center gap-12">
          <span className="brand-avatar" style={{ width: 32, height: 32 }}>{brandInitials}</span>
          <div>
            <h2 className="page-title">{brandName} — products</h2>
            <p className="page-subtitle">
              Revenue, units and refunds by product · {data ? `${data.periodStart} – ${data.periodEnd}` : ''}
            </p>
          </div>
        </div>
      </div>

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
            <Link to={`/brands/${slug}`}>
              <Button variant="secondary">Back to {brandName}</Button>
            </Link>
          }
        />
      )}

      {data && (data.hasData || search) && (
        <>
          <Card style={{ overflow: 'hidden' }}>
            <table className="data-table">
              <thead>
                <tr>
                  <th style={{ width: '34%' }}>Product</th>
                  <th className="num">Revenue</th>
                  <th className="num">Share</th>
                  <th className="num">vs prior</th>
                  <th className="num">Orders</th>
                  <th className="num">Units</th>
                  <th className="num">Refunds</th>
                </tr>
              </thead>
              <tbody>
                {data.rows.map((r) => (
                  <tr key={r.key}>
                    <td><div style={{ fontWeight: 500 }}>{r.title}</div></td>
                    <td className="num"><span className="metric-primary num">{formatMoney(r.revenue, currency)}</span></td>
                    <td className="num">{r.sharePct !== null ? `${r.sharePct}%` : '—'}</td>
                    <td className="num" style={r.deltaPct !== null ? { color: r.deltaPct >= 0 ? 'var(--success, #1f6f5c)' : 'var(--danger, #b3261e)' } : undefined}>
                      {r.deltaPct !== null ? `${r.deltaPct > 0 ? '+' : ''}${r.deltaPct}%` : '—'}
                    </td>
                    <td className="num">{r.orders.toLocaleString()}</td>
                    <td className="num">{r.units.toLocaleString()}</td>
                    <td className="num" style={r.refundRatePct !== null && r.refundRatePct > 5 ? { color: 'var(--warning)' } : undefined}>
                      {formatMoney(r.refunds, currency)}
                      {r.refundRatePct !== null && <span className="muted text-xs"> ({r.refundRatePct}%)</span>}
                    </td>
                  </tr>
                ))}
                {data.rows.length === 0 && (
                  <tr><td colSpan={7} className="muted" style={{ padding: 18 }}>No products match “{search}”.</td></tr>
                )}
              </tbody>
            </table>
          </Card>

          <div className="flex items-center justify-between mt-24">
            <div className="text-xs muted">
              {data.rows.length} product{data.rows.length === 1 ? '' : 's'} · window total{' '}
              {formatMoney(data.totalRevenue, currency)} ·{' '}
              {data.lastPulledAt ? `commerce data pulled ${new Date(data.lastPulledAt).toLocaleString()}` : 'pull time unknown'}
            </div>
          </div>
        </>
      )}
    </AppLayout>
  );
}
