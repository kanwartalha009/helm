import { Drawer } from '@/components/ui';
import { useAdsCampaign } from '@/hooks/useAdsCampaign';
import { formatMoney, formatNumber, formatRoas } from '@/lib/formatters';
import type { AdsPeriod, AdsPlatform, AdsSignal, AdsTrendPoint } from '@/types/ads';
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
  campaign: { id: string; name: string; signal?: AdsSignal | null; signalReason?: string | null } | null;
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
        {campaign?.signal && campaign.signalReason && (
          <div className={`adrawer-sig adrawer-sig-${campaign.signal}`}>
            <span className={`asig asig-${campaign.signal}`}>{campaign.signal === 'scale' ? 'Scale' : campaign.signal === 'cut' ? 'Review' : 'Watch'}</span>
            <span className="adrawer-sig-why">{campaign.signalReason}</span>
          </div>
        )}
        {q.isError ? (
          <div className="ads-empty">Couldn’t load this campaign. Try refreshing.</div>
        ) : !d && q.isLoading ? (
          <div className="ads-empty">Loading campaign…</div>
        ) : d ? (
          <>
            {d.campaign.status && <span className="acamp-status">{d.campaign.status}</span>}
            {/* Real Google channel type (null on Meta/TikTok → no chip) */}
            {d.campaign.channelType && <span className="acamp-status" style={{ marginLeft: 6 }}>{d.campaign.channelType.replace(/_/g, ' ')}</span>}
            <div className="adrawer-kpis">
              <Cell label="Spend" value={money(d.summary.spend)} v={d.summary.delta?.spend ?? null} goodUp={false} series={d.trend.map((t) => t.spend)} color="#22C55E" />
              <Cell label="Revenue" value={money(d.summary.revenue)} v={d.summary.delta?.revenue ?? null} goodUp series={d.trend.map((t) => t.revenue)} color="#2563EB" />
              <Cell label="ROAS" value={formatRoas(d.summary.roas)} v={d.summary.delta?.roas ?? null} goodUp series={d.trend.map((t) => (t.spend > 0 ? t.revenue / t.spend : 0))} color="#2563EB" />
              <Cell label="Purchases" value={formatNumber(d.summary.purchases)} v={d.summary.delta?.purchases ?? null} goodUp series={d.trend.map((t) => t.purchases)} color="#0EA5B7" />
              <Cell label="CPA" value={unit(d.summary.cpa)} v={d.summary.delta?.cpa ?? null} goodUp={false} series={d.trend.map((t) => (t.purchases > 0 ? t.spend / t.purchases : 0))} color="#64748B" />
              <Cell label="AOV" value={unit(d.summary.aov)} v={d.summary.delta?.aov ?? null} goodUp series={d.trend.map((t) => (t.purchases > 0 ? t.revenue / t.purchases : 0))} color="#EC4899" />
              <Cell label="CPM" value={unit(d.summary.cpm)} v={d.summary.delta?.cpm ?? null} goodUp={false} series={d.trend.map((t) => (t.impressions > 0 ? (t.spend / t.impressions) * 1000 : 0))} color="#64748B" />
              <Cell label="CPC" value={unit(d.summary.cpc)} v={d.summary.delta?.cpc ?? null} goodUp={false} series={d.trend.map((t) => (t.clicks > 0 ? t.spend / t.clicks : 0))} color="#64748B" />
              <Cell label="CTR" value={d.summary.ctr != null ? `${d.summary.ctr}%` : '—'} v={d.summary.delta?.ctr ?? null} goodUp series={d.trend.map((t) => (t.impressions > 0 ? (t.clicks / t.impressions) * 100 : 0))} color="#0EA5B7" />
            </div>
            {/* Search/Shopping impression share (window average) — Google fills
                it only where it applies, so a null row is simply hidden. Google
                floors sub-10% share at 9.99%. */}
            {d.campaign.searchImpressionShare != null && (
              <div className="adrawer-is">
                <span className="adrawer-is-l">Search impression share</span>
                <span className="adrawer-is-v num">{d.campaign.searchImpressionShare}%</span>
                {d.campaign.searchBudgetLostIs != null && (
                  <span className="adrawer-is-lost">· lost to budget {d.campaign.searchBudgetLostIs}%</span>
                )}
              </div>
            )}
            <div className="ads-panel">
              <div className="ads-ph"><h3>Daily trend</h3></div>
              <div className="ads-psub">Revenue and impressions · {d.from} – {d.to}</div>
              <TrendMini trend={d.trend} currency={currency} />
            </div>
          </>
        ) : null}
      </div>
    </Drawer>
  );
}

function Cell({ label, value, v, goodUp, series, color }: { label: string; value: string; v: number | null; goodUp: boolean; series?: number[]; color?: string }) {
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
      {series && series.length > 1 && <CellSpark series={series} color={color ?? '#2563EB'} />}
    </div>
  );
}

function CellSpark({ series, color }: { series: number[]; color: string }) {
  const max = Math.max(...series);
  const min = Math.min(...series);
  const rng = max - min || 1;
  const W = 120, H = 26;
  const pts = series.map((v, i) => `${((i / (series.length - 1)) * W).toFixed(1)},${(H - ((v - min) / rng) * H).toFixed(1)}`).join(' ');
  return (
    <svg className="adrawer-spark" viewBox={`0 0 ${W} ${H}`} preserveAspectRatio="none">
      <polyline points={pts} fill="none" stroke={color} strokeWidth={1.6} strokeLinejoin="round" strokeLinecap="round" />
    </svg>
  );
}

function TrendMini({ trend, currency }: { trend: AdsTrendPoint[]; currency: string }) {
  if (trend.length < 2) {
    return <div className="ads-empty" style={{ height: 160 }}>Not enough days to chart yet.</div>;
  }
  const W = 900, top = 10, bot = 180;

  // Left axis = revenue (money); right axis = impressions. Spend is NOT a line
  // here — it's ~10× smaller than revenue so on the shared money axis it just
  // flatlines; its trend already lives in the Spend KPI sparkline above.
  const leftStep = niceStep(Math.max(1, ...trend.map((t) => t.revenue)) / 3);
  const rightStep = niceStep(Math.max(1, ...trend.map((t) => t.impressions)) / 3);
  const leftMax = leftStep * 3, rightMax = rightStep * 3;

  const x = (i: number) => (i / (trend.length - 1)) * W;
  const yL = (v: number) => bot - (v / leftMax) * (bot - top);
  const yR = (v: number) => bot - (v / rightMax) * (bot - top);
  const line = (acc: (t: AdsTrendPoint) => number, y: (v: number) => number) =>
    trend.map((t, i) => `${x(i).toFixed(1)},${y(acc(t)).toFixed(1)}`).join(' ');

  const gridY = [top, top + (bot - top) / 3, top + (2 * (bot - top)) / 3, bot];
  const leftTicks = [leftStep * 3, leftStep * 2, leftStep, 0];
  const rightTicks = [rightStep * 3, rightStep * 2, rightStep, 0];
  const xTicks = pickDates(trend, 7);
  const cur = currency === 'USD' ? '$' : currency === 'EUR' ? '€' : '';

  return (
    <>
      <div className="atrend-legend" style={{ marginBottom: 6 }}>
        <span><i style={{ background: '#2563EB' }} />Revenue</span>
        <span><i style={{ background: '#0EA5B7' }} />Impressions</span>
      </div>
      <div className="atrend-chart">
        <div className="atrend-axis l">{leftTicks.map((v, i) => <span key={i}>{v === 0 ? '0' : `${cur}${axisFmt(v)}`}</span>)}</div>
        <svg className="atrend-svg" viewBox={`0 0 ${W} ${bot + 10}`} preserveAspectRatio="none">
          {gridY.map((gy, i) => (
            <line key={i} x1={0} y1={gy} x2={W} y2={gy} stroke="#E7E5E4" strokeWidth={1} strokeDasharray={i === 0 || i === 3 ? undefined : '2 6'} />
          ))}
          <polyline points={line((t) => t.impressions, yR)} fill="none" stroke="#0EA5B7" strokeWidth={1.8} strokeLinejoin="round" />
          <polyline points={line((t) => t.revenue, yL)} fill="none" stroke="#2563EB" strokeWidth={2} strokeLinejoin="round" />
        </svg>
        <div className="atrend-axis r">{rightTicks.map((v, i) => <span key={i}>{axisFmt(v)}</span>)}</div>
      </div>
      <div className="atrend-x">{xTicks.map((d, i) => <span key={i}>{d}</span>)}</div>
      <div className="ads-psub" style={{ marginTop: 8, fontSize: 11 }}>Left axis: revenue · Right axis: impressions</div>
    </>
  );
}

// Self-contained axis helpers — mirror AdsOverviewView deliberately so the
// drawer keeps no import back to it (that file imports this drawer → circular).
function niceStep(v: number): number {
  if (v <= 0) return 1;
  const pow = Math.pow(10, Math.floor(Math.log10(v)));
  const n = v / pow;
  const nice = n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10;
  return nice * pow;
}

function axisFmt(v: number): string {
  if (v === 0) return '0';
  if (v >= 1e6) return `${Number.isInteger(v / 1e6) ? v / 1e6 : (v / 1e6).toFixed(1)}M`;
  if (v >= 1e3) return `${Number.isInteger(v / 1e3) ? v / 1e3 : (v / 1e3).toFixed(1)}K`;
  return `${Math.round(v)}`;
}

function pickDates(trend: AdsTrendPoint[], n: number): string[] {
  const len = trend.length;
  const fmt = (iso: string) => {
    const [y, m, d] = iso.split('-').map(Number);
    return new Date(y, (m ?? 1) - 1, d ?? 1).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
  };
  if (len <= n) return trend.map((t) => fmt(t.date));
  const out: string[] = [];
  for (let i = 0; i < n; i++) out.push(fmt(trend[Math.round((i / (n - 1)) * (len - 1))].date));
  return out;
}
