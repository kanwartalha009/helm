import { useEffect, useState } from 'react';
import { Button, Drawer } from '@/components/ui';
import { useBrandTargets, useSaveBrandTargets } from '@/hooks/useTargets';
import { toast } from '@/stores/toastStore';

/**
 * M5 addendum (Kanwar, 2026-07-15 — "move goals from settings to sidebar as
 * well... in report section of goals connect so it will be easier to
 * manage"). Same slide-over pattern just built for country tiers
 * (CountryTierDrawer) — SUPERSEDES the old always-rendered GoalsSection.tsx
 * form on brand Settings (Bosco §A.2's original placement), which is now
 * retired. Two entry points share this ONE component: a "Manage goals"
 * button on brand Settings (GoalsSummary), and an "Edit goals" button
 * directly on the mom report's S-GOALS card (SGoals.tsx) — the report is now
 * the tighter loop: see the number, open the drawer, fix the target, close.
 *
 * Deliberately much simpler than CountryTierDrawer — goals are 3 scalar
 * fields (STANDING DEFAULT only, month omitted, same as the original
 * GoalsSection), not a list of rows needing add/remove/move semantics.
 */
export function GoalsDrawer({
  slug,
  canEdit,
  open,
  onClose,
}: {
  slug: string | undefined;
  canEdit: boolean;
  open: boolean;
  onClose: () => void;
}) {
  const { data } = useBrandTargets(slug);
  const save = useSaveBrandTargets(slug);

  const [revenue, setRevenue] = useState('');
  const [roas, setRoas] = useState('');
  const [spendCap, setSpendCap] = useState('');

  const t = data?.target;
  useEffect(() => {
    if (!open) return; // re-seed fresh every time the drawer opens, never mid-edit
    setRevenue(t?.revenueTarget != null ? String(t.revenueTarget) : '');
    setRoas(t?.roasTarget != null ? String(t.roasTarget) : '');
    setSpendCap(t?.spendCap != null ? String(t.spendCap) : '');
  }, [open, t?.revenueTarget, t?.roasTarget, t?.spendCap]);

  const num = (s: string): number | null => (s.trim() === '' ? null : Number(s));

  const onSave = () => {
    const r = num(roas);
    if (r !== null && (r < 0 || r > 100)) {
      toast.error('Check the ROAS target', 'A ROAS above 100× is almost always a typo.');
      return;
    }

    save.mutate(
      // month omitted → the STANDING DEFAULT goal, same contract as before.
      { revenue_target: num(revenue), roas_target: r, spend_cap: num(spendCap) },
      {
        onSuccess: () => toast.success('Goals saved', 'Pacing and the report’s S-GOALS section pick this up immediately.'),
        onError: () => toast.error('Could not save goals', 'Admins and managers only.'),
      },
    );
  };

  return (
    <Drawer
      open={open}
      onClose={onClose}
      size="sm"
      title="Goals"
      footer={
        canEdit ? (
          <Button size="sm" variant="secondary" type="button" disabled={save.isPending} onClick={onSave}>
            {save.isPending ? 'Saving…' : 'Save goals'}
          </Button>
        ) : undefined
      }
    >
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
