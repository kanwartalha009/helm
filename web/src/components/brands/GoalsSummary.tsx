import { Button } from '@/components/ui';
import { formatMoney, formatRoas } from '@/lib/formatters';
import { useBrandTargets } from '@/hooks/useTargets';

/**
 * M5 addendum (Kanwar, 2026-07-15) — the compact read-only strip that
 * replaces the old always-rendered GoalsSection form on brand Settings now
 * that GoalsDrawer is the real editing surface (same move as
 * CountryTiersSummary/CountryTierDrawer for tiers). Read is brand-visible
 * for everyone; "Manage goals" just opens the drawer, which itself hides the
 * Save control when the viewer isn't admin/manager.
 */
export function GoalsSummary({ slug, currency = 'USD', onManage }: { slug?: string; currency?: string; onManage: () => void }) {
  const { data } = useBrandTargets(slug);
  const t = data?.target;
  const hasGoal = t?.revenueTarget != null || t?.roasTarget != null || t?.spendCap != null;

  return (
    <div className="field">
      <label className="field-label">Goals</label>
      <span className="field-hint">
        Used for pacing on the brand overview, the dashboard, and the mom report's S-GOALS section.
      </span>

      <div style={{ display: 'flex', alignItems: 'center', gap: 14, marginTop: 8, flexWrap: 'wrap' }}>
        {!hasGoal && <span className="muted text-sm">No goal set yet.</span>}
        {t?.revenueTarget != null && (
          <span className="chip">Revenue <span className="muted" style={{ fontSize: 10 }}>· {formatMoney(t.revenueTarget, currency, { compact: true })}/mo</span></span>
        )}
        {t?.roasTarget != null && (
          <span className="chip">ROAS <span className="muted" style={{ fontSize: 10 }}>· {formatRoas(t.roasTarget)}</span></span>
        )}
        {t?.spendCap != null && (
          <span className="chip">Spend cap <span className="muted" style={{ fontSize: 10 }}>· {formatMoney(t.spendCap, currency, { compact: true })}/mo</span></span>
        )}
      </div>

      <div style={{ marginTop: 10 }}>
        <Button size="sm" variant="secondary" type="button" onClick={onManage}>
          Manage goals
        </Button>
      </div>
    </div>
  );
}
