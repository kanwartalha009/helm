import { useState } from 'react';
import { Button, Card } from '@/components/ui';
import { ANOMALY_LABEL, useBrandAnomalies, useDismissAnomaly } from '@/hooks/useAnomalies';
import type { AnomalyRow } from '@/hooks/useAnomalies';
import { toast } from '@/stores/toastStore';

const SEVERITY_COLOR: Record<string, string> = {
  critical: 'var(--danger, #b3261e)',
  warn:     'var(--warning, #9a6700)',
  info:     'var(--text-secondary)',
};

/**
 * Open anomalies for one brand (GO-2.4).
 *
 * Every alert shows its EVIDENCE inline — the number, its 28-day median, and the
 * threshold that fired it — so the operator can check the maths rather than take it on
 * faith. An alert you cannot verify is an alert you eventually learn to ignore.
 *
 * Dismissing requires typing a reason. That is deliberate friction: the reason is the
 * honesty record that GO-3's ledger will score Helm's own suggestions against.
 */
export function AnomalyStrip({ slug }: { slug?: string }) {
  const { data: rows } = useBrandAnomalies(slug);

  if (!rows || rows.length === 0) return null;

  return (
    <Card style={{ padding: 14, marginBottom: 16 }}>
      <div style={{ fontWeight: 600, marginBottom: 8 }}>
        {rows.length} open {rows.length === 1 ? 'anomaly' : 'anomalies'}
      </div>
      <div style={{ display: 'grid', gap: 10 }}>
        {rows.map((a) => <AnomalyItem key={a.id} slug={slug} a={a} />)}
      </div>
    </Card>
  );
}

function AnomalyItem({ slug, a }: { slug?: string; a: AnomalyRow }) {
  const dismiss = useDismissAnomaly(slug);
  const [open, setOpen] = useState(false);
  const [reason, setReason] = useState('');

  const color = SEVERITY_COLOR[a.severity] ?? 'var(--text-secondary)';
  const ev = a.evidence as Record<string, number | string | undefined>;

  const submit = () => {
    if (reason.trim().length < 3) return;
    dismiss.mutate(
      { id: a.id, reason: reason.trim() },
      { onSuccess: () => { setOpen(false); setReason(''); toast.success('Anomaly dismissed'); },
        onError: () => toast.error('Could not dismiss', 'A reason is required.') },
    );
  };

  return (
    <div style={{ borderLeft: `3px solid ${color}`, paddingLeft: 10 }}>
      <div className="flex items-center justify-between" style={{ gap: 8, flexWrap: 'wrap' }}>
        <span className="text-sm" style={{ fontWeight: 600 }}>
          {ANOMALY_LABEL[a.kind] ?? a.kind}
          {a.subject && <span className="muted"> · {a.subject}</span>}
        </span>
        <span className="flex items-center gap-8">
          <span className="muted text-xs">{a.date}</span>
          <button
            type="button"
            className="text-xs"
            style={{ background: 'none', border: 0, cursor: 'pointer', color: 'var(--accent)' }}
            onClick={() => setOpen(!open)}
          >
            dismiss
          </button>
        </span>
      </div>

      {/* The evidence, always. Numbers + median + threshold = a checkable claim. */}
      <div className="muted text-xs" style={{ marginTop: 2, lineHeight: 1.5 }}>
        {ev.actual !== undefined && ev.median28d !== undefined ? (
          <>
            {String(ev.actual)} vs a 28-day median of {String(ev.median28d)} ({String(ev.deltaPct)}% — the
            threshold is {String(ev.thresholdPct)}%).
          </>
        ) : (
          String(ev.note ?? '')
        )}
      </div>

      {open && (
        <div className="flex items-center gap-8 mt-8" style={{ flexWrap: 'wrap' }}>
          <input
            className="input"
            placeholder="Why is this not a problem? (required)"
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            onKeyDown={(e) => { if (e.key === 'Enter') submit(); }}
            style={{ maxWidth: 340 }}
            autoFocus
          />
          <Button size="sm" variant="secondary" disabled={reason.trim().length < 3 || dismiss.isPending} onClick={submit}>
            Dismiss
          </Button>
          <span className="muted text-xs">Recorded, so Helm can be scored on its own alerts later.</span>
        </div>
      )}
    </div>
  );
}
