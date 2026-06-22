import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { AppLayout } from '@/components/shell/AppLayout';
import { Button, Card } from '@/components/ui';
import { useReportTypes } from '@/hooks/useReports';
import { useDashboardData } from '@/hooks/useDashboardData';

/**
 * Report picker — choose a brand and a report type, generate. The brand list
 * reuses the dashboard query (the user's accessible brands); report types come
 * from the registry, so new types appear here automatically.
 */
export function ReportsPage() {
  const navigate = useNavigate();
  const { data: types = [] } = useReportTypes();
  const { data: rows = [] } = useDashboardData();
  const [slug, setSlug] = useState('');

  const brands = rows.map((r) => r.brand).filter((b) => !!b?.slug);

  return (
    <AppLayout title="Reports">
      <Card style={{ padding: 22, maxWidth: 560 }}>
        <div className="muted text-sm" style={{ marginBottom: 16 }}>
          Pick a brand and a report to generate a client-ready, white-label report you can edit and send.
        </div>

        <label className="text-xs muted" style={{ display: 'block', marginBottom: 6 }}>Brand</label>
        <select
          value={slug}
          onChange={(e) => setSlug(e.target.value)}
          style={{
            width: '100%',
            padding: '9px 10px',
            marginBottom: 18,
            borderRadius: 8,
            border: '1px solid var(--border)',
            background: 'var(--surface, #fff)',
            color: 'inherit',
          }}
        >
          <option value="">Select a brand…</option>
          {brands.map((b) => (
            <option key={b.slug} value={b.slug}>{b.name}</option>
          ))}
        </select>

        <div className="text-xs muted" style={{ marginBottom: 8 }}>Report</div>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
          {types.length === 0 && <div className="muted text-sm">No report types available.</div>}
          {types.map((t) => (
            <Button
              key={t.key}
              variant="secondary"
              disabled={!slug}
              onClick={() => navigate(`/brands/${slug}/reports/${t.key}`)}
            >
              {t.label}
            </Button>
          ))}
        </div>
      </Card>
    </AppLayout>
  );
}
