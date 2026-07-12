import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Card, Chip } from '@/components/ui';
import { api } from '@/lib/api';
import { useRecommendations } from '@/hooks/useRecommendations';

export interface TrackRecordData {
  total: number;
  open: number;
  accepted: number;
  dismissed: number;
  expired: number;
  acceptedPct: number | null;
  measured: number;
  improved: number;
  worsened: number;
  flat: number;
  unmeasurable: number;
  improvedPct: number | null;
  byKind: { kind: string; total: number; accepted: number; measured: number; improved: number; improvedPct: number | null }[];
  note: string;
}

function useTrackRecord(slug: string | undefined) {
  return useQuery({
    queryKey: ['brand', slug, 'track-record'],
    enabled: !!slug,
    queryFn: async (): Promise<TrackRecordData> => {
      const { data } = await api.get<TrackRecordData>(`/brands/${slug}/track-record`);
      return data;
    },
  });
}

const OUTCOME_COLOR: Record<string, string> = {
  improved:     'var(--success, #1f6f5c)',
  worsened:     'var(--danger, #b3261e)',
  flat:         'var(--text-secondary)',
  unmeasurable: 'var(--text-muted)',
};

/**
 * Helm's track record (GO-3.3) — the engine scored on its own advice.
 *
 * No tool on the market publishes this about itself. It is computed LIVE from the
 * ledger on every load, never cached, so it cannot be frozen on a good week — and if
 * Helm has a bad month, this card will say so out loud.
 *
 * `improvedPct` is null (not 0%) until something has actually been measured: "no data
 * yet" and "0% success" are very different claims and must never look the same.
 */
export function TrackRecordCard({ slug }: { slug?: string }) {
  const { data } = useTrackRecord(slug);
  const [showLedger, setShowLedger] = useState(false);

  if (!data || data.total === 0) return null;

  return (
    <>
      <Card style={{ padding: 16, marginTop: 16 }}>
        <div className="flex items-center justify-between mb-8" style={{ flexWrap: 'wrap', gap: 8 }}>
          <div style={{ fontWeight: 600 }}>Helm's track record on this brand</div>
          <button
            type="button"
            className="text-sm"
            style={{ background: 'none', border: 0, cursor: 'pointer', color: 'var(--accent)' }}
            onClick={() => setShowLedger(!showLedger)}
          >
            {showLedger ? 'Hide' : 'Show'} every recommendation
          </button>
        </div>

        <div className="flex items-center gap-8 mb-8" style={{ flexWrap: 'wrap', gap: 18 }}>
          <Stat label="made" value={String(data.total)} />
          <Stat
            label="accepted"
            value={data.acceptedPct === null ? '—' : `${data.acceptedPct}%`}
            sub={`${data.accepted} of ${data.accepted + data.dismissed} decided`}
          />
          <Stat
            label="improved the metric"
            value={data.improvedPct === null ? 'not measured yet' : `${data.improvedPct}%`}
            sub={data.measured > 0 ? `${data.improved} of ${data.measured} measured` : 'outcomes land 14–30 days after a decision'}
            color={data.improvedPct === null ? undefined : OUTCOME_COLOR.improved}
          />
        </div>

        {data.measured > 0 && (
          <div className="flex items-center gap-8 mb-8" style={{ flexWrap: 'wrap' }}>
            <Chip>{data.improved} improved</Chip>
            <Chip>{data.worsened} worsened</Chip>
            <Chip>{data.flat} flat</Chip>
            <Chip>{data.unmeasurable} unmeasurable</Chip>
          </div>
        )}

        <div className="text-xs muted" style={{ maxWidth: 760, lineHeight: 1.55 }}>{data.note}</div>
      </Card>

      {showLedger && <LedgerTable slug={slug} />}
    </>
  );
}

function Stat({ label, value, sub, color }: { label: string; value: string; sub?: string; color?: string }) {
  return (
    <div>
      <div style={{ fontSize: 20, fontWeight: 700, color }}>{value}</div>
      <div className="muted text-xs">{label}</div>
      {sub && <div className="muted text-xs" style={{ opacity: 0.8 }}>{sub}</div>}
    </div>
  );
}

/** The full ledger — every recommendation, including the ones Helm got wrong. */
function LedgerTable({ slug }: { slug?: string }) {
  const [status, setStatus] = useState('all');
  const { data } = useRecommendations(slug, status);

  if (!data) return null;

  return (
    <Card style={{ marginTop: 12, overflow: 'auto' }}>
      <div className="filter-bar" style={{ padding: 12 }}>
        {['all', 'open', 'accepted', 'dismissed', 'expired'].map((s) => (
          <Chip key={s} active={status === s} onClick={() => setStatus(s)}>{s}</Chip>
        ))}
      </div>

      <table className="data-table">
        <thead>
          <tr>
            <th>Recommendation</th>
            <th>Kind</th>
            <th>Source</th>
            <th>Status</th>
            <th>Outcome</th>
          </tr>
        </thead>
        <tbody>
          {data.rows.map((r) => (
            <tr key={r.id}>
              <td>
                <div style={{ fontWeight: 500 }}>{r.title}</div>
                {r.statusReason && <div className="muted text-xs">“{r.statusReason}”</div>}
              </td>
              <td>{data.kindLabels[r.kind] ?? r.kind}</td>
              <td className="muted text-xs">{r.source.replace('_', ' ')}</td>
              <td className="text-xs">{r.status}</td>
              <td className="text-xs">
                {/* Losses are displayed, not hidden. A track record that only shows its
                    wins is an advertisement. */}
                {r.outcome === null ? (
                  <span className="muted" title="Outcomes are measured 14–30 days after a decision.">—</span>
                ) : (
                  <span style={{ color: OUTCOME_COLOR[r.outcome], fontWeight: 600 }}>{r.outcome}</span>
                )}
              </td>
            </tr>
          ))}
          {data.rows.length === 0 && (
            <tr><td colSpan={5} className="muted" style={{ padding: 16 }}>Nothing here.</td></tr>
          )}
        </tbody>
      </table>
    </Card>
  );
}
