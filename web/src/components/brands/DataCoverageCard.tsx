import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Button, Card } from '@/components/ui';
import { useDataCoverage, useTriggerBackfill } from '@/hooks/useApiData';
import type { CoverageDataset } from '@/hooks/useApiData';

/**
 * Onboarding data coverage (2026-07-10). Renders ONLY when a connected
 * platform is missing history against the 12-month target or a backfill is
 * in flight — a fully covered brand never sees this card.
 *
 * Click feedback contract (2026-07-10 follow-up): the button flips to a
 * queued state IMMEDIATELY (local optimistic set — the history dataset can't
 * report "running" until its fan-out job writes sync_logs), a success toast
 * fires from the trigger hook, the card polls while anything is pending, and
 * each row disappears on its own once coverage reaches the target. A run
 * that finishes while a gap remains means the platform simply has no older
 * data — that renders as a terminal note, not an eternal button.
 */
export function DataCoverageCard({ slug, compact = false }: { slug: string | undefined; compact?: boolean }) {
  const { data } = useDataCoverage(slug);
  const trigger = useTriggerBackfill(slug);

  // Datasets clicked this page-visit, shown as queued until the server
  // reports running (tracked runs) or the gap clears (history).
  const [justTriggered, setJustTriggered] = useState<Set<string>>(new Set());

  useEffect(() => {
    if (!data) return;
    setJustTriggered((prev) => {
      if (prev.size === 0) return prev;
      const next = new Set(prev);
      for (const d of data.datasets) {
        // Server truth has caught up — running flag set, or the gap resolved.
        if (next.has(d.key) && (d.running || !d.needsBackfill)) next.delete(d.key);
      }
      return next.size === prev.size ? prev : next;
    });
  }, [data]);

  if (!data || !data.anyGap) return null;

  const visible = data.datasets.filter((d) => d.relevant && (d.needsBackfill || d.running));
  if (visible.length === 0) return null;

  const run = (key: CoverageDataset['key']) => {
    setJustTriggered((prev) => new Set(prev).add(key));
    trigger.mutate(key, {
      onError: () => setJustTriggered((prev) => {
        const next = new Set(prev);
        next.delete(key);
        return next;
      }),
    });
  };

  return (
    <Card style={{ padding: 18, marginBottom: compact ? 16 : 24, borderLeft: '3px solid var(--warning, #9a6700)' }}>
      <div style={{ fontWeight: 600, marginBottom: 2 }}>Historical data missing</div>
      <div className="muted text-sm" style={{ marginBottom: 12, lineHeight: 1.55 }}>
        Daily sync keeps data current going forward; these datasets have no history back to{' '}
        <b>{data.targetStart}</b> ({data.targetMonths} months). Backfill once — each row disappears when its coverage
        is complete.
      </div>

      <div style={{ display: 'grid', gap: 8 }}>
        {visible.map((d) => (
          <DatasetRow
            key={d.key}
            d={d}
            queuedLocally={justTriggered.has(d.key)}
            onRun={() => run(d.key)}
            busy={trigger.isPending}
          />
        ))}
      </div>
    </Card>
  );
}

function DatasetRow({
  d,
  queuedLocally,
  onRun,
  busy,
}: {
  d: CoverageDataset;
  queuedLocally: boolean;
  onRun: () => void;
  busy: boolean;
}) {
  const earliest = d.platforms.find((p) => p.earliest)?.earliest ?? null;
  const pending = d.running || queuedLocally;

  // A finished run with the gap still open = the platform has no older data
  // to give (e.g. the ad account is younger than 12 months). Terminal state,
  // not a button — otherwise this row nags forever.
  const exhausted = !pending && d.lastRun?.status === 'done' && d.needsBackfill;

  return (
    <div className="flex items-center gap-12" style={{ padding: '8px 0', borderTop: '1px solid var(--border)' }}>
      <div style={{ flex: 1 }}>
        <div style={{ fontWeight: 500, fontSize: 13.5 }}>{d.label}</div>
        <div className="text-xs muted">
          {earliest ? `Data starts ${earliest}` : 'No historical rows yet'}
          {d.lastRun?.status === 'failed' && !pending && (
            <span style={{ color: 'var(--danger, #b3261e)' }}>
              {' '}· last run failed — safe to retry{d.lastRun.message ? ` (${d.lastRun.message.slice(0, 120)}…)` : ''}
            </span>
          )}
          {exhausted && ' · backfill ran — the platform has no older data than this'}
        </div>
      </div>

      {pending ? (
        <span className="text-xs" style={{ color: 'var(--warning, #9a6700)', fontWeight: 500 }}>
          {d.key === 'history' ? (
            <>Queued — one job per day per connection. <Link to="/sync-health" style={{ textDecoration: 'underline' }}>Track on Sync health</Link></>
          ) : (
            'Backfilling… this row updates itself'
          )}
        </span>
      ) : exhausted ? (
        <Button size="sm" variant="ghost" onClick={onRun} disabled={busy} title="Re-run anyway — reruns resume safely">
          Run again
        </Button>
      ) : (
        <Button size="sm" variant="secondary" onClick={onRun} disabled={busy}>
          Backfill 12 months
        </Button>
      )}
    </div>
  );
}
