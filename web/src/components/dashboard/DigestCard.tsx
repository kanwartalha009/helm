import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { Card } from '@/components/ui';
import { api } from '@/lib/api';

interface DigestResponse {
  periodStart: string;
  periodEnd: string;
  brands: number;
  empty: boolean;
  emptyNote: string;
  sections: {
    newRecommendations?: { count: number; rows: { brand: string; slug: string; kind: string; title: string }[] };
    anomalies?: { count: number; rows: { brand: string; slug: string; kind: string; severity: string; date: string }[] };
    trackRecord?: {
      measuredThisWeek: number;
      improvedThisWeek: number;
      worsenedThisWeek: number;
      overallImprovedPct: number | null;
      overallAccepted: number;
      overallTotal: number;
    };
    competitorMovement?: { count: number; rows: { message: string }[]; label: string };
  };
}

function useDigest() {
  return useQuery({
    queryKey: ['digest'],
    staleTime: 10 * 60 * 1000,
    queryFn: async (): Promise<DigestResponse> => {
      const { data } = await api.get<DigestResponse>('/digest');
      return data;
    },
  });
}

/**
 * The weekly digest, in-app (GO-3.5). Slack is optional delivery — this is the feature.
 *
 * A quiet week says "quiet week" and stops. It does not pad itself with vanity metrics to
 * look busy: a digest that always has something to say is one people stop opening, and
 * then the week it actually matters, nobody reads it.
 *
 * The track-record line reports what Helm got WRONG this week next to what it got right.
 */
export function DigestCard() {
  const { data } = useDigest();

  if (!data) return null;

  const s = data.sections;
  const tr = s.trackRecord;

  return (
    <Card style={{ padding: 16, marginBottom: 16 }}>
      <div className="flex items-center justify-between mb-8" style={{ flexWrap: 'wrap', gap: 8 }}>
        <div style={{ fontWeight: 600 }}>This week</div>
        <span className="muted text-xs">{data.periodStart} – {data.periodEnd}</span>
      </div>

      {data.empty ? (
        <div className="muted text-sm">{data.emptyNote}</div>
      ) : (
        <div style={{ display: 'grid', gap: 10 }}>
          {(s.newRecommendations?.count ?? 0) > 0 && (
            <Line
              label={`${s.newRecommendations!.count} new recommendation${s.newRecommendations!.count === 1 ? '' : 's'}`}
              detail={s.newRecommendations!.rows.slice(0, 3).map((r) => `${r.brand}: ${r.title}`).join(' · ')}
            />
          )}

          {(s.anomalies?.count ?? 0) > 0 && (
            <Line
              label={`${s.anomalies!.count} open anomal${s.anomalies!.count === 1 ? 'y' : 'ies'}`}
              detail={s.anomalies!.rows.slice(0, 3).map((a) => `${a.brand}: ${a.kind.replace(/_/g, ' ')}`).join(' · ')}
              color="var(--danger, #b3261e)"
            />
          )}

          {tr && (tr.measuredThisWeek > 0 || tr.overallImprovedPct !== null) && (
            <Line
              label="Helm's own advice"
              detail={
                `${tr.measuredThisWeek} measured this week — ${tr.improvedThisWeek} improved, ${tr.worsenedThisWeek} worsened. ` +
                `Overall ${tr.overallTotal} made, ${tr.overallAccepted} accepted, ` +
                (tr.overallImprovedPct === null ? 'not enough measured yet.' : `${tr.overallImprovedPct}% improved the metric.`)
              }
            />
          )}

          {(s.competitorMovement?.count ?? 0) > 0 && (
            <Line
              label="Competitor movement"
              detail={`${s.competitorMovement!.rows.slice(0, 2).map((m) => m.message).join(' · ')} (${s.competitorMovement!.label})`}
            />
          )}
        </div>
      )}
    </Card>
  );
}

function Line({ label, detail, color }: { label: string; detail: string; color?: string }) {
  return (
    <div>
      <div className="text-sm" style={{ fontWeight: 600, color }}>{label}</div>
      <div className="muted text-xs" style={{ marginTop: 2, lineHeight: 1.5 }}>{detail}</div>
    </div>
  );
}
