import { useQuery } from '@tanstack/react-query';
import { Card } from '@/components/ui';
import { api } from '@/lib/api';
import { formatMoney } from '@/lib/formatters';

interface StaleRow {
  adId: string;
  adName: string;
  platform: string;
  season: string;
  seasonLabel: string;
  matchedTerms: string[];
  seasonEnded: string;
  daysStale: number;
  spend: number;
}

interface StaleResponse {
  currency: string;
  count: number;
  spendAtRisk: number;
  rows: StaleRow[];
  trigger: string;
}

function useSeasonalStale(slug: string | undefined) {
  return useQuery({
    queryKey: ['brand', slug, 'seasonal-stale'],
    enabled: !!slug,
    queryFn: async (): Promise<StaleResponse> => {
      const { data } = await api.get<StaleResponse>(`/brands/${slug}/ads/seasonal-stale`);
      return data;
    },
  });
}

/**
 * Seasonal-stale creatives (GO-3.1) — live ads still spending on a hook whose season
 * is over. Christmas copy in February; "soldes d'hiver" in April.
 *
 * Every row names the exact words that fired it and the date the season ended, so the
 * claim can be checked on sight. That matters more than it sounds: the trigger is a
 * keyword+date rule, not a model, and the card says so — an operator should never have
 * to wonder whether an AI guessed at their ad copy.
 *
 * Renders nothing when nothing is stale.
 */
export function StaleCreativeCard({ slug }: { slug?: string }) {
  const { data } = useSeasonalStale(slug);

  if (!data || data.count === 0) return null;

  return (
    <Card style={{ padding: 16, marginBottom: 16 }}>
      <div className="flex items-center justify-between mb-8" style={{ flexWrap: 'wrap', gap: 8 }}>
        <div style={{ fontWeight: 600, color: 'var(--warning, #9a6700)' }}>
          {data.count} out-of-season {data.count === 1 ? 'creative' : 'creatives'} still running
        </div>
        <span className="text-sm muted">
          {formatMoney(data.spendAtRisk, data.currency)} spent in the last 7 days
        </span>
      </div>

      <div style={{ display: 'grid', gap: 10 }}>
        {data.rows.map((r) => (
          <div key={r.adId} style={{ borderLeft: '3px solid var(--warning, #9a6700)', paddingLeft: 10 }}>
            <div className="text-sm" style={{ fontWeight: 600 }}>
              {r.adName || r.adId}
            </div>
            <div className="muted text-xs" style={{ marginTop: 2, lineHeight: 1.5 }}>
              {r.seasonLabel} ended {r.seasonEnded} — {r.daysStale} days ago. Matched:{' '}
              <b>{r.matchedTerms.join(', ')}</b>. Spent {formatMoney(r.spend, data.currency)} in the last 7 days.
            </div>
          </div>
        ))}
      </div>

      <div className="text-xs muted mt-16" style={{ maxWidth: 720, lineHeight: 1.5 }}>
        {data.trigger}
      </div>
    </Card>
  );
}
