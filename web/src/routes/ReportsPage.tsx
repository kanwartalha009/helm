import { useMemo, useState } from 'react';
import { AppLayout } from '@/components/shell/AppLayout';
import { Card, Input } from '@/components/ui';
import { useReportTypes } from '@/hooks/useReports';
import { useDashboardData } from '@/hooks/useDashboardData';
import { useCurrentUser } from '@/hooks/useSettings';
import { useUsers } from '@/hooks/useApiData';
import { formatMoney } from '@/lib/formatters';

/**
 * Report picker — REPORT-FIRST flow (2026-07-10 rework): choose the report
 * type, then the brand. The brand list is manager-filterable (same filter as
 * the dashboard/ads hub) and arrives BEST SELLERS FIRST — it preserves the
 * dashboard engine's rolling-revenue ordering, no client-side re-sort.
 * Picking a brand opens the report in a NEW TAB so the picker stays put for
 * the next report. Per-brand report links elsewhere keep working as deep
 * links.
 */

const TYPE_DESCRIPTIONS: Record<string, string> = {
  'overall-performance': 'The full periodic client report — revenue vs spend vs ROAS with commerce splits, ads audit and dead stock.',
  monthly: 'The last complete calendar month, MoM + YoY — the month-close deliverable.',
  weekly: 'The Monday email — last complete week, WoW deltas, campaign movers and the action plan.',
  creatives: 'Creative winners, fatigue and watch depth — what to scale, cap or refresh.',
};

export function ReportsPage() {
  const { data: types = [] } = useReportTypes();
  const { data: user } = useCurrentUser();
  const canFilterByManager = user?.role === 'master_admin' || user?.role === 'manager';

  const [typeKey, setTypeKey] = useState<string | null>(null);
  const [manager, setManager] = useState<string>('me');
  const [query, setQuery] = useState('');

  const { data: rows = [] } = useDashboardData(manager);
  const { data: managerUsers = [] } = useUsers(canFilterByManager);

  // Keep the engine's order (rolling revenue desc — best sellers first).
  const brands = useMemo(
    () =>
      rows
        .filter((r) => !!r.brand?.slug)
        .map((r) => ({
          slug: r.brand.slug,
          name: r.brand.name ?? r.brand.slug,
          currency: r.brand.baseCurrency,
          revenue: r.rolling?.revenue ?? null,
          windowDays: r.rolling?.windowDays ?? 7,
        })),
    [rows],
  );

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (!q) return brands;
    return brands.filter((b) => b.name.toLowerCase().includes(q) || b.slug.toLowerCase().includes(q));
  }, [brands, query]);

  const openReport = (slug: string) => {
    if (!typeKey) return;
    window.open(`/brands/${slug}/reports/${typeKey}`, '_blank', 'noopener');
  };

  const selectedType = types.find((t) => t.key === typeKey);

  return (
    <AppLayout title="Reports">
      <style>{PICK_CSS}</style>

      {/* Step 1 — the report */}
      <div className="text-xs muted" style={{ margin: '4px 0 8px', fontWeight: 600, letterSpacing: '0.04em', textTransform: 'uppercase' }}>
        1 · Choose a report
      </div>
      <div className="rpt-type-grid">
        {types.map((t) => (
          <button
            key={t.key}
            type="button"
            className={`rpt-type-card${typeKey === t.key ? ' is-selected' : ''}`}
            onClick={() => setTypeKey(t.key)}
          >
            <span className="rpt-type-name">{t.label}</span>
            <span className="rpt-type-desc">{TYPE_DESCRIPTIONS[t.key] ?? 'Client-ready white-label report.'}</span>
          </button>
        ))}
        {types.length === 0 && <div className="muted text-sm">No report types available.</div>}
      </div>

      {/* Step 2 — the brand */}
      {typeKey && (
        <Card style={{ padding: 20, maxWidth: 640, marginTop: 18 }}>
          <div className="text-xs muted" style={{ marginBottom: 10, fontWeight: 600, letterSpacing: '0.04em', textTransform: 'uppercase' }}>
            2 · Choose a brand — opens {selectedType?.label ?? 'the report'} in a new tab
          </div>

          <div className="flex items-center gap-8" style={{ marginBottom: 4 }}>
            <div style={{ flex: 1 }}>
              <Input
                placeholder="Search brands…"
                value={query}
                autoFocus
                hint={
                  (query ? `${filtered.length} of ${brands.length} brands` : `${brands.length} brands`) +
                  ' · best sellers first'
                }
                onChange={(e) => setQuery(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === 'Enter' && filtered.length > 0) {
                    e.preventDefault();
                    openReport(filtered[0].slug);
                  }
                }}
              />
            </div>
            {canFilterByManager && (
              <select
                className="input"
                style={{ maxWidth: 190 }}
                value={manager}
                onChange={(e) => setManager(e.target.value)}
                aria-label="Filter brands by manager"
              >
                <option value="me">My brands</option>
                <option value="all">All brands</option>
                <option value="unassigned">Unassigned</option>
                {managerUsers
                  .filter((u) => u.status !== 'invited')
                  .map((u) => (
                    <option key={u.id} value={String(u.id)}>
                      {u.name}
                    </option>
                  ))}
              </select>
            )}
          </div>

          <div className="brand-pick-list" role="listbox" aria-label="Brands">
            {filtered.length === 0 && (
              <div className="muted text-sm" style={{ padding: '10px 12px' }}>
                No brands match “{query}”.
              </div>
            )}
            {filtered.map((b) => (
              <button key={b.slug} type="button" role="option" className="brand-pick-row" onClick={() => openReport(b.slug)}>
                <span className="brand-pick-name">{b.name}</span>
                <span className="brand-pick-rev">
                  {b.revenue !== null ? `${formatMoney(b.revenue, b.currency)} · ${b.windowDays}d` : 'not synced'}
                </span>
              </button>
            ))}
          </div>
        </Card>
      )}
    </AppLayout>
  );
}

const PICK_CSS = `
.rpt-type-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:10px;max-width:840px}
.rpt-type-card{display:flex;flex-direction:column;gap:5px;text-align:left;padding:14px 16px;background:var(--surface,transparent);
  border:1px solid var(--border);border-radius:10px;color:inherit;cursor:pointer;font-family:inherit}
.rpt-type-card:hover{border-color:var(--accent,#1f6f5c)}
.rpt-type-card.is-selected{border-color:var(--accent,#1f6f5c);box-shadow:inset 0 0 0 1px var(--accent,#1f6f5c)}
.rpt-type-name{font-weight:600;font-size:14px}
.rpt-type-desc{font-size:12.5px;line-height:1.45;color:var(--text-secondary,#6b6f76)}
.brand-pick-list{margin-top:10px;max-height:300px;overflow-y:auto;border:1px solid var(--border);border-radius:8px}
.brand-pick-row{display:flex;align-items:center;justify-content:space-between;gap:10px;width:100%;text-align:left;
  padding:9px 12px;background:transparent;border:0;border-bottom:1px solid var(--border);color:inherit;
  font-size:13.5px;line-height:1.3;cursor:pointer;font-family:inherit}
.brand-pick-row:last-child{border-bottom:0}
.brand-pick-row:hover{background:rgba(0,0,0,.03)}
.brand-pick-row:focus-visible{outline:2px solid var(--accent,#1f6f5c);outline-offset:-2px}
.brand-pick-name{font-weight:500}
.brand-pick-rev{font-size:12px;color:var(--text-secondary,#6b6f76);white-space:nowrap}
`;
