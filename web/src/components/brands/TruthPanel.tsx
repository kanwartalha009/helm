import { Card } from '@/components/ui';
import { useBrandTruth } from '@/hooks/useTruth';
import { formatMoney, formatRoas } from '@/lib/formatters';

const PLATFORM_LABEL: Record<string, string> = { meta: 'Meta', google: 'Google', tiktok: 'TikTok' };

/**
 * The triangulated-truth panel (GO-1.4). MER is presented as THE number — it comes
 * from revenue the store actually took. Each platform's self-reported ROAS is shown
 * beside it, visually subordinate, and every one carries its bias annotation.
 *
 * What this panel must never do: sum the platform numbers, or present a platform ROAS
 * without its caveat. Both are how the incumbents lost credibility with senior buyers.
 */
export function TruthPanel({ slug, period = 'last30' }: { slug?: string; period?: string }) {
  const { data } = useBrandTruth(slug, period);

  if (!data) return null;

  const c = data.currency;

  return (
    <Card style={{ padding: 18, marginBottom: 16 }}>
      <div className="flex items-center justify-between mb-8" style={{ flexWrap: 'wrap', gap: 8 }}>
        <div style={{ fontWeight: 600 }}>Truth</div>
        <span className="text-xs muted">{data.periodStart} – {data.periodEnd}</span>
      </div>

      {/* The spine. */}
      <div className="flex items-center gap-8 mb-8" style={{ flexWrap: 'wrap' }}>
        <span style={{ fontSize: 26, fontWeight: 700 }}>{data.mer === null ? '—' : formatRoas(data.mer)}</span>
        <div>
          <div className="text-sm" style={{ fontWeight: 600 }}>MER</div>
          <div className="text-xs" style={{ color: 'var(--success, #1f6f5c)', fontWeight: 600 }}>{data.merLabel}</div>
        </div>
        <span className="muted text-sm" style={{ marginLeft: 8 }}>
          {formatMoney(data.storeRevenue, c)} store revenue ÷ {formatMoney(data.totalSpend, c)} ad spend
        </span>
      </div>

      <div className="text-xs muted mb-16" style={{ maxWidth: 720, lineHeight: 1.5 }}>{data.merFormula}</div>

      {/* What each platform says about itself — subordinate, and always caveated. */}
      {data.platforms.length > 0 && (
        <div style={{ display: 'grid', gap: 10 }}>
          {data.platforms.map((p) => (
            <div key={p.platform} style={{ borderTop: '1px solid var(--border)', paddingTop: 10 }}>
              <div className="flex items-center justify-between text-sm" style={{ flexWrap: 'wrap', gap: 8 }}>
                <span style={{ fontWeight: 600 }}>{PLATFORM_LABEL[p.platform] ?? p.platform}</span>
                <span className="flex items-center gap-8">
                  <span className="muted">{formatMoney(p.spend, c)} spend</span>
                  <span>
                    {p.reportedRoas === null ? '—' : formatRoas(p.reportedRoas)}{' '}
                    <span className="muted text-xs">reported</span>
                  </span>
                </span>
              </div>
              <div className="text-xs" style={{ color: 'var(--warning, #9a6700)', fontWeight: 600, marginTop: 2 }}>
                {p.label}
              </div>
              <div className="text-xs muted" style={{ marginTop: 2, lineHeight: 1.5 }}>{p.annotation}</div>
            </div>
          ))}
        </div>
      )}

      <div className="text-xs muted mt-16" style={{ maxWidth: 720, lineHeight: 1.5, borderTop: '1px solid var(--border)', paddingTop: 10 }}>
        {data.divergenceNote}
      </div>
    </Card>
  );
}
