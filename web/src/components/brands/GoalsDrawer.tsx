import { useEffect, useMemo, useState } from 'react';
import { Button, Drawer } from '@/components/ui';
import { useBrandTargets, useDeleteBrandTarget, useSaveBrandTargets } from '@/hooks/useTargets';
import { toast } from '@/stores/toastStore';

/**
 * M5 addendum (Kanwar, 2026-07-15 — "move goals from settings to sidebar as
 * well... in report section of goals connect so it will be easier to
 * manage"). Same slide-over pattern just built for country tiers
 * (CountryTierDrawer). Two entry points share this ONE component: a "Manage
 * goals" button on brand Settings (GoalsSummary), and an "Edit goals" button
 * directly on the mom report's S-GOALS card (SGoals.tsx).
 *
 * Per-month goals (Kanwar, 2026-07-17 — "goals are monthly... allow to add
 * future and previous months' goals, in the May report we're showing the July
 * targets"): the drawer now has a SCOPE picker. Goals resolve as month-override
 * -> standing default (Pacing/BrandTargetController), so a value set as the
 * standing default silently applies to EVERY month — which is exactly how a
 * "July" number ended up showing in the May report. The picker lets you set a
 * goal for a specific past/current/future month, or the standing default, and
 * reads each scope's TRUE stored value (exact mode, no fallback) so an
 * inherited default is never mistaken for a real per-month goal. A Clear action
 * removes a month's override (or the standing default itself).
 */
function buildScopeOptions(defaultMonth?: string): { value: string; label: string }[] {
  const now = new Date();
  const opts: { value: string; label: string }[] = [
    { value: '__default', label: 'Standing default (all months)' },
  ];
  const seen = new Set<string>();
  const fmt = (d: Date) => d.toLocaleString('en-US', { month: 'long', year: 'numeric' });
  // Six months back through six months forward — covers a report you're
  // catching up on and goals you're setting ahead.
  for (let i = -6; i <= 6; i++) {
    const d = new Date(now.getFullYear(), now.getMonth() + i, 1);
    const ym = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
    seen.add(ym);
    opts.push({ value: ym, label: fmt(d) });
  }
  // Always let the report's own month be selectable, even if it's outside the window.
  if (defaultMonth && !seen.has(defaultMonth)) {
    const [y, m] = defaultMonth.split('-').map(Number);
    opts.push({ value: defaultMonth, label: fmt(new Date(y, m - 1, 1)) });
  }
  return opts;
}

export function GoalsDrawer({
  slug,
  canEdit,
  open,
  onClose,
  defaultMonth,
}: {
  slug: string | undefined;
  canEdit: boolean;
  open: boolean;
  onClose: () => void;
  /** Preselect this month's scope (e.g. the month the mom report is showing). */
  defaultMonth?: string;
}) {
  const scopeOptions = useMemo(() => buildScopeOptions(defaultMonth), [defaultMonth]);
  const [scope, setScope] = useState<string>(defaultMonth ?? '__default');
  const scopeLabel = scopeOptions.find((o) => o.value === scope)?.label ?? scope;
  const isDefaultScope = scope === '__default';

  // Exact read of THIS scope's own stored row (no standing-default fallback).
  const { data } = useBrandTargets(slug, isDefaultScope ? undefined : scope, true);
  const save = useSaveBrandTargets(slug);
  const del = useDeleteBrandTarget(slug);

  const [revenue, setRevenue] = useState('');
  const [roas, setRoas] = useState('');
  const [spendCap, setSpendCap] = useState('');

  // Reset the scope to the report's month each time the drawer opens.
  useEffect(() => {
    if (open) setScope(defaultMonth ?? '__default');
  }, [open, defaultMonth]);

  const t = data?.target;
  const hasStoredGoal = t != null;
  useEffect(() => {
    if (!open) return; // re-seed on open and whenever the scope's stored value changes
    setRevenue(t?.revenueTarget != null ? String(t.revenueTarget) : '');
    setRoas(t?.roasTarget != null ? String(t.roasTarget) : '');
    setSpendCap(t?.spendCap != null ? String(t.spendCap) : '');
  }, [open, scope, t?.revenueTarget, t?.roasTarget, t?.spendCap]);

  const num = (s: string): number | null => (s.trim() === '' ? null : Number(s));

  const onSave = () => {
    const r = num(roas);
    if (r !== null && (r < 0 || r > 100)) {
      toast.error('Check the ROAS target', 'A ROAS above 100× is almost always a typo.');
      return;
    }

    save.mutate(
      {
        // Omit month for the standing default; otherwise set THIS month's goal.
        month: isDefaultScope ? undefined : scope,
        revenue_target: num(revenue),
        roas_target: r,
        spend_cap: num(spendCap),
      },
      {
        onSuccess: () =>
          toast.success(
            'Goals saved',
            isDefaultScope
              ? 'Applies to every month without its own goal. Pacing and the report update immediately.'
              : `Set for ${scopeLabel}. Pacing and that month's report update immediately.`,
          ),
        onError: () => toast.error('Could not save goals', 'Admins and managers only.'),
      },
    );
  };

  const onClear = () => {
    del.mutate(isDefaultScope ? '__default' : scope, {
      onSuccess: () => {
        setRevenue('');
        setRoas('');
        setSpendCap('');
        toast.success(
          'Goal cleared',
          isDefaultScope ? 'The standing default is removed.' : `${scopeLabel}'s goal is removed.`,
        );
      },
      onError: () => toast.error('Could not clear goal', 'Admins and managers only.'),
    });
  };

  return (
    <Drawer
      open={open}
      onClose={onClose}
      size="sm"
      title="Goals"
      footer={
        canEdit ? (
          <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
            <Button size="sm" variant="secondary" type="button" disabled={save.isPending} onClick={onSave}>
              {save.isPending ? 'Saving…' : 'Save goals'}
            </Button>
            {hasStoredGoal && (
              <Button size="sm" variant="ghost" type="button" disabled={del.isPending} onClick={onClear}>
                {del.isPending ? 'Clearing…' : 'Clear this goal'}
              </Button>
            )}
          </div>
        ) : undefined
      }
    >
      <div className="field" style={{ marginBottom: 12 }}>
        <label className="field-label">Goal for</label>
        <select className="input" value={scope} onChange={(e) => setScope(e.target.value)} disabled={!canEdit}>
          {scopeOptions.map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </select>
        <span className="field-hint">
          {isDefaultScope
            ? 'The fallback goal for any month without its own target. Set month-specific goals below to override it.'
            : hasStoredGoal
              ? `A goal set specifically for ${scopeLabel}.`
              : `No goal set for ${scopeLabel} yet — until you set one, it inherits the standing default. Enter values to give this month its own target.`}
        </span>
      </div>

      <div className="field" style={{ marginBottom: 10 }}>
        <span className="field-hint">
          Used for pacing on the brand overview, the dashboard, and the mom report's S-GOALS section. Leave a field
          empty to hide that goal's tracking — an empty goal is unset, not zero.
        </span>
      </div>

      <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
        <div className="field">
          <label className="field-label">Target monthly revenue</label>
          <input
            className="input"
            type="number"
            min={0}
            step="1"
            value={revenue}
            onChange={(e) => setRevenue(e.target.value)}
            placeholder="e.g. 30000"
            disabled={!canEdit}
          />
          <span className="field-hint">In the brand's own currency.</span>
        </div>

        <div className="field">
          <label className="field-label">Target ROAS</label>
          <input
            className="input"
            type="number"
            min={0}
            max={100}
            step="0.1"
            value={roas}
            onChange={(e) => setRoas(e.target.value)}
            placeholder="e.g. 3.0"
            disabled={!canEdit}
          />
          <span className="field-hint">Blended revenue ÷ ad spend, computed in USD so it holds across currencies.</span>
        </div>

        <div className="field">
          <label className="field-label">Monthly spend cap (optional)</label>
          <input
            className="input"
            type="number"
            min={0}
            step="1"
            value={spendCap}
            onChange={(e) => setSpendCap(e.target.value)}
            placeholder="optional"
            disabled={!canEdit}
          />
        </div>
      </div>
    </Drawer>
  );
}
