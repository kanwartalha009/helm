import { useParams } from 'react-router-dom';
import { AppLayout } from '@/components/shell/AppLayout';
import { Banner, Breadcrumb, Button, Chip } from '@/components/ui';
import { useBrand, useProductRows } from '@/hooks/useDashboardData';

export function BrandProductsPage() {
  const { slug = 'meller' } = useParams();
  const { data: brand } = useBrand(slug);
  const { data: rows = [] } = useProductRows();

  const brandName = brand?.name ?? 'Meller';
  const brandInitials = brand?.initials ?? 'ML';

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
            <p className="page-subtitle">Revenue and refunds by SKU. Refunds attribute to original order date.</p>
          </div>
        </div>
      </div>

      <Banner
        variant="info"
        icon={
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75">
            <circle cx="12" cy="12" r="10" />
            <line x1="12" y1="16" x2="12" y2="12" />
            <line x1="12" y1="8" x2="12.01" y2="8" />
          </svg>
        }
      >
        Product performance is a Phase 2 feature. Real per-SKU data appears here once Shopify
        product-level sync is wired.
      </Banner>

      <div className="filter-bar mb-16" style={{ marginTop: 16 }}>
        <Chip>Yesterday</Chip>
        <Chip active>Last 7 days</Chip>
        <Chip>MTD</Chip>
        <Chip>Last 30 days</Chip>
        <span style={{ flex: 1 }} />
        <input className="input" type="text" placeholder="Search SKU or title…" style={{ maxWidth: 280 }} />
      </div>

      <div className="card" style={{ overflow: 'hidden' }}>
        <table className="data-table">
          <thead>
            <tr>
              <th style={{ width: '40%' }}>Product</th>
              <th className="num">Units sold</th>
              <th className="num">Revenue</th>
              <th className="num">Refunds</th>
              <th className="num">Refund rate</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.productId}>
                <td>
                  <div style={{ fontWeight: 500 }}>{r.title}</div>
                  <div className="brand-meta mono">SKU {r.sku}</div>
                </td>
                <td className="num"><span className="num">{r.unitsSold}</span></td>
                <td className="num"><span className="metric-primary num">${r.revenue.toLocaleString()}</span></td>
                <td className="num">
                  <span className="num">${r.refundAmount}</span>{' '}
                  <span className="muted text-xs">{r.refundUnits} {r.refundUnits === 1 ? 'unit' : 'units'}</span>
                </td>
                <td className="num" style={r.refundRate > 5 ? { color: 'var(--warning)' } : undefined}>
                  <span className="num">{r.refundRate.toFixed(1)}%</span>
                </td>
                <td className="text-right"><Button size="sm" variant="ghost">View →</Button></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="flex items-center justify-between mt-24">
        <div className="text-xs muted">Showing {rows.length} of 218 products</div>
        <div className="flex items-center gap-8">
          <Button size="sm" variant="secondary">Previous</Button>
          <Button size="sm" variant="secondary">Next</Button>
        </div>
      </div>
    </AppLayout>
  );
}
