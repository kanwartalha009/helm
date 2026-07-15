import { Button } from '@/components/ui';
import { useCountryTiers } from '@/hooks/useCountryTiers';

/**
 * M5 addendum (Kanwar, 2026-07-15) — the compact read-only strip that
 * replaces the old inline CountryTiersSection form on the brand Settings
 * tab now that CountryTierDrawer is the real editing surface. Read is
 * brand-visible for everyone (same as the CRUD routes' own RBAC split) —
 * the "Manage tiers" button just opens the drawer; the drawer itself hides
 * every edit control when the viewer isn't admin/manager.
 */
export function CountryTiersSummary({ slug, onManage }: { slug?: string; onManage: () => void }) {
  const { data } = useCountryTiers(slug);
  const tiers = data?.tiers ?? [];
  const assignedCount = tiers.reduce((n, t) => n + t.countries.length, 0);

  return (
    <div className="field">
      <label className="field-label">Country tiers</label>
      <span className="field-hint">
        {data?.hasOverride ? 'This brand has its own tier set.' : 'Following the agency default set.'}
        {' '}A country not assigned to any tier reads as "Other" — never dropped from a report.
      </span>

      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginTop: 8, flexWrap: 'wrap' }}>
        {tiers.length === 0 && <span className="muted text-sm">No tiers configured yet.</span>}
        {tiers.map((t) => (
          <span
            key={t.tierKey}
            className="chip"
            style={{ background: `${t.color}1a`, borderColor: t.color }}
            title={`${t.countries.length} ${t.countries.length === 1 ? 'country' : 'countries'}`}
          >
            {t.label} <span className="muted" style={{ fontSize: 10 }}>· {t.countries.length}</span>
          </span>
        ))}
        {tiers.length > 0 && (
          <span className="muted text-sm">{assignedCount} {assignedCount === 1 ? 'country' : 'countries'} assigned</span>
        )}
      </div>

      <div style={{ marginTop: 10 }}>
        <Button size="sm" variant="secondary" type="button" onClick={onManage}>
          Manage tiers
        </Button>
      </div>
    </div>
  );
}
