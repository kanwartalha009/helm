import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { AppLayout } from '@/components/shell/AppLayout';
import { Button, Card, Input } from '@/components/ui';
import { useReportTypes } from '@/hooks/useReports';
import { useDashboardData } from '@/hooks/useDashboardData';

/**
 * Report picker — search a brand, pick a report type, generate. The brand list
 * reuses the dashboard query (the user's accessible brands); with 70+ brands a
 * type-to-filter list beats a long dropdown. Report types come from the
 * registry, so new types appear here automatically.
 */
export function ReportsPage() {
  const navigate = useNavigate();
  const { data: types = [] } = useReportTypes();
  const { data: rows = [] } = useDashboardData();
  const [slug, setSlug] = useState('');
  const [query, setQuery] = useState('');

  const brands = useMemo(
    () => rows.map((r) => r.brand).filter((b): b is NonNullable<typeof b> => !!b?.slug),
    [rows],
  );

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (!q) return brands;
    return brands.filter(
      (b) => (b.name ?? '').toLowerCase().includes(q) || b.slug.toLowerCase().includes(q),
    );
  }, [brands, query]);

  const selected = brands.find((b) => b.slug === slug);

  return (
    <AppLayout title="Reports">
      <style>{PICK_CSS}</style>
      <Card style={{ padding: 22, maxWidth: 560 }}>
        <div className="muted text-sm" style={{ marginBottom: 16 }}>
          Pick a brand and a report to generate a client-ready, white-label report you can edit and send.
        </div>

        <Input
          label="Brand"
          placeholder="Search brands…"
          value={query}
          autoFocus
          hint={query ? `${filtered.length} of ${brands.length} brands` : `${brands.length} brands`}
          onChange={(e) => setQuery(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === 'Enter' && filtered.length > 0) {
              e.preventDefault();
              setSlug(filtered[0].slug);
            }
          }}
        />

        <div className="brand-pick-list" role="listbox" aria-label="Brands">
          {filtered.length === 0 && (
            <div className="muted text-sm" style={{ padding: '10px 12px' }}>
              No brands match “{query}”.
            </div>
          )}
          {filtered.map((b) => (
            <button
              key={b.slug}
              type="button"
              role="option"
              aria-selected={b.slug === slug}
              className={`brand-pick-row${b.slug === slug ? ' is-selected' : ''}`}
              onClick={() => setSlug(b.slug)}
            >
              <span className="brand-pick-name">{b.name ?? b.slug}</span>
              {b.slug === slug && <span className="brand-pick-check">Selected</span>}
            </button>
          ))}
        </div>

        <div className="text-xs muted" style={{ margin: '18px 0 8px' }}>
          Report{selected ? ` · ${selected.name ?? selected.slug}` : ''}
        </div>
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

const PICK_CSS = `
.brand-pick-list{margin-top:10px;max-height:264px;overflow-y:auto;border:1px solid var(--border);border-radius:8px}
.brand-pick-row{display:flex;align-items:center;justify-content:space-between;gap:10px;width:100%;text-align:left;
  padding:9px 12px;background:transparent;border:0;border-bottom:1px solid var(--border);color:inherit;
  font-size:13.5px;line-height:1.3;cursor:pointer}
.brand-pick-row:last-child{border-bottom:0}
.brand-pick-row:hover{background:rgba(0,0,0,.03)}
.brand-pick-row:focus-visible{outline:2px solid var(--accent,#1f6f5c);outline-offset:-2px}
.brand-pick-row.is-selected{background:var(--accent-soft,#eaf3f0);box-shadow:inset 2px 0 0 var(--accent,#1f6f5c)}
.brand-pick-name{font-weight:500}
.brand-pick-check{font-size:10px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--accent,#1f6f5c)}
`;
