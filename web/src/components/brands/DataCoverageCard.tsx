import { useEffect, useState } from 'react';
import { Button } from '@/components/ui';
import { useDataCoverage, useTriggerBackfill } from '@/hooks/useApiData';
import type { CoverageDataset } from '@/hooks/useApiData';

/**
 * Onboarding data coverage — compact banner (2026-07-10 rework). Renders ONLY
 * when a connected platform is missing 12-month history or a backfill is in
 * flight; a covered brand never sees it.
 *
 * One button, one job: "Backfill everything" runs a single tracked queued job
 * covering every gapped dataset and every connected platform (ranged API
 * pulls — never per-day fan-out). Per-dataset detail lives behind a
 * disclosure. The banner polls while pending and removes itself when
 * coverage reaches the target.
 */
export function DataCoverageCard({ slug, compact = false }: { slug: string | undefined; compact?: boolean }) {
  const { data } = useDataCoverage(slug);
  const trigger = useTriggerBackfill(slug);
  const [open, setOpen] = useState(false);
  const [clicked, setClicked] = useState(false);

  // Server truth caught up (running reported) or gaps resolved → drop the
  // optimistic click state.
  useEffect(() => {
    if (!data) return;
    if (data.datasets.some((d) => d.running) || !data.anyGap) setClicked(false);
  }, [data]);

  if (!data || !data.anyGap) return null;

  const gapped = data.datasets.filter((d) => d.relevant && d.needsBackfill);
  const running = clicked || data.datasets.some((d) => d.running);
  const failed = !running && data.datasets.some((d) => d.lastRun?.status === 'failed');
  // Every gapped dataset has a finished run → the platforms have no older
  // data to give. Terminal: say so once, stop nagging.
  const exhausted = !running && gapped.length > 0 && gapped.every((d) => d.lastRun?.status === 'done');

  if (gapped.length === 0 && !running) return null;

  return (
    <div
      style={{
        display: 'flex',
        flexWrap: 'wrap',
        alignItems: 'center',
        gap: 10,
        padding: '10px 14px',
        marginBottom: compact ? 14 : 20,
        border: '1px solid var(--border)',
        borderLeft: '3px solid var(--warning, #9a6700)',
        borderRadius: 8,
        background: 'var(--surface, transparent)',
        fontSize: 13,
      }}
    >
      <span aria-hidden style={{ width: 7, height: 7, borderRadius: '50%', background: 'var(--warning, #9a6700)', flexShrink: 0 }} />

      <span style={{ flex: 1, minWidth: 220 }}>
        {running ? (
          <b>Backfilling {data.targetMonths} months of history — one job, all platforms. This banner clears itself.</b>
        ) : exhausted ? (
          <>
            <b>Backfill complete</b>
            <span className="muted"> — the platforms hold no data older than what's on file.</span>
          </>
        ) : (
          <>
            <b>Historical data missing</b>
            <span className="muted">
              {' '}— {gapped.length} dataset{gapped.length === 1 ? '' : 's'} without history to {data.targetStart}
              {failed ? ' · last run failed (safe to retry)' : ''}
            </span>
          </>
        )}
      </span>

      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        className="muted text-xs"
        style={{ background: 'transparent', border: 0, cursor: 'pointer', textDecoration: 'underline', fontFamily: 'inherit' }}
      >
        {open ? 'Hide details' : 'Details'}
      </button>

      {!running && !exhausted && (
        <Button
          size="sm"
          variant="secondary"
          disabled={trigger.isPending}
          onClick={() => {
            setClicked(true);
            trigger.mutate('all', { onError: () => setClicked(false) });
          }}
        >
          {trigger.isPending ? 'Queuing…' : 'Backfill everything'}
        </Button>
      )}

      {open && (
        <div style={{ flexBasis: '100%', display: 'grid', gap: 4, paddingTop: 6, borderTop: '1px solid var(--border)' }}>
          {data.datasets
            .filter((d) => d.relevant)
            .map((d) => (
              <DetailRow key={d.key} d={d} />
            ))}
        </div>
      )}
    </div>
  );
}

function DetailRow({ d }: { d: CoverageDataset }) {
  const earliest = d.platforms.find((p) => p.earliest)?.earliest ?? null;
  const state = d.running
    ? 'backfilling…'
    : !d.needsBackfill
      ? 'covered'
      : d.lastRun?.status === 'done'
        ? 'no older data on the platform'
        : d.lastRun?.status === 'failed'
          ? `failed — retry via the button${d.lastRun.message ? ` (${d.lastRun.message.slice(0, 90)}…)` : ''}`
          : 'missing';

  return (
    <div className="text-xs" style={{ display: 'flex', gap: 8 }}>
      <span style={{ minWidth: 220, fontWeight: 500 }}>{d.label}</span>
      <span className="muted">{earliest ? `since ${earliest}` : 'no rows'}</span>
      <span
        style={{
          color: d.running
            ? 'var(--warning, #9a6700)'
            : state === 'covered'
              ? 'var(--success, #1f6f5c)'
              : state.startsWith('failed')
                ? 'var(--danger, #b3261e)'
                : 'inherit',
        }}
      >
        · {state}
      </span>
    </div>
  );
}
