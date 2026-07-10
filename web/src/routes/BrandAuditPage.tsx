import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { AppLayout } from '@/components/shell/AppLayout';
import { DataCoverageCard } from '@/components/brands/DataCoverageCard';
import { Breadcrumb, Card, Chip } from '@/components/ui';
import { useAuditFindings, useBrandDetail } from '@/hooks/useApiData';
import type { AuditFinding } from '@/hooks/useApiData';

const PERIODS: { key: string; label: string }[] = [
  { key: 'last7', label: 'Last 7 days' },
  { key: 'last30', label: 'Last 30 days' },
  { key: 'mtd', label: 'MTD' },
];

const SEVERITY: Record<AuditFinding['severity'], { label: string; color: string }> = {
  critical: { label: 'Critical', color: 'var(--danger, #b3261e)' },
  warn:     { label: 'Warning',  color: 'var(--warning, #9a6700)' },
  info:     { label: 'Info',     color: 'var(--text-secondary, #6b6f76)' },
  good:     { label: 'Good',     color: 'var(--success, #1f6f5c)' },
};

// Section order (spec §4 Phase 2.8): Data health → Revenue → Tracking → Ad
// accounts → Products → Inventory.
const AREA_ORDER: AuditFinding['area'][] = ['data', 'revenue', 'tracking', 'ads', 'products', 'inventory'];
const AREA_LABEL: Record<AuditFinding['area'], string> = {
  data: 'Data health',
  revenue: 'Revenue',
  tracking: 'Tracking',
  ads: 'Ad accounts',
  products: 'Products',
  inventory: 'Inventory',
};

const SEV_RANK: Record<AuditFinding['severity'], number> = { critical: 0, warn: 1, info: 2, good: 3 };

/**
 * Store audit — REAL findings composed exclusively from the rules engines
 * (campaign verdicts, dead stock, sync freshness). Deterministic by design:
 * rules, never LLM (spec §4.3). Replaces the Phase-2 empty state
 * (2026-07-10).
 */
export function BrandAuditPage() {
  const { slug } = useParams();
  const [period, setPeriod] = useState('last30');
  const [sevFilter, setSevFilter] = useState<AuditFinding['severity'] | null>(null);

  const { data: detail } = useBrandDetail(slug);
  const brand = detail?.brand;
  const { data, isLoading } = useAuditFindings(slug, period);

  const brandName = brand?.name ?? 'Brand';
  const brandInitials = brand?.initials ?? '··';

  const findings = data?.findings ?? [];
  const counts = {
    critical: findings.filter((f) => f.severity === 'critical').length,
    warn: findings.filter((f) => f.severity === 'warn').length,
    good: findings.filter((f) => f.severity === 'good').length,
  };
  const shown = sevFilter ? findings.filter((f) => f.severity === sevFilter) : findings;
  const grouped = AREA_ORDER
    .map((area) => ({
      area,
      items: shown.filter((f) => f.area === area).sort((a, b) => SEV_RANK[a.severity] - SEV_RANK[b.severity]),
    }))
    .filter((g) => g.items.length > 0);

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
            <p className="page-subtitle">
              Rules-driven findings from campaign verdicts, stock levels and data freshness
              {data ? ` · ${data.periodStart} – ${data.periodEnd}` : ''}
            </p>
          </div>
        </div>
      </div>

      <DataCoverageCard slug={slug} compact />

      <div className="filter-bar mb-16" style={{ marginTop: 8 }}>
        {PERIODS.map((p) => (
          <Chip key={p.key} active={period === p.key} onClick={() => setPeriod(p.key)}>
            {p.label}
          </Chip>
        ))}
      </div>

      {isLoading && <div className="muted" style={{ padding: 24 }}>Running the rules…</div>}

      {data && (
        <>
          <div className="flex items-center gap-8 mb-16" style={{ flexWrap: 'wrap' }}>
            {(['critical', 'warn', 'good'] as const).map((sev) => (
              <button
                key={sev}
                type="button"
                onClick={() => setSevFilter(sevFilter === sev ? null : sev)}
                style={{
                  cursor: 'pointer', border: '1px solid var(--border, #e7e4dd)', borderRadius: 20, padding: '4px 12px',
                  background: sevFilter === sev ? SEVERITY[sev].color : 'transparent',
                  color: sevFilter === sev ? '#fff' : SEVERITY[sev].color, fontSize: 12, fontWeight: 600,
                }}
              >
                {counts[sev]} {sev === 'critical' ? 'critical' : sev === 'warn' ? `warning${counts[sev] === 1 ? '' : 's'}` : 'good'}
              </button>
            ))}
            {sevFilter && (
              <button type="button" className="muted text-xs" style={{ cursor: 'pointer', background: 'none', border: 'none' }} onClick={() => setSevFilter(null)}>
                clear filter
              </button>
            )}
          </div>

          <div style={{ display: 'grid', gap: 20 }}>
            {grouped.map((g) => (
              <div key={g.area}>
                <div className="text-xs" style={{ fontWeight: 700, letterSpacing: '.06em', textTransform: 'uppercase', color: 'var(--text-secondary)', marginBottom: 8 }}>
                  {AREA_LABEL[g.area]}
                </div>
                <div style={{ display: 'grid', gap: 12 }}>
                  {g.items.map((f) => (
                    <Card key={f.id} style={{ padding: 18 }}>
                      <div className="flex items-center gap-8" style={{ marginBottom: 6 }}>
                        <span aria-hidden style={{ width: 8, height: 8, borderRadius: '50%', background: SEVERITY[f.severity].color, flexShrink: 0 }} />
                        <span style={{ fontSize: 12, fontWeight: 600, color: SEVERITY[f.severity].color }}>{SEVERITY[f.severity].label}</span>
                      </div>
                      <div style={{ fontWeight: 600, marginBottom: 4 }}>{f.title}</div>
                      <div className="muted text-sm" style={{ lineHeight: 1.55 }}>{f.detail}</div>
                    </Card>
                  ))}
                </div>
              </div>
            ))}
            {grouped.length === 0 && <div className="muted" style={{ padding: 18 }}>No {sevFilter ?? ''} findings in this window.</div>}
          </div>

          <div className="text-xs muted mt-16">
            Every finding comes from a deterministic rule — the same thresholds every time, no AI involved.
          </div>
        </>
      )}
    </AppLayout>
  );
}
