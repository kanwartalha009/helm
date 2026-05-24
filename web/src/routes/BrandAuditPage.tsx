import { useParams } from 'react-router-dom';
import { AppLayout } from '@/components/shell/AppLayout';
import { Banner, Breadcrumb, Button, Dot } from '@/components/ui';
import { useAuditFindings, useBrand } from '@/hooks/useDashboardData';

function severityLabel(s: string) {
  if (s === 'critical') return 'critical';
  if (s === 'warn') return 'warn';
  return 'info';
}

function detectedLabel(date: string) {
  const today = new Date('2026-05-16');
  const d = new Date(date);
  const diffDays = Math.round((today.getTime() - d.getTime()) / (1000 * 60 * 60 * 24));
  return `${diffDays} days ago`;
}

export function BrandAuditPage() {
  const { slug = 'meller' } = useParams();
  const { data: brand } = useBrand(slug);
  const { data: findings = [] } = useAuditFindings();

  const brandName = brand?.name ?? 'Meller';
  const brandInitials = brand?.initials ?? 'ML';

  return (
    <AppLayout title="Store audit">
      <Breadcrumb
        crumbs={[
          { label: 'Brands', to: '/brands' },
          { label: brandName, to: `/brands/${slug}` },
          { label: 'Store audit' },
        ]}
      />

      <div className="page-header">
        <div className="flex items-center gap-12">
          <span className="brand-avatar" style={{ width: 32, height: 32 }}>{brandInitials}</span>
          <div>
            <h2 className="page-title">{brandName} — store audit</h2>
            <p className="page-subtitle">Refreshed weekly. Last run: 2 days ago at 04:00 UTC.</p>
          </div>
        </div>
        <Button size="sm" variant="secondary">Re-run audit</Button>
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
        Store audit checks are a Phase 2 feature. Real findings appear here once weekly audits run.
      </Banner>

      <div className="stat-grid stat-grid-3 mb-24" style={{ marginTop: 16 }}>
        <div className="stat">
          <div className="stat-label">Page speed (LCP)</div>
          <div className="stat-value num">2.4s</div>
          <div className="stat-sub" style={{ color: 'var(--success)' }}>Within target (&lt; 2.5s)</div>
        </div>
        <div className="stat">
          <div className="stat-label">Checkout drop-off</div>
          <div className="stat-value num">61.4%</div>
          <div className="stat-sub" style={{ color: 'var(--warning)' }}>+4.2 pts vs prior week</div>
        </div>
        <div className="stat">
          <div className="stat-label">Broken events</div>
          <div className="stat-value num">2</div>
          <div className="stat-sub" style={{ color: 'var(--warning)' }}>Meta Pixel: Purchase, AddToCart</div>
        </div>
      </div>

      <h3 className="section-title">Findings</h3>
      <div className="card" style={{ overflow: 'hidden' }}>
        {findings.map((f) => (
          <div key={f.id} className="list-row">
            {f.severity === 'critical' ? (
              <span className="dot" style={{ background: 'var(--danger)' }} />
            ) : f.severity === 'warn' ? (
              <Dot variant="warning" />
            ) : (
              <span className="dot" style={{ background: 'var(--text-muted)' }} />
            )}
            <div className="list-row-main">
              <div className="list-row-title">{f.title}</div>
              <div className="list-row-sub">
                {f.auditType} · severity: {severityLabel(f.severity)} · detected {detectedLabel(f.detectedAt)}
              </div>
            </div>
            {/* Phase 2 — the audit-findings table + resolve mutation aren't
                wired yet. Buttons hidden until then so they don't pretend to act. */}
          </div>
        ))}
      </div>
    </AppLayout>
  );
}
