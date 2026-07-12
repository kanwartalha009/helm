import { useState } from 'react';
import { Button, Card } from '@/components/ui';
import {
  useAcceptRecommendation,
  useDismissRecommendation,
  useRecommendations,
} from '@/hooks/useRecommendations';
import type { RecommendationRow, RecommendationsResponse } from '@/hooks/useRecommendations';
import { toast } from '@/stores/toastStore';

const KIND_COLOR: Record<string, string> = {
  pause:            'var(--danger, #b3261e)',
  fix:              'var(--warning, #9a6700)',
  budget_shift:     'var(--warning, #9a6700)',
  creative_refresh: 'var(--warning, #9a6700)',
  scale:            'var(--success, #1f6f5c)',
  launch:           'var(--success, #1f6f5c)',
  investigate:      'var(--text-secondary)',
};

/**
 * The Stop/Scale/Fix board (GO-3.2).
 *
 * ══ ACCEPT DOES NOT EXECUTE ANYTHING ══
 * Helm never touches an ad account. Accepting records the operator's decision and hands
 * them a checklist of what to do themselves in Ads Manager. The board says this on every
 * render, and the accept confirmation says it again — because the single worst outcome
 * here would be an operator believing a campaign got paused when it didn't.
 *
 * Every card shows its EVIDENCE expanded. The operator agrees (or refuses) on numbers
 * they can check, never on Helm's authority. And a refusal must state a reason — that
 * record is what lets the engine be scored honestly later (GO-3.3).
 */
export function RecommendationBoard({ slug, canDecide }: { slug?: string; canDecide: boolean }) {
  const { data } = useRecommendations(slug);

  if (!data) return null;

  const groups = data.kindOrder
    .map((kind) => ({ kind, rows: data.rows.filter((r) => r.kind === kind) }))
    .filter((g) => g.rows.length > 0);

  if (groups.length === 0) {
    return (
      <Card style={{ padding: 20, marginTop: 16 }}>
        <div style={{ fontWeight: 600, marginBottom: 4 }}>Nothing to act on</div>
        <div className="muted text-sm">
          No open recommendations for this brand. Helm only speaks when a rule fires on evidence it can show you.
        </div>
      </Card>
    );
  }

  return (
    <div style={{ marginTop: 16 }}>
      <div className="flex items-center justify-between mb-8" style={{ flexWrap: 'wrap', gap: 8 }}>
        <h3 className="section-title" style={{ margin: 0 }}>What to do</h3>
        <span className="muted text-xs">{data.rows.length} open</span>
      </div>

      {/* Said out loud, before anyone clicks anything. */}
      <div className="text-xs muted mb-16" style={{ maxWidth: 760, lineHeight: 1.55 }}>
        {data.executionNote}
      </div>

      <div style={{ display: 'grid', gap: 18 }}>
        {groups.map((g) => (
          <div key={g.kind}>
            <div
              className="text-xs"
              style={{
                fontWeight: 700, letterSpacing: '.06em', textTransform: 'uppercase',
                color: KIND_COLOR[g.kind] ?? 'var(--text-secondary)', marginBottom: 8,
              }}
            >
              {data.kindLabels[g.kind] ?? g.kind} · {g.rows.length}
            </div>
            <div style={{ display: 'grid', gap: 10 }}>
              {g.rows.map((r) => (
                <RecCard key={r.id} slug={slug} r={r} meta={data} canDecide={canDecide} />
              ))}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

function RecCard({
  slug, r, meta, canDecide,
}: { slug?: string; r: RecommendationRow; meta: RecommendationsResponse; canDecide: boolean }) {
  const accept = useAcceptRecommendation(slug);
  const dismiss = useDismissRecommendation(slug);
  const [dismissing, setDismissing] = useState(false);
  const [reason, setReason] = useState('');
  const [checklist, setChecklist] = useState<string[] | null>(null);

  const color = KIND_COLOR[r.kind] ?? 'var(--text-secondary)';

  const onAccept = () => {
    accept.mutate(r.id, {
      // The checklist is the point: it tells the operator what Helm did NOT do for them.
      onSuccess: (res) => { setChecklist(res.checklist); toast.success('Decision recorded', 'Now make the change in the ad platform.'); },
      onError: () => toast.error('Could not accept', 'It may already have been decided.'),
    });
  };

  const onDismiss = () => {
    if (reason.trim().length < 3) return;
    dismiss.mutate(
      { id: r.id, reason: reason.trim() },
      { onSuccess: () => { setDismissing(false); toast.success('Dismissed', 'Your reason is on the record.'); },
        onError: () => toast.error('Could not dismiss', 'A reason is required.') },
    );
  };

  return (
    <Card style={{ padding: 14, borderLeft: `3px solid ${color}` }}>
      <div className="flex items-center justify-between" style={{ gap: 8, flexWrap: 'wrap' }}>
        <span style={{ fontWeight: 600 }}>{r.title}</span>
        <span className="flex items-center gap-8">
          {r.confidence === 'early' && (
            <span className="text-xs" title="Below the solid-evidence spend floor — a signal, not a verdict." style={{ color: 'var(--warning, #9a6700)', fontWeight: 600 }}>
              early signal
            </span>
          )}
          <span className="muted text-xs">{r.source.replace('_', ' ')}</span>
        </span>
      </div>

      {/* Evidence, expanded. Agree on numbers, not on authority. */}
      <div className="muted text-xs" style={{ marginTop: 6, lineHeight: 1.55 }}>
        <Evidence evidence={r.evidence} />
      </div>

      {checklist && (
        <div style={{ marginTop: 10, padding: 10, borderRadius: 8, background: 'var(--surface-subtle)' }}>
          <div className="text-xs" style={{ fontWeight: 700, marginBottom: 4 }}>Now do this yourself:</div>
          <ul style={{ margin: 0, paddingLeft: 18 }}>
            {checklist.map((c, i) => <li key={i} className="text-xs muted" style={{ lineHeight: 1.6 }}>{c}</li>)}
          </ul>
          <div className="text-xs muted" style={{ marginTop: 6 }}>{meta.executionNote}</div>
        </div>
      )}

      {canDecide && !checklist && (
        <div className="flex items-center gap-8 mt-8" style={{ flexWrap: 'wrap' }}>
          {!dismissing ? (
            <>
              <Button size="sm" variant="secondary" disabled={accept.isPending} onClick={onAccept}>
                Accept
              </Button>
              <button
                type="button"
                className="muted text-xs"
                style={{ background: 'none', border: 0, cursor: 'pointer' }}
                onClick={() => setDismissing(true)}
              >
                dismiss
              </button>
              <span className="muted text-xs">Accepting records your decision — it changes nothing in the ad platform.</span>
            </>
          ) : (
            <>
              <input
                className="input"
                placeholder="Why is this wrong? (required)"
                value={reason}
                onChange={(e) => setReason(e.target.value)}
                onKeyDown={(e) => { if (e.key === 'Enter') onDismiss(); if (e.key === 'Escape') setDismissing(false); }}
                style={{ maxWidth: 320 }}
                autoFocus
              />
              <Button size="sm" variant="secondary" disabled={reason.trim().length < 3 || dismiss.isPending} onClick={onDismiss}>
                Dismiss
              </Button>
              <span className="muted text-xs">Recorded — it is how Helm gets scored on its own advice.</span>
            </>
          )}
        </div>
      )}
    </Card>
  );
}

/** Render the evidence json in plain language, without pretending to know every shape. */
function Evidence({ evidence }: { evidence: Record<string, unknown> }) {
  const e = evidence as Record<string, string | number | string[] | undefined>;

  if (e.actual !== undefined && e.median28d !== undefined) {
    return (
      <>
        {String(e.actual)} vs a 28-day median of {String(e.median28d)} ({String(e.deltaPct)}% — threshold{' '}
        {String(e.thresholdPct)}%).
      </>
    );
  }

  if (Array.isArray(e.matchedTerms)) {
    return (
      <>
        Matched <b>{(e.matchedTerms as string[]).join(', ')}</b>. {String(e.seasonLabel ?? '')} ended{' '}
        {String(e.seasonEnded ?? '')} — {String(e.daysStale ?? '')} days ago; spent {String(e.spendLast7d ?? '')} since.
      </>
    );
  }

  return <>{String(e.body ?? e.detail ?? e.note ?? e.rule ?? '')}</>;
}
