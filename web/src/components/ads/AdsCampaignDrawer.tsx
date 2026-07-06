import { useMemo } from 'react';
import { Drawer } from '@/components/ui';
import { useAdsCampaign } from '@/hooks/useAdsCampaign';
import { formatMoney, formatNumber, formatRoas } from '@/lib/formatters';
import type { AdsPeriod, AdsPlatform, AdsTrendPoint } from '@/types/ads';
import '@/styles/ads.css';

/**
 * Campaign drill-down (Phase B): a right-anchored drawer showing one campaign's
 * KPI grid (with prior-window deltas) and a daily trend. Self-contained (its own
 * Delta + mini trend) so it doesn't import from AdsOverviewView, which imports
 * it — no circular dependency.
 */
export function AdsCampaignDrawer({
  slug,
  period,
  platform,
  campaign,
  onClose,
}: {
  slug?: string;
  period: AdsPeriod;
  platform: AdsPlatform;
  campaign: { id: string; name: string } | null;
  onClose: () => void;
}) {
  const open = campaign != null;
  const q = useAdsCampaign(slug, campaign?.id, period, open, platform);
  const d = q.data;
  const currency = d ? (d.currency === 'usd' ? 'USD' : d.brand.baseCurrency || 'EUR') : 'EUR';
  const money = (v: number | null) => formatMoney(v, currency, { whole: true });
  const unit = (v: number | null) => formatMoney(v, currency);

  return (
    <Drawer open={open} onClose={onClose} size="lg" title={campaign?.name ?? 'Campaign'}>
      <div className="ads-root">
        {q.isError ? (
          <div className="ads-empty">Couldn’t load this campaign. Try refreshing.</div>
        ) : !d && q.isLoading ? (
          <div className="ads-empty">Loading campaign…</div>
        ) : d ? (
          <>
            {d.campaign.status && <span className="acamp-status">{d.campaign.status}</span>}
            <div className="adrawer-kpis">
              <Cell label="Spend" value={money(d.summary.spend)} v={d.summary.delta?.spend ?? null} goodUp={false} />
              <Cell label="Revenue" value={money(d.summary.revenue)} v={d.summary.delta?.revenue ?? null} goodUp />
              <Cell label="ROAS" value={formatRoas(d.summary.roas)} v={d.summary.delta?.roas ?? null} goodUp />
              <Cell label="Purchases" value={formatNumber(d.summary.purchases)} v={d.summary.delta?.purchases ?? null} goodUp />
              <Cell label="CPA" value={unit(d.summary.cpa)} v={d.summary.delta?.cpa ?? null} goodUp={false} />
              <Cell label="AOV" value={unit(d.summary.aov)} v={d.summary.delta?.aov ?? null} goodUp />
              <Cell label="CPM" value={unit(d.summary.cpm)} v={d.summary.delta?.cpm ?? null} goodUp={false} />
              <Cell label="CPC" value={unit(d.summary.cpc)} v={d.summary.delta?.cpc ?? null} goodUp={false} />
              <Cell label="CTR" value={d.summary.ctr != null ? `${d.summary.ctr}%` : '—'} v={d.summary.delta?.ctr ?? null} goodUp />
            </div>
            <div className="ads-panel">
              <div className="ads-ph"><h3>Daily trend</h3></div>
              <div className="ads-psub">Revenue, spend and impressions · {d.from} – {d.to}</div>
              <TrendMini trend={d.trend} />
            </div>
          </>
        ) : null}
      </div>
    </Drawer>
  );
}

function Cell({ label, value, v, goodUp }: { label: string; value: string; v: number | null; goodUp: boolean }) {
  let delta = <span className="adrawer-d">—</span>;
  if (v != null) {
    const up = v >= 0;
    const good = goodUp ? up : !up;
    delta = (
      <span className="adrawer-d" style={{ color: good ? '#16A34A' : '#B91C1C' }}>
        {up ? '▲' : '▼'} {Math.abs(v)}%
      </span>
    );
  }
  return (
    <div className="adrawer-kpi">
      <div className="adrawer-kpi-l">{label}</div>
      <div className="adrawer-kpi-v">{value}</div>
      {delta}
    </div>
  );
}

function TrendMini({ trend }: { trend: AdsTrendPoint[] }) {
  const chart = useMemo(() => {
    if (trend.length < 2) return null;
    const W = 900;
    const top = 8;
    const bot = 168;
    const leftMax = Math.max(1, ...trend.map((t) => Math.max(t.revenue, t.spend)));
    const rightMax = Math.max(1, ...trend.map((t) => t.impressions));
    const x = (i: number) => (i / (trend.length - 1)) * W;
    const yL = (val: number) => bot - (val / leftMax) * (bot - top);
    const yR = (val: number) => bot - (val / rightMax) * (bot - top);
    const line = (acc: (t: AdsTrendPoint) => number, y: (v: number) => number) =>
      trend.map((t, i) => `${x(i).toFixed(1)},${y(acc(t)).toFixed(1)}`).join(' ');
    return { W, top, bot, line, yL, yR };
  }, [trend]);

  if (!chart) return <div className="ads-empty" style={{ height: 120 }}>Not enough days to chart yet.</div>;

  const gridY = [chart.top, chart.top + (chart.bot - chart.top) / 2, chart.bot];
  return (
    <>
      <div className="atrend-legend" style={{ marginBottom: 6 }}>
        <span><i style={{ background: '#2563EB' }} />Revenue</span>
        <span><i style={{ background: '#22C55E' }} />Spend</span>
        <span><i style={{ background: '#0EA5B7' }} />Impressions</span>
      </div>
      <svg className="atrend-svg" viewBox={`0 0 ${chart.W} 180`} preserveAspectRatio="none" style={{ height: 170 }}>
        {gridY.map((gy, i) => (
          <line key={i} x1={0} y1={gy} x2={chart.W} y2={gy} stroke="#E7E5E4" strokeDasharray={i === 1 ? '2 6' : undefined} />
        ))}
        <polyline points={chart.line((t) => t.impressions, chart.yR)} fill="none" stroke="#0EA5B7" strokeWidth={1.8} strokeLinejoin="round" />
        <polyline points={chart.line((t) => t.spend, chart.yL)} fill="none" stroke="#22C55E" strokeWidth={1.8} strokeLinejoin="round" />
        <polyline points={chart.line((t) => t.revenue, chart.yL)} fill="none" stroke="#2563EB" strokeWidth={2} strokeLinejoin="round" />
      </svg>
    </>
  );
}
