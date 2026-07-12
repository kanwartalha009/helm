import { useState } from 'react';
import { Card, Drawer } from '@/components/ui';
import { useDataQuality } from '@/hooks/useDataQuality';
import type { QualityComponent } from '@/hooks/useDataQuality';

const TIER_COLOR: Record<string, string> = {
  good: 'var(--success, #1f6f5c)',
  ok:   'var(--warning, #9a6700)',
  poor: 'var(--danger, #b3261e)',
};

/**
 * Data-quality score for one brand (GO-1.3). The number is not decoration: below the
 * threshold, Helm will DECLINE to make recommendations (GO-3/GO-4) rather than advise
 * on holey data. So the card's job is to say exactly what is missing and how to fix it.
 *
 * Every component is measured, never estimated. A component that cannot apply to this
 * brand (ad detail on a brand with no ad platform) is shown as "not applicable" and is
 * excluded from the score — it is never counted as a zero.
 */
export function DataQualityCard({ slug }: { slug?: string }) {
  const { data } = useDataQuality(slug);
  const [open, setOpen] = useState(false);

  if (!data) return null;

  const color = TIER_COLOR[data.tier] ?? 'var(--text-secondary)';

  return (
    <>
      <Card style={{ padding: 14, marginBottom: 16 }}>
        <div className="flex items-center justify-between" style={{ gap: 12, flexWrap: 'wrap' }}>
          <div className="flex items-center gap-8">
            <span style={{ fontSize: 22, fontWeight: 700, color }}>{data.score}</span>
            <span className="muted text-sm">/ 100 data quality</span>
            {!data.meetsGate && (
              <span
                className="text-xs"
                title={`Recommendations need a score of at least ${data.threshold}.`}
                style={{ color: TIER_COLOR.poor, fontWeight: 600 }}
              >
                · below the {data.threshold} threshold for recommendations
              </span>
            )}
          </div>
          <button
            type="button"
            className="text-sm"
            style={{ background: 'none', border: 0, cursor: 'pointer', color: 'var(--accent)' }}
            onClick={() => setOpen(true)}
          >
            What's missing?
          </button>
        </div>
      </Card>

      <Drawer open={open} onClose={() => setOpen(false)} size="sm" title={`Data quality — ${data.score}/100`}>
        <div style={{ display: 'grid', gap: 14 }}>
          <div className="muted text-sm">
            Every part below is measured, not estimated. Helm needs {data.threshold}+ before it will make
            recommendations for this brand — advising on incomplete data is worse than staying quiet.
          </div>

          {data.components.map((c) => <ComponentRow key={c.key} c={c} />)}

          <div className="text-xs muted">
            A part that can't apply to this brand is excluded from the score, never counted as zero.
          </div>
        </div>
      </Drawer>
    </>
  );
}

function ComponentRow({ c }: { c: QualityComponent }) {
  const pct = Math.round(c.ratio * 100);

  return (
    <div>
      <div className="flex items-center justify-between text-sm" style={{ marginBottom: 4 }}>
        <span style={{ fontWeight: 600 }}>{c.label}</span>
        <span className="muted text-xs">
          {c.applicable ? <>{c.points} / {c.weight} pts</> : 'not applicable'}
        </span>
      </div>

      {c.applicable && (
        <div style={{ height: 5, borderRadius: 3, background: 'var(--surface-subtle)', overflow: 'hidden', marginBottom: 4 }}>
          <div
            style={{
              width: `${pct}%`,
              height: '100%',
              background: pct >= 85 ? TIER_COLOR.good : pct >= 50 ? TIER_COLOR.ok : TIER_COLOR.poor,
            }}
          />
        </div>
      )}

      <div className="muted text-xs">{c.detail}</div>

      {c.fix && (
        <div className="text-xs" style={{ marginTop: 3 }}>
          Fix: run the <b>{c.fix}</b> backfill on the data coverage card above.
        </div>
      )}
    </div>
  );
}
