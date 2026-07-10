import { Link } from 'react-router-dom';
import { Button, Card } from '@/components/ui';
import { useDataCoverage, useTriggerBackfill } from '@/hooks/useApiData';
import type { CoverageDataset } from '@/hooks/useApiData';

/**
 * Onboarding data coverage (2026-07-10). Renders ONLY when a connected
 * platform is missing history against the 12-month target or a backfill is
 * in flight — a fully covered brand never sees this card. One-click,
 * one-at-a-time backfills; daily sync owns everything after the pull.
 */
export function DataCoverageCard({ slug, compact = false }: { slug: string | undefined; compact?: boolean }) {
  const { data } = useDataCoverage(slug);
  const trigger = useTriggerBackfill(slug);

  if (!data || !data.anyGap) return null;

  const visible = data.datasets.filter((d) => d.relevant && (d.needsBackfill || d.running));
  if (visible.length === 0) return null;

  return (
    <Card style={{ padding: 18, marginBottom: compact ? 16 : 24, borderLeft: '3px solid var(--warning, #9a6700)' }}>
      <div style={{ fontWeight: 600, marginBottom: 2 }}>Historical data missing</div>
      <div className="muted text-sm" style={{ marginBottom: 12, lineHeight: 1.55 }}>
        Daily sync keeps data current going forward; these datasets have no history back to{' '}
        <b>{data.targetStart}</b> ({data.targetMonths} months). Backfill once — the buttons disappear when coverage is
        complete.
      </div>

      <div style={{ display: 'grid', gap: 8 }}>
        {visible.map((d) => (
          <DatasetRow key={d.key} d={d} onRun={() => trigger.mutate(d.key)} busy={trigger.isPending} />
        ))}
      </div>
    </Card>
  );
}

function DatasetRow({ d, onRun, busy }: { d: CoverageDataset; onRun: () => void; busy: boolean }) {
  const earliest = d.platforms.find((p) => p.earliest)?.earliest ?? null;

  return (
    <div className="flex items-center gap-12" style={{ padding: '8px 0', borderTop: '1px solid var(--border)' }}>
      <div style={{ flex: 1 }}>
        <div style={{ fontWeight: 500, fontSize: 13.5 }}>{d.label}</div>
        <div className="text-xs muted">
          {earliest ? `Data starts ${earliest}` : 'No historical rows yet'}
          {d.lastRun?.status === 'failed' && (
            <span style={{ color: 'var(--danger, #b3261e)' }}>
              {' '}· last run failed — safe to retry{d.lastRun.message ? ` (${d.lastRun.message.slice(0, 120)}…)` : ''}
            </span>
          )}
          {d.lastRun?.status === 'done' && ' · last run finished'}
        </div>
      </div>
      {d.running ? (
        <span className="text-xs muted">
          {d.key === 'history' ? (
            <>Backfilling — <Link to="/sync-health" style={{ textDecoration: 'underline' }}>track on Sync health</Link></>
          ) : (
            'Backfilling…'
          )}
        </span>
      ) : (
        <Button size="sm" variant="secondary" onClick={onRun} disabled={busy}>
          Backfill 12 months
        </Button>
      )}
    </div>
  );
}
