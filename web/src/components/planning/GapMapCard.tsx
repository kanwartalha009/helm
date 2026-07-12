import { useQuery } from '@tanstack/react-query';
import { Card } from '@/components/ui';
import { api } from '@/lib/api';
import { formatMoney } from '@/lib/formatters';

interface GapRow {
  market: string;
  competitorConcepts: number;
  competitorPages: number;
  competitorNames: string[];
  topFormats: string[];
  proxyLabel: string;
  ownSpendUsd: number | null;
  ownSharePct: number | null;
  verifiedLabel: string;
  gap: 'absent' | 'underweight' | 'present' | 'unknown';
}

interface GapMapResponse {
  status: 'ok' | 'no_niche' | 'no_corpus';
  niche?: string;
  lookbackDays?: number;
  rows: GapRow[];
  note: string;
}

function useGapMap(slug: string | undefined) {
  return useQuery({
    queryKey: ['brand', slug, 'gap-map'],
    enabled: !!slug,
    queryFn: async (): Promise<GapMapResponse> => {
      const { data } = await api.get<GapMapResponse>(`/brands/${slug}/gap-map`);
      return data;
    },
  });
}

const GAP_COLOR: Record<string, string> = {
  absent:      'var(--danger, #b3261e)',
  underweight: 'var(--warning, #9a6700)',
  present:     'var(--success, #1f6f5c)',
  unknown:     'var(--text-muted)',
};

const GAP_WORD: Record<string, string> = {
  absent:      'no spend here',
  underweight: 'barely present',
  present:     'we’re active',
  unknown:     'we don’t know',
};

/**
 * Competitor gap map (GO-3.4) — the join no other tool can make: what rivals in this
 * niche are actively running, by market, against what we actually spend there.
 *
 * The two sides are kept visually and semantically apart. Competitor activity is
 * PROXY — presence, concept counts, formats — and carries **no spend**, because the EU
 * Ad Library does not publish competitor spend and Helm will not estimate it. Our own
 * numbers are VERIFIED money.
 *
 * And the honest caveat, on every render: a gap is a QUESTION, not proof. Competitors
 * being in a market means they chose to be there — not that it pays.
 */
export function GapMapCard({ slug }: { slug?: string }) {
  const { data } = useGapMap(slug);

  if (!data) return null;

  if (data.status !== 'ok') {
    return (
      <Card style={{ padding: 16, marginTop: 16 }}>
        <div style={{ fontWeight: 600, marginBottom: 4 }}>Competitor gap map</div>
        <div className="muted text-sm" style={{ maxWidth: 720, lineHeight: 1.55 }}>{data.note}</div>
      </Card>
    );
  }

  return (
    <Card style={{ padding: 16, marginTop: 16 }}>
      <div className="flex items-center justify-between mb-8" style={{ flexWrap: 'wrap', gap: 8 }}>
        <div style={{ fontWeight: 600 }}>Competitor gap map · {data.niche}</div>
        <span className="muted text-xs">our spend: last {data.lookbackDays} days</span>
      </div>

      <div style={{ display: 'grid', gap: 12 }}>
        {data.rows.map((r) => (
          <div key={r.market} style={{ borderLeft: `3px solid ${GAP_COLOR[r.gap]}`, paddingLeft: 10 }}>
            <div className="flex items-center justify-between" style={{ gap: 8, flexWrap: 'wrap' }}>
              <span style={{ fontWeight: 600 }}>{r.market}</span>
              <span className="text-xs" style={{ color: GAP_COLOR[r.gap], fontWeight: 600 }}>
                {GAP_WORD[r.gap]}
              </span>
            </div>

            {/* PROXY side — presence only. Never spend. */}
            <div className="muted text-xs" style={{ marginTop: 3, lineHeight: 1.5 }}>
              {r.competitorPages} competitor{r.competitorPages === 1 ? '' : 's'} · {r.competitorConcepts} live
              concept{r.competitorConcepts === 1 ? '' : 's'}
              {r.topFormats.length > 0 && <> · {r.topFormats.join(', ')}</>}
              {r.competitorNames.length > 0 && <> — {r.competitorNames.slice(0, 3).join(', ')}</>}
              <span style={{ opacity: 0.75 }}> (Proxy — presence only, no competitor spend exists)</span>
            </div>

            {/* VERIFIED side — our real money. */}
            <div className="muted text-xs" style={{ marginTop: 2 }}>
              Our spend:{' '}
              {r.ownSpendUsd === null
                ? <span title="No country breakdown synced — we cannot claim we're absent.">unknown</span>
                : <>{formatMoney(r.ownSpendUsd, 'USD')}{r.ownSharePct !== null && <> · {r.ownSharePct}% of our budget</>}</>}
              <span style={{ opacity: 0.75 }}> (Verified — our data)</span>
            </div>
          </div>
        ))}
      </div>

      <div className="text-xs muted mt-16" style={{ maxWidth: 760, lineHeight: 1.55 }}>{data.note}</div>
    </Card>
  );
}
