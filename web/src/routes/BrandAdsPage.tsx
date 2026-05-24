import { useParams } from 'react-router-dom';
import { AppLayout } from '@/components/shell/AppLayout';
import { Banner, Breadcrumb, Button, Chip, Tag } from '@/components/ui';
import { useAdRows, useBrand } from '@/hooks/useDashboardData';

export function BrandAdsPage() {
  const { slug = 'meller' } = useParams();
  const { data: brand } = useBrand(slug);
  const { data: rows = [] } = useAdRows();

  const brandName = brand?.name ?? 'Meller';
  const brandInitials = brand?.initials ?? 'ML';

  return (
    <AppLayout title="Ad performance">
      <Breadcrumb
        crumbs={[
          { label: 'Brands', to: '/brands' },
          { label: brandName, to: `/brands/${slug}` },
          { label: 'Ad performance' },
        ]}
      />

      <div className="page-header">
        <div className="flex items-center gap-12">
          <span className="brand-avatar" style={{ width: 32, height: 32 }}>{brandInitials}</span>
          <div>
            <h2 className="page-title">{brandName} — ad performance</h2>
            <p className="page-subtitle">Campaign → ad set → ad. Last 7 days.</p>
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
        Ad performance is a Phase 2 feature. The data shown below is illustrative until
        Meta/Google/TikTok ad-level syncs ship.
      </Banner>

      <div className="filter-bar mb-16" style={{ marginTop: 16 }}>
        <Chip>Yesterday</Chip>
        <Chip active>Last 7 days</Chip>
        <Chip>Last 14 days</Chip>
        <Chip>Last 30 days</Chip>
        <span style={{ flex: 1 }} />
        <div className="segmented">
          <button className="active">Meta</button>
          <button>Google</button>
          <button>TikTok</button>
          <button>All</button>
        </div>
      </div>

      <Banner
        variant="warning"
        icon={
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75">
            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
            <line x1="12" y1="9" x2="12" y2="13" />
          </svg>
        }
      >
        <strong>3 underperformers</strong> · spend &gt; €500 with ROAS &lt; 1.0 over the trailing 14 days.{' '}
        <a href="#" style={{ color: 'inherit', textDecoration: 'underline', marginLeft: 6 }}>Review</a>
      </Banner>

      <div className="card" style={{ overflow: 'hidden', marginTop: 16 }}>
        <table className="data-table">
          <thead>
            <tr>
              <th />
              <th style={{ width: '30%' }}>Name</th>
              <th className="num">Spend</th>
              <th className="num">Revenue</th>
              <th className="num">ROAS</th>
              <th className="num">CTR</th>
              <th className="num">CPC</th>
              <th className="num">Freq</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.id}>
                <td>
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                    <polyline points="9 18 15 12 9 6" />
                  </svg>
                </td>
                <td>
                  <div style={{ fontWeight: 500 }}>{r.name}</div>
                  <div className="brand-meta">CAMPAIGN · ad sets · ads</div>
                </td>
                <td className="num"><span className="num">${r.spend.toLocaleString()}</span></td>
                <td className="num"><span className="num">${r.revenue.toLocaleString()}</span></td>
                <td className="num">
                  <span className="metric-primary num">{r.roas.toFixed(2)}×</span>
                  {r.flag === 'scale' && <Tag variant="success" style={{ marginLeft: 4 }}>Scale</Tag>}
                  {r.flag === 'underperformer' && <Tag variant="warning" style={{ marginLeft: 4 }}>Underperformer</Tag>}
                </td>
                <td className="num"><span className="num">{r.ctr.toFixed(2)}%</span></td>
                <td className="num"><span className="num">${r.cpc.toFixed(2)}</span></td>
                <td className="num"><span className="num">{r.frequency.toFixed(1)}</span></td>
                <td className="text-right"><Button size="sm" variant="ghost">Open →</Button></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div style={{ marginTop: 24 }}>
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
          Attribution: 7-day click only. Flags refresh nightly with the daily sync.
        </Banner>
      </div>
    </AppLayout>
  );
}
