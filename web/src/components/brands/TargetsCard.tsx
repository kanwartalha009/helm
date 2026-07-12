import { useEffect, useState } from 'react';
import { Button, Card } from '@/components/ui';
import { useBrandTargets, useSaveBrandTargets } from '@/hooks/useTargets';
import type { PacingMetric } from '@/hooks/useTargets';
import { formatMoney } from '@/lib/formatters';
import { toast } from '@/stores/toastStore';

const STATUS_COLOR: Record<string, string> = {
  on_pace: 'var(--success, #1f6f5c)',
  behind:  'var(--danger, #b3261e)',
  over:    'var(--warning, #9a6700)',
  unknown: 'var(--text-secondary)',
};

/**
 * Monthly targets + pacing (GO-2.1).
 *
 * The pace line is target × (complete days ÷ days in month). It counts only days that
 * have finished AND synced — so a brand is never shown as "behind" merely because
 * today isn't over yet. The caption says how many days are actually being measured,
 * so the number can always be checked by hand.
 */
export function TargetsCard({ slug, canEdit }: { slug?: string; canEdit: boolean }) {
  const { data } = useBrandTargets(slug);
  const save = useSaveBrandTargets(slug);
  const [editing, setEditing] = useState(false);

  const [revenue, setRevenue] = useState('');
  const [spendCap, setSpendCap] = useState('');
  const [mer, setMer] = useState('');

  const t = data?.target;
  useEffect(() => {
    setRevenue(t?.revenueTarget != null ? String(t.revenueTarget) : '');
    setSpendCap(t?.spendCap != null ? String(t.spendCap) : '');
    setMer(t?.merTarget != null ? String(t.merTarget) : '');
  }, [t?.revenueTarget, t?.spendCap, t?.merTarget]);

  if (!data) return null;

  const p = data.pacing;
  const num = (s: string): number | null => (s.trim() === '' ? null : Number(s));

  const onSave = () => {
    save.mutate(
      { month: data.month, revenue_target: num(revenue), spend_cap: num(spendCap), mer_target: num(mer) },
      { onSuccess: () => { setEditing(false); toast.success('Targets saved', data.month); },
        onError: () => toast.error('Could not save targets', 'Admins and managers only.') },
    );
  };

  return (
    <Card style={{ padding: 16, marginBottom: 16 }}>
      <div className="flex items-center justify-between mb-8" style={{ flexWrap: 'wrap', gap: 8 }}>
        <div style={{ fontWeight: 600 }}>Targets — {data.month}</div>
        {canEdit && !editing && (
          <button type="button" className="text-sm" style={{ background: 'none', border: 0, cursor: 'pointer', color: 'var(--accent)' }} onClick={() => setEditing(true)}>
            {t ? 'Edit' : 'Set targets'}
          </button>
        )}
      </div>

      {editing ? (
        <div className="form-grid form-grid-2" style={{ gap: 10 }}>
          <div className="field">
            <label className="field-label">Revenue target</label>
            <input className="input" type="number" min={0} value={revenue} onChange={(e) => setRevenue(e.target.value)} placeholder="e.g. 30000" />
          </div>
          <div className="field">
            <label className="field-label">Spend cap</label>
            <input className="input" type="number" min={0} value={spendCap} onChange={(e) => setSpendCap(e.target.value)} placeholder="optional" />
          </div>
          <div className="field">
            <label className="field-label">MER target</label>
            <input className="input" type="number" min={0} step="0.1" value={mer} onChange={(e) => setMer(e.target.value)} placeholder="e.g. 3" />
            <span className="field-hint">Store-truth MER (revenue ÷ total ad spend), not platform-reported ROAS.</span>
          </div>
          <div className="field" style={{ alignSelf: 'end' }}>
            <div className="flex items-center gap-8">
              <Button size="sm" variant="primary" disabled={save.isPending} onClick={onSave}>Save</Button>
              <button type="button" className="muted text-sm" style={{ background: 'none', border: 0, cursor: 'pointer' }} onClick={() => setEditing(false)}>cancel</button>
            </div>
            <span className="field-hint">Leave a field empty to leave that target unset — Helm never invents a goal.</span>
          </div>
        </div>
      ) : !p || !p.revenue ? (
        <div className="muted text-sm">
          No revenue target set for {data.month}. Without one there is nothing to pace against — Helm won't invent a goal.
        </div>
      ) : (
        <>
          <PaceRow label="Revenue" m={p.revenue} currency={p.currency} />
          {p.spend && <PaceRow label="Ad spend vs cap" m={p.spend} currency={p.currency} lowerIsBetter />}
          {p.roas && (
            <div className="flex items-center justify-between text-sm" style={{ marginTop: 6 }}>
              <span className="muted">MER vs target</span>
              <span style={{ color: STATUS_COLOR[p.roas.status] }}>
                {p.roas.actual === null ? '—' : `${p.roas.actual.toFixed(2)}×`} / {p.roas.target.toFixed(2)}×
              </span>
            </div>
          )}

          <div className="text-xs muted mt-8">
            Day {p.completeDays} of {p.daysInMonth} — pacing counts only days that have finished and synced
            {p.dataThrough ? ` (through ${p.dataThrough})` : ''}, so a brand is never called “behind” just because
            today isn’t over.
          </div>
        </>
      )}
    </Card>
  );
}

function PaceRow({ label, m, currency, lowerIsBetter }: { label: string; m: PacingMetric; currency: string; lowerIsBetter?: boolean }) {
  const color = STATUS_COLOR[m.status] ?? 'var(--text-secondary)';
  const word =
    m.status === 'unknown' ? 'nothing measured yet'
    : m.status === 'on_pace' ? 'on pace'
    : m.status === 'over' ? `over by ${formatMoney(Math.abs(m.delta), currency)}`
    : `behind by ${formatMoney(Math.abs(m.delta), currency)}`;

  return (
    <div style={{ marginTop: 8 }}>
      <div className="flex items-center justify-between text-sm">
        <span className="muted">{label}</span>
        <span>
          {formatMoney(m.actual, currency)} / {formatMoney(m.target, currency)}
          {m.pctOfTarget !== null && <span className="muted text-xs"> · {m.pctOfTarget}%</span>}
        </span>
      </div>
      <div className="flex items-center justify-between text-xs" style={{ marginTop: 2 }}>
        <span className="muted">
          expected by now {formatMoney(m.expectedNow, currency)}{lowerIsBetter ? ' (cap pace)' : ''}
        </span>
        <span style={{ color, fontWeight: 600 }}>{word}</span>
      </div>
    </div>
  );
}
