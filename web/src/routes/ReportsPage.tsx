import { useMemo, useState } from 'react';
import { AppLayout } from '@/components/shell/AppLayout';
import { Card, Input, Segmented } from '@/components/ui';
import { useReportTypes } from '@/hooks/useReports';
import { useDashboardData } from '@/hooks/useDashboardData';
import { useCurrentUser } from '@/hooks/useSettings';
import { useUsers } from '@/hooks/useApiData';
import { formatMoney } from '@/lib/formatters';

/**
 * Report picker — REPORT-FIRST flow (2026-07-10 rework, full-width redesign):
 * choose the report type, then the brand. The brand list is manager-filterable
 * (same filter as the dashboard/ads hub) and arrives BEST SELLERS FIRST — it
 * preserves the dashboard engine's rolling-revenue ordering, no client-side
 * re-sort. Picking a brand opens the report in a NEW TAB so the picker stays
 * put for the next report. Per-brand report links elsewhere keep working as
 * deep links. For the ads-audit type, a platform picker narrows the audit to
 * one platform (deep-linked as ?platform=).
 */

const TYPE_DESCRIPTIONS: Record<string, string> = {
  'overall-performance': 'The full periodic client report — revenue vs spend vs ROAS with commerce splits, ads audit and dead stock.',
  monthly: 'The last complete calendar month, MoM + YoY — the month-close deliverable.',
  weekly: 'The Monday email — last complete week, WoW deltas, campaign movers and the action plan.',
  creatives: 'Creative winners, fatigue and watch depth — what to scale, cap or refresh.',
  'ads-audit': "Platform-by-platform campaign audit — what's winning, what's burning spend, what to do about it. Filter to one platform to send a Meta-only or Google-only audit.",
};

type PlatformChoice = 'all' | 'meta' | 'google' | 'tiktok';

export function ReportsPage() {
  const { data: types = [] } = useReportTypes();
  const { data: user } = useCurrentUser();
  const canFilterByManager = user?.role === 'master_admin' || user?.role === 'manager';

  const [typeKey, setTypeKey] = useState<string | null>(null);
  const [manager, setManager] = useState<string>('me');
  const [query, setQuery] = useState('');
  const [platform, setPlatform] = useState<PlatformChoice>('all');

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

  // The revenue bars scale against the biggest earner in view.
  const maxRevenue = useMemo(
    () => filtered.reduce((m, b) => Math.max(m, b.revenue ?? 0), 0),
    [filtered],
  );

  const openReport = (slug: string) => {
    if (!typeKey) return;
    const suffix = typeKey === 'ads-audit' && platform !== 'all' ? `?platform=${platform}` : '';
    window.open(`/brands/${slug}/reports/${typeKey}${suffix}`, '_blank', 'noopener');
  };

  const selectedType = types.find((t) => t.key === typeKey);

  return (
    <AppLayout title="Reports">
      <style>{PICK_CSS}</style>

      {/* Step 1 — the report */}
      <div className="text-xs muted" style={{ margin: '4px 0 10px', fontWeight: 600, letterSpacing: '0.04em', textTransform: 'uppercase' }}>
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
            <span className="rpt-type-kicker">Report</span>
            <span className="rpt-type-name">{t.label}</span>
            <span className="rpt-type-desc">{TYPE_DESCRIPTIONS[t.key] ?? 'Client-ready white-label report.'}</span>
          </button>
        ))}
        {types.length === 0 && <div className="muted text-sm">No report types available.</div>}
      </div>
      <div className="rpt-pick-foot muted">
        {types.length} report type{types.length === 1 ? '' : 's'} · reports open in a new tab · share links are read-only
      </div>

      {/* Step 2 — the brand */}
      {typeKey && (
        <Card style={{ padding: 20, marginTop: 18 }}>
          <div className="text-xs muted" style={{ marginBottom: 10, fontWeight: 600, letterSpacing: '0.04em', textTransform: 'uppercase' }}>
            2 · Choose a brand — opens {selectedType?.label ?? 'the report'} in a new tab
          </div>

          <div className="flex items-center gap-8" style={{ marginBottom: 4, flexWrap: 'wrap' }}>
            <div style={{ flex: 1, minWidth: 240 }}>
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
            {typeKey === 'ads-audit' && (
              <Segmented
                options={[
                  { value: 'all', label: 'All platforms' },
                  { value: 'meta', label: 'Meta' },
                  { value: 'google', label: 'Google' },
                  { value: 'tiktok', label: 'TikTok' },
                ]}
                value={platform}
                onChange={(v) => setPlatform(v as PlatformChoice)}
              />
            )}
          </div>

          <div className="brand-pick-list" role="listbox" aria-label="Brands">
            {filtered.length === 0 && (
              <div className="muted text-sm" style={{ padding: '10px 12px' }}>
                No brands match “{query}”.
              </div>
            )}
            {filtered.map((b, i) => (
              <button key={b.slug} type="button" role="option" className="brand-pick-row" onClick={() => openReport(b.slug)}>
                <span className="brand-pick-rank">{String(i + 1).padStart(2, '0')}</span>
                <span className="brand-pick-main">
                  <span className="brand-pick-name">{b.name}</span>
                  {b.revenue !== null && maxRevenue > 0 && (
                    <span className="brand-pick-bar">
                      <span style={{ width: `${Math.max(1, Math.round(((b.revenue ?? 0) / maxRevenue) * 100))}%` }} />
                    </span>
                  )}
                </span>
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
.rpt-type-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px}
.rpt-type-card{display:flex;flex-direction:column;gap:5px;text-align:left;padding:16px 18px;background:var(--surface,transparent);
  border:1px solid var(--border);border-radius:12px;color:inherit;cursor:pointer;font-family:inherit;
  transition:border-color .12s ease,transform .12s ease,background .12s ease}
.rpt-type-card:hover{border-color:var(--accent,#1f6f5c);transform:translateY(-1px)}
.rpt-type-card:focus-visible{outline:2px solid var(--accent,#1f6f5c);outline-offset:2px}
.rpt-type-card.is-selected{border-color:var(--accent,#1f6f5c);background:color-mix(in srgb,var(--accent,#1f6f5c) 7%,var(--surface,#fff))}
.rpt-type-kicker{font-size:10px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--text-secondary,#6b6f76)}
.rpt-type-card.is-selected .rpt-type-kicker{color:var(--accent,#1f6f5c)}
.rpt-type-name{font-weight:600;font-size:15px}
.rpt-type-desc{font-size:12.5px;line-height:1.45;color:var(--text-secondary,#6b6f76)}
.rpt-pick-foot{font-size:12px;margin-top:10px}
.brand-pick-list{margin-top:10px;max-height:60vh;overflow-y:auto;border:1px solid var(--border);border-radius:8px}
.brand-pick-row{display:flex;align-items:center;gap:14px;width:100%;text-align:left;
  padding:10px 14px;background:transparent;border:0;border-bottom:1px solid var(--border);color:inherit;
  font-size:13.5px;line-height:1.3;cursor:pointer;font-family:inherit}
.brand-pick-row:last-child{border-bottom:0}
.brand-pick-row:hover{background:var(--surface-subtle,rgba(0,0,0,.03))}
.brand-pick-row:focus-visible{outline:2px solid var(--accent,#1f6f5c);outline-offset:-2px}
.brand-pick-rank{font-size:11px;font-weight:600;color:var(--text-secondary,#6b6f76);font-variant-numeric:tabular-nums;min-width:22px}
.brand-pick-main{flex:1;min-width:0;display:flex;flex-direction:column;gap:5px}
.brand-pick-name{font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.brand-pick-bar{display:block;height:4px;max-width:420px;background:transparent;border-radius:2px;overflow:hidden}
.brand-pick-bar span{display:block;height:100%;background:var(--accent,#1f6f5c);opacity:.45;border-radius:2px;min-width:2px}
.brand-pick-rev{font-size:12px;color:var(--text-secondary,#6b6f76);white-space:nowrap;font-variant-numeric:tabular-nums}
`;
