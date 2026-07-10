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

const AREA_LABEL: Record<AuditFinding['area'], string> = {
  ads: 'Ad accounts',
  inventory: 'Inventory',
  data: 'Data health',
};

/**
 * Store audit — REAL findings composed exclusively from the rules engines
 * (campaign verdicts, dead stock, sync freshness). Deterministic by design:
 * rules, never LLM (spec §4.3). Replaces the Phase-2 empty state
 * (2026-07-10).
 */
export function BrandAuditPage() {
  const { slug } = useParams();
  const [period, setPeriod] = useState('last30');

  const { data: detail } = useBrandDetail(slug);
  const brand = detail?.brand;
  const { data, isLoading } = useAuditFindings(slug, period);

  const brandName = brand?.name ?? 'Brand';
  const brandInitials = brand?.initials ?? '··';

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
        <div style={{ display: 'grid', gap: 12 }}>
          {data.findings.map((f) => (
            <Card key={f.id} style={{ padding: 18 }}>
              <div className="flex items-center gap-8" style={{ marginBottom: 6 }}>
                <span
                  aria-hidden
                  style={{ width: 8, height: 8, borderRadius: '50%', background: SEVERITY[f.severity].color, flexShrink: 0 }}
                />
                <span style={{ fontSize: 12, fontWeight: 600, color: SEVERITY[f.severity].color }}>
                  {SEVERITY[f.severity].label}
                </span>
                <span className="muted text-xs">· {AREA_LABEL[f.area]}</span>
              </div>
              <div style={{ fontWeight: 600, marginBottom: 4 }}>{f.title}</div>
              <div className="muted text-sm" style={{ lineHeight: 1.55 }}>{f.detail}</div>
            </Card>
          ))}
          <div className="text-xs muted">
            Every finding above comes from a deterministic rule — the same thresholds every time, no AI involved.
          </div>
        </div>
      )}
    </AppLayout>
  );
}
