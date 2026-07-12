import { useEffect, useState } from 'react';
import { Button } from '@/components/ui';
import { useBrandTargets, useSaveBrandTargets } from '@/hooks/useTargets';
import { toast } from '@/stores/toastStore';

/**
 * Brand goals (Bosco spec §A.2) — lives in the brand Settings tab, where Bosco asked
 * for it.
 *
 * v1 writes the STANDING DEFAULT only (month = null): one goal that applies to every
 * month until someone overrides a specific one. Per-month overrides are already possible
 * in the schema — GO-2 §5.1 will expose them — but shipping a month picker now would ask
 * the operator to re-enter the same number twelve times a year.
 *
 * Empty means unset, not zero: leaving a field blank hides that goal's tracking entirely
 * rather than rendering a 0% bar, which would read as failure rather than absence.
 */
export function GoalsSection({ slug, canEdit }: { slug?: string; canEdit: boolean }) {
  const { data } = useBrandTargets(slug);
  const save = useSaveBrandTargets(slug);

  const [revenue, setRevenue] = useState('');
  const [roas, setRoas] = useState('');
  const [spendCap, setSpendCap] = useState('');

  const t = data?.target;
  useEffect(() => {
    setRevenue(t?.revenueTarget != null ? String(t.revenueTarget) : '');
    setRoas(t?.roasTarget != null ? String(t.roasTarget) : '');
    setSpendCap(t?.spendCap != null ? String(t.spendCap) : '');
  }, [t?.revenueTarget, t?.roasTarget, t?.spendCap]);

  const num = (s: string): number | null => (s.trim() === '' ? null : Number(s));

  const onSave = () => {
    const r = num(roas);
    if (r !== null && (r < 0 || r > 100)) {
      toast.error('Check the ROAS target', 'A ROAS above 100× is almost always a typo.');
      return;
    }

    save.mutate(
      // month omitted → the STANDING DEFAULT goal.
      { revenue_target: num(revenue), roas_target: r, spend_cap: num(spendCap) },
      {
        onSuccess: () => toast.success('Goals saved', 'Pacing now shows on the brand overview.'),
        onError: () => toast.error('Could not save goals', 'Admins and managers only.'),
      },
    );
  };

  return (
    <>
      <div className="field" style={{ marginTop: 8 }}>
        <label className="field-label">Goals</label>
        <span className="field-hint">
          Used for pacing on the brand overview and the dashboard. Leave a field empty to hide that goal’s
          tracking — an empty goal is unset, not zero.
        </span>
      </div>

      <div className="form-grid form-grid-2">
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
          <span className="field-hint">In the brand’s own currency.</span>
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

        {canEdit && (
          <div className="field" style={{ alignSelf: 'end' }}>
            <Button size="sm" variant="secondary" type="button" disabled={save.isPending} onClick={onSave}>
              {save.isPending ? 'Saving…' : 'Save goals'}
            </Button>
          </div>
        )}
      </div>
    </>
  );
}
