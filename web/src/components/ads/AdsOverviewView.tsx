import { type ReactNode, useMemo, useState } from 'react';
import { AdsRegionMap } from './AdsRegionMap';
import { AdsCampaignDrawer } from './AdsCampaignDrawer';
import { countryName } from './countryNames';
import { formatMoney, formatNumber, formatRoas } from '@/lib/formatters';
import type {
  AdsByCountry,
  AdsByDevice,
  AdsCampaignRow,
  AdsFunnelStep,
  AdsOverviewResponse,
  AdsPeriod,
  AdsPlatform,
  AdsSummary,
  AdsTrendPoint,
} from '@/types/ads';
import '@/styles/ads.css';

type MetricKey = 'roas' | 'revenue' | 'purchases' | 'cpa' | 'aov';

const DEVICE_COLORS = ['#2F6BE8', '#0EA5B7', '#16A34A', '#64748B', '#EC4899'];

export function AdsOverviewView({ data, slug, period, platform }: { data: AdsOverviewResponse; slug?: string; period: AdsPeriod; platform: AdsPlatform }) {
  const currency = data.currency === 'usd' ? 'USD' : data.brand.baseCurrency || 'EUR';
  const money = (v: number | null) => formatMoney(v, currency, { whole: true });
  const unit = (v: number | null) => formatMoney(v, currency);
  const s = data.summary;
  const isMeta = data.platform === 'meta';
  const breakdownable = data.platform === 'meta' || data.platform === 'tiktok';
  const platformLabel = data.platform === 'google' ? 'Google' : data.platform === 'tiktok' ? 'TikTok' : 'Meta';
  // Each platform stays on its NATIVE attribution so Helm's numbers match that
  // platform's own Ads Manager (what the agency's client sees) — we label the
  // basis rather than force one window. Blended ROAS is the cross-platform truth.
  const attributionNote = data.platform === 'meta' ? '7-day click' : data.platform === 'tiktok' ? 'account attribution' : null;
  const [drill, setDrill] = useState<{ id: string; name: string } | null>(null);
  const [showAllRegions, setShowAllRegions] = useState(false);
  const [showAllCampaigns, setShowAllCampaigns] = useState(false);

  const kpis: Array<{ key: MetricKey; label: string; color: string; goodUp: boolean; fmt: (v: number | null) => string; series: (t: AdsTrendPoint) => number; icon: ReactNode }> = [
    { key: 'roas', label: 'ROAS', color: '#2563EB', goodUp: true, fmt: (v) => formatRoas(v), series: (t) => (t.spend > 0 ? t.revenue / t.spend : 0), icon: <IconTrend /> },
    { key: 'revenue', label: 'Revenue', color: '#16A34A', goodUp: true, fmt: money, series: (t) => t.revenue, icon: <IconMoney /> },
    { key: 'purchases', label: 'Purchases', color: '#0EA5B7', goodUp: true, fmt: (v) => formatNumber(v), series: (t) => t.purchases, icon: <IconCart /> },
    { key: 'cpa', label: 'CPA', color: '#64748B', goodUp: false, fmt: unit, series: (t) => (t.purchases > 0 ? t.spend / t.purchases : 0), icon: <IconTarget /> },
    { key: 'aov', label: 'AOV', color: '#EC4899', goodUp: true, fmt: unit, series: (t) => (t.purchases > 0 ? t.revenue / t.purchases : 0), icon: <IconBag /> },
  ];

  return (
    <div className="ads-root">
      {/* KPI summary */}
      <div className="ads-panel">
        <div className="ads-ph"><h3>Performance summary</h3></div>
        <div className="ads-psub">Attributed {platformLabel} performance{attributionNote ? ` · ${attributionNote}` : ''} · {rangeLabel(data.from, data.to)}</div>
        <div className="ads-kpis">
          {kpis.map((k) => (
            <div className="ads-kpi" key={k.key}>
              <div className="akpi-top">
                <span className="akpi-tick" style={{ background: k.color }} />
                <span className="akpi-icon" style={{ color: k.color }}>{k.icon}</span>
                <span className="akpi-label">{k.label}</span>
                <Delta v={s.delta?.[k.key] ?? null} goodUp={k.goodUp} />
              </div>
              <div className="akpi-bot">
                <span className="akpi-val">{k.fmt(s[k.key])}</span>
                <Spark series={data.trend.map(k.series)} color={k.color} />
              </div>
            </div>
          ))}
        </div>
        <div className="ads-eff">
          <EffStat label="CPM" value={unit(s.cpm)} />
          <EffStat label="CPC" value={unit(s.cpc)} />
          <EffStat label="CTR" value={s.ctr != null ? `${s.ctr}%` : '—'} />
          {s.reach != null && <EffStat label="Reach" value={formatNumber(s.reach)} />}
          {s.frequency != null && <EffStat label="Frequency" value={s.frequency.toFixed(2)} />}
        </div>
      </div>

      {/* Trends + funnel */}
      <div className="ads-grid-2">
        <div className="ads-panel">
          <div className="atrend-head">
            <div>
              <div className="ads-ph"><h3>Performance Trends</h3><span className="ads-chip">View Trends</span></div>
              <div className="ads-psub">Link Clicks, Impressions and Ad Spend</div>
            </div>
            <div className="atrend-legend">
              <span><i style={{ background: '#2563EB' }} />Link Clicks</span>
              <span><i style={{ background: '#0EA5B7' }} />Impressions</span>
              <span><i style={{ background: '#22C55E' }} />Ads Spend</span>
            </div>
          </div>
          <TrendChart trend={data.trend} summary={s} currency={currency} />
        </div>

        <div className="ads-panel">
          <div className="ads-ph"><h3>Purchase funnel</h3></div>
          <div className="ads-psub">Impressions → purchases</div>
          <Funnel steps={data.funnel} />
        </div>
      </div>

      {/* Region + device — Meta/TikTok get both; Google gets device only (its
          region panel points to the per-country campaign table instead). */}
      {(breakdownable || data.byDevice.hasData) && (
      <div className="ads-grid-2">
        <div className="ads-panel">
          <div className="ads-ph"><h3>Performance by region</h3></div>
          <div className="ads-psub">Spend and purchases by country</div>
          {data.byCountry.hasData ? (
            <div className="aregion">
              <AdsRegionMap rows={data.byCountry.rows} />
              <div className="aregion-side">
                {data.byCountry.top && (
                  <>
                    <div className="atr-label">Top region</div>
                    <div className="atr-card">
                      <span className="atr-thumb">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="var(--text-secondary)" strokeWidth="1.6"><path d="M21 10c0 7-9 12-9 12s-9-5-9-12a9 9 0 0 1 18 0Z" /><circle cx="12" cy="10" r="3" /></svg>
                      </span>
                      <div style={{ flex: 1 }}>
                        <div className="atr-name">{countryName(data.byCountry.top.label)}</div>
                        <div className="atr-meta">Spend {money(data.byCountry.top.spend)} · CPA {unit(data.byCountry.top.cpa)}</div>
                      </div>
                    </div>
                  </>
                )}
                <table className="ads-tbl" style={{ marginTop: 14 }}>
                  <thead><tr><th className="l">Country</th><th>Spend</th><th>Purch.</th><th>ROAS</th></tr></thead>
                  <tbody>
                    {(showAllRegions ? data.byCountry.rows : data.byCountry.rows.slice(0, 6)).map((r) => (
                      <tr key={r.key}>
                        <td className="l">{countryName(r.label)}</td>
                        <td className="num">{money(r.spend)}</td>
                        <td className="num">{formatNumber(r.purchases)}</td>
                        <td className="num">{formatRoas(r.roas)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
                {data.byCountry.rows.length > 6 && (
                  <div className="ads-viewall" style={{ marginTop: 8 }}>
                    <button type="button" onClick={() => setShowAllRegions((v) => !v)}>
                      {showAllRegions ? 'Show less' : `All regions (${data.byCountry.rows.length})`}
                    </button>
                  </div>
                )}
              </div>
            </div>
          ) : (
            <div className="ads-empty">{data.platform === 'tiktok' ? (<>Country breakdown not synced yet. Run <code>tiktok:backfill-breakdown --type=country</code> for this brand.</>) : data.platform === 'meta' ? (<>Country breakdown not synced yet. Run <code>meta:backfill-breakdown country</code> for this brand.</>) : 'Country performance is per campaign for Google — see the campaign breakdown below.'}</div>
          )}
        </div>

        <div className="ads-panel">
          <div className="ads-ph"><h3>Performance by device</h3></div>
          <div className="ads-psub">Purchases by device</div>
          {data.byDevice.hasData ? (
            <DeviceDonut device={data.byDevice} />
          ) : (
            <div className="ads-empty">{data.platform === 'tiktok' ? (<>Device breakdown not synced yet. Run <code>tiktok:backfill-breakdown --type=device</code> for this brand.</>) : data.platform === 'meta' ? (<>Device breakdown not synced yet. Run <code>meta:backfill-breakdown device</code> for this brand.</>) : 'Device breakdown is available for Meta only.'}</div>
          )}
        </div>
      </div>
      )}

      {/* TikTok-native engagement — video completion + social (TikTok only) */}
      {data.tiktokNative && (
        <div className="ads-panel">
          <div className="ads-ph"><h3>TikTok engagement</h3></div>
          <div className="ads-psub">Video completion &amp; social · {rangeLabel(data.from, data.to)}</div>
          <div className="ads-eff">
            <EffStat label="Video plays" value={formatNumber(data.tiktokNative.video.plays)} />
            <EffStat label="2-sec views" value={formatNumber(data.tiktokNative.video.watched2s)} />
            <EffStat label="6-sec views" value={formatNumber(data.tiktokNative.video.watched6s)} />
            <EffStat label="Completed" value={formatNumber(data.tiktokNative.video.p100)} />
            <EffStat label="Completion" value={data.tiktokNative.video.completionRate != null ? `${data.tiktokNative.video.completionRate}%` : '—'} />
          </div>
          <div className="ads-eff" style={{ marginTop: 10 }}>
            <EffStat label="Likes" value={formatNumber(data.tiktokNative.social.likes)} />
            <EffStat label="Comments" value={formatNumber(data.tiktokNative.social.comments)} />
            <EffStat label="Shares" value={formatNumber(data.tiktokNative.social.shares)} />
            <EffStat label="Follows" value={formatNumber(data.tiktokNative.social.follows)} />
            <EffStat label="Profile visits" value={formatNumber(data.tiktokNative.social.profileVisits)} />
          </div>
        </div>
      )}

      {/* Meta-native engagement — video completion + social (Meta only). Labels
          match Meta's real metrics: ThruPlays (no 6-sec), Page likes (no follows),
          and no profile-visit column (Meta reports none for ads). */}
      {data.metaNative && (
        <div className="ads-panel">
          <div className="ads-ph"><h3>Meta engagement</h3></div>
          <div className="ads-psub">Video completion &amp; social · {rangeLabel(data.from, data.to)}</div>
          <div className="ads-eff">
            <EffStat label="Video plays" value={formatNumber(data.metaNative.video.plays)} />
            <EffStat label="3-sec plays" value={formatNumber(data.metaNative.video.watched3s)} />
            <EffStat label="ThruPlays" value={formatNumber(data.metaNative.video.thruplays)} />
            <EffStat label="Completed" value={formatNumber(data.metaNative.video.p100)} />
            <EffStat label="Completion" value={data.metaNative.video.completionRate != null ? `${data.metaNative.video.completionRate}%` : '—'} />
          </div>
          <div className="ads-eff" style={{ marginTop: 10 }}>
            <EffStat label="Likes" value={formatNumber(data.metaNative.social.likes)} />
            <EffStat label="Comments" value={formatNumber(data.metaNative.social.comments)} />
            <EffStat label="Shares" value={formatNumber(data.metaNative.social.shares)} />
            <EffStat label="Page likes" value={formatNumber(data.metaNative.social.pageLikes)} />
          </div>
        </div>
      )}

      {/* Google brand-vs-non-brand incrementality lens (Google only) */}
      {data.byBrandType.hasData && (
        <BrandSplit bd={data.byBrandType} currency={currency} from={data.from} to={data.to} />
      )}

      {/* Google channel mix — PMax / Search·Brand / Search·Generic / Shopping (Google only) */}
      {data.byChannel.hasData && (
        <ChannelMix bd={data.byChannel} currency={currency} from={data.from} to={data.to} />
      )}

      {/* Campaign analysis */}
      <div className="ads-panel">
        <div className="acamp-head">
          <div>
            <div className="ads-ph"><h3>Campaign analysis</h3></div>
            <div className="ads-psub">Attributed performance by campaign · click a row to drill in</div>
          </div>
        </div>
        {data.campaigns.length > 0 ? (
          <>
            <CampaignTable rows={showAllCampaigns ? data.campaigns : data.campaigns.slice(0, 10)} money={money} unit={unit} onView={(id, name) => setDrill({ id, name })} />
            {data.campaigns.length > 10 && (
              <div className="ads-viewall">
                <button type="button" onClick={() => setShowAllCampaigns((v) => !v)}>
                  {showAllCampaigns ? 'Show fewer' : `View all ${data.campaigns.length} campaigns →`}
                </button>
              </div>
            )}
          </>
        ) : (
          <div className="ads-empty">No campaign data in this window yet. Run <code>ads:backfill-campaigns</code> for this brand.</div>
        )}
      </div>

      <AdsCampaignDrawer slug={slug} period={period} platform={platform} campaign={drill} onClose={() => setDrill(null)} />
    </div>
  );
}

/* ---- KPI helpers ----------------------------------------------------- */

function Delta({ v, goodUp }: { v: number | null; goodUp: boolean }) {
  if (v == null) return <span className="akpi-delta">—</span>;
  const up = v >= 0;
  const good = goodUp ? up : !up;
  return (
    <span className="akpi-delta">
      <span style={{ color: good ? '#16A34A' : '#B91C1C' }}>{up ? '▲' : '▼'}</span> {Math.abs(v)}%
    </span>
  );
}

function Spark({ series, color }: { series: number[]; color: string }) {
  const d = useMemo(() => {
    if (series.length < 2) return '';
    const min = Math.min(...series);
    const max = Math.max(...series);
    const span = max - min || 1;
    const n = series.length;
    const pts = series.map((v, i) => {
      const x = (i / (n - 1)) * 78;
      const y = 28 - ((v - min) / span) * 24;
      return `${x.toFixed(1)},${y.toFixed(1)}`;
    });
    return `M0,32 L${pts.join(' L')} L78,32 Z`;
  }, [series]);
  if (!d) return <span className="akpi-spark" />;
  return (
    <svg className="akpi-spark" viewBox="0 0 78 32" preserveAspectRatio="none">
      <path d={d} fill={color} fillOpacity={0.22} />
    </svg>
  );
}

function EffStat({ label, value }: { label: string; value: string }) {
  return (
    <div className="ads-eff-stat">
      <span className="l">{label}</span>
      <span className="v num">{value}</span>
    </div>
  );
}

/* ---- Trends (dual axis, labeled — matches the mockup) ---------------- */

function TrendChart({ trend, summary, currency }: { trend: AdsTrendPoint[]; summary: AdsSummary; currency: string }) {
  if (trend.length < 2) {
    return <div className="ads-empty" style={{ height: 220 }}>Not enough days to chart yet.</div>;
  }
  const W = 900;
  const top = 10;
  const bot = 200;

  // Right axis = Impressions (millions); left axis = Link clicks (thousands).
  // Ad spend (€, usually 20-40x smaller than clicks) gets its OWN scale — on the
  // shared clicks axis it flatlines, which reads as "spend is broken". Its
  // absolute total lives in the stat row below; here we show its trend SHAPE.
  const rightStep = niceStep(Math.max(1, ...trend.map((t) => t.impressions)) / 3);
  const leftStep = niceStep(Math.max(1, ...trend.map((t) => t.clicks)) / 3);
  const spendStep = niceStep(Math.max(1, ...trend.map((t) => t.spend)) / 3);
  const rightMax = rightStep * 3;
  const leftMax = leftStep * 3;
  const spendMax = spendStep * 3;

  const x = (i: number) => (i / (trend.length - 1)) * W;
  const yL = (v: number) => bot - (v / leftMax) * (bot - top);
  const yR = (v: number) => bot - (v / rightMax) * (bot - top);
  const ySpend = (v: number) => bot - (v / spendMax) * (bot - top);
  const line = (acc: (t: AdsTrendPoint) => number, y: (v: number) => number) =>
    trend.map((t, i) => `${x(i).toFixed(1)},${y(acc(t)).toFixed(1)}`).join(' ');

  const gridY = [top, top + (bot - top) / 3, top + (2 * (bot - top)) / 3, bot];
  const leftTicks = [leftStep * 3, leftStep * 2, leftStep, 0];
  const rightTicks = [rightStep * 3, rightStep * 2, rightStep, 0];
  const xTicks = pickDates(trend, 9);

  return (
    <>
      <div className="atrend-chart">
        <div className="atrend-axis l">{leftTicks.map((v, i) => <span key={i}>{axisFmt(v)}</span>)}</div>
        <svg className="atrend-svg" viewBox={`0 0 ${W} ${bot + 10}`} preserveAspectRatio="none">
          {gridY.map((gy, i) => (
            <line key={i} x1={0} y1={gy} x2={W} y2={gy} stroke="#E7E5E4" strokeWidth={1} strokeDasharray={i === 0 || i === 3 ? undefined : '2 6'} />
          ))}
          <polyline points={line((t) => t.impressions, yR)} fill="none" stroke="#0EA5B7" strokeWidth={1.8} strokeLinejoin="round" />
          <polyline points={line((t) => t.spend, ySpend)} fill="none" stroke="#22C55E" strokeWidth={1.8} strokeLinejoin="round" />
          <polyline points={line((t) => t.clicks, yL)} fill="none" stroke="#2563EB" strokeWidth={2} strokeLinejoin="round" />
        </svg>
        <div className="atrend-axis r">{rightTicks.map((v, i) => <span key={i}>{axisFmt(v)}</span>)}</div>
      </div>
      <div className="atrend-x">{xTicks.map((d, i) => <span key={i}>{d}</span>)}</div>
      <div className="atrend-stats">
        <TStat color="#2563EB" label="Page Impressions" delta={summary.delta?.impressions ?? null} value={compact(summary.impressions)} goodUp />
        <TStat color="#22C55E" label="Ads Spend" delta={summary.delta?.spend ?? null} value={formatMoney(summary.spend, currency, { compact: true })} goodUp />
        <TStat color="#0EA5B7" label="CTR (Links)" delta={summary.delta?.ctr ?? null} value={summary.ctr != null ? `${summary.ctr}%` : '—'} goodUp />
      </div>
    </>
  );
}

function TStat({ color, label, delta, value, goodUp }: { color: string; label: string; delta: number | null; value: string; goodUp: boolean }) {
  return (
    <div className="atrend-stat">
      <div className="l">
        <span className="tk" style={{ background: color }} />
        {label}
        {delta != null && (
          <b style={{ color: (goodUp ? delta >= 0 : delta < 0) ? '#16A34A' : '#B91C1C', fontWeight: 600, marginLeft: 2 }}>
            {delta >= 0 ? '▲' : '▼'} {Math.abs(delta)}%
          </b>
        )}
      </div>
      <div className="v num">{value}</div>
    </div>
  );
}

/** Nice round step (1/2/5 × 10ⁿ) so axis ticks read cleanly. */
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

function compact(v: number): string {
  return new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 1 }).format(v);
}

function pickDates(trend: AdsTrendPoint[], n: number): string[] {
  const len = trend.length;
  if (len <= n) return trend.map((t) => shortDate(t.date));
  const out: string[] = [];
  for (let i = 0; i < n; i++) {
    out.push(shortDate(trend[Math.round((i / (n - 1)) * (len - 1))].date));
  }
  return out;
}

/* ---- Funnel ---------------------------------------------------------- */

const FUNNEL_PATH =
  'M45,10 L215,10 C210,50 192,70 190,95 C188,130 168,165 162,190 C156,215 140,232 137,250 L137,372 L123,372 L123,250 C120,232 104,215 98,190 C92,165 72,130 70,95 C68,70 50,50 45,10 Z';

function Funnel({ steps }: { steps: AdsFunnelStep[] }) {
  return (
    <div className="lf-body">
      {/* Smooth funnel silhouette with a soft glow (the approved mockup design). */}
      <svg className="lf-svg" viewBox="0 0 260 376" preserveAspectRatio="xMidYMid meet">
        <defs>
          <filter id="adsFunnelGlow" x="-40%" y="-15%" width="180%" height="130%">
            <feGaussianBlur stdDeviation="5" />
          </filter>
        </defs>
        <path d={FUNNEL_PATH} fill="#AFC7F7" filter="url(#adsFunnelGlow)" />
        <path d={FUNNEL_PATH} fill="#2F5AE8" />
      </svg>
      <div className="lf-stages">
        {steps.map((st, i) => {
          const prev = steps[i - 1];
          const drop = prev && prev.value && st.value != null ? Math.max(0, Math.round(100 - (st.value / prev.value) * 100)) : null;
          return (
            <div className="lf-stage" key={st.key}>
              <div className="lf-label">
                <div className="lb">{st.label}{st.pending && <span className="pend">soon</span>}</div>
                <div className="vv">{st.value != null ? formatNumber(st.value) : '—'}</div>
              </div>
              {drop != null && <span className="lf-pill">↓ {drop}%</span>}
            </div>
          );
        })}
      </div>
    </div>
  );
}

/* ---- Device donut ---------------------------------------------------- */

function DeviceDonut({ device }: { device: AdsByDevice }) {
  const rows = device.rows.slice(0, 5);
  let offset = 0;
  const arcs = rows.map((r, i) => {
    const len = Math.max(0, Math.min(100, r.pct));
    const arc = { color: DEVICE_COLORS[i % DEVICE_COLORS.length], dash: `${Math.max(len - 1.2, 0.5)} ${100 - Math.max(len - 1.2, 0.5)}`, offset: -offset };
    offset += len;
    return arc;
  });
  return (
    <>
      <div className="adev-donut">
        <svg viewBox="0 0 200 200">
          <g transform="rotate(-90 100 100)" fill="none" strokeWidth={16} strokeLinecap="round">
            <circle cx={100} cy={100} r={72} stroke="#EDEBEA" />
            {arcs.map((a, i) => (
              <circle key={i} cx={100} cy={100} r={72} stroke={a.color} pathLength={100} strokeDasharray={a.dash} strokeDashoffset={a.offset} />
            ))}
          </g>
        </svg>
        <div className="adev-center"><span>Total</span><b>{formatNumber(device.total)}</b></div>
      </div>
      <div className="adev-leg">
        {rows.map((r, i) => (
          <div className="r" key={r.label}>
            <span className="sw" style={{ background: DEVICE_COLORS[i % DEVICE_COLORS.length] }} />
            <span className="nm">{r.label}</span>
            <b>{formatNumber(r.value)}</b>
            <span className="pct">({r.pct}%)</span>
          </div>
        ))}
      </div>
    </>
  );
}

/* ---- Campaign table -------------------------------------------------- */

function CampaignTable({ rows, money, unit, onView }: { rows: AdsCampaignRow[]; money: (v: number | null) => string; unit: (v: number | null) => string; onView: (id: string, name: string) => void }) {
  const maxSpend = Math.max(1, ...rows.map((r) => r.spend));
  return (
    <table className="ads-tbl acamp-tbl">
      <thead>
        <tr>
          <th className="l">Campaign</th>
          <th className="l">Spend</th>
          <th>Revenue</th>
          <th>ROAS</th>
          <th>Purch.</th>
          <th>CPA</th>
          <th>CTR</th>
          <th />
        </tr>
      </thead>
      <tbody>
        {rows.map((r) => (
          <tr key={r.id}>
            <td className="l acamp-name">{r.name}</td>
            <td className="l">
              <span className="acamp-barcell">
                <span className="acamp-num">{money(r.spend)}</span>
                <span className="acamp-bar" style={{ width: `${Math.max(6, (r.spend / maxSpend) * 70)}px` }} />
              </span>
            </td>
            <td className="num">{money(r.revenue)}</td>
            <td className="num">{formatRoas(r.roas)}</td>
            <td className="num">{formatNumber(r.purchases)}</td>
            <td className="num">{unit(r.cpa)}</td>
            <td className="num">{r.ctr != null ? `${r.ctr}%` : '—'}</td>
            <td className="l"><button type="button" className="acamp-view" onClick={() => onView(r.id, r.name)}>View →</button></td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}

/* ---- Icons + date helpers -------------------------------------------- */

function IconTrend() { return <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" strokeWidth="1.9"><path d="M3 3v18h18" /><path d="m19 9-5 5-4-4-3 3" /></svg>; }
function IconMoney() { return <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" strokeWidth="1.9"><line x1="12" y1="2" x2="12" y2="22" /><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" /></svg>; }
function IconCart() { return <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" strokeWidth="1.9"><circle cx="9" cy="21" r="1" /><circle cx="20" cy="21" r="1" /><path d="M1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.6L23 6H6" /></svg>; }
function IconTarget() { return <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" strokeWidth="1.9"><circle cx="12" cy="12" r="9" /><circle cx="12" cy="12" r="3" /></svg>; }
function IconBag() { return <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" strokeWidth="1.8"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z" /><path d="M3 6h18" /><path d="M16 10a4 4 0 0 1-8 0" /></svg>; }

function shortDate(iso: string): string {
  const [y, m, d] = iso.split('-').map(Number);
  return new Date(y, (m ?? 1) - 1, d ?? 1).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
}

// Google-only brand-vs-non-brand incrementality lens. The bar is share of
// REVENUE (not spend) — that's what makes brand's dominance obvious: it takes a
// small slice of spend but a huge slice of revenue because it harvests demand
// that would convert anyway. The caveat under it is the guardrail, in-product.
function BrandSplit({ bd, currency, from, to }: { bd: AdsByCountry; currency: string; from: string; to: string }) {
  const money = (v: number | null) => formatMoney(v, currency, { whole: true });
  const rows = bd.rows;
  const totalRev = Math.max(1, rows.reduce((s, r) => s + r.revenue, 0));
  const totalSpend = rows.reduce((s, r) => s + r.spend, 0);
  const brand = rows.find((r) => r.key === 'brand');

  return (
    <div className="ads-panel">
      <div className="ads-ph"><h3>Brand vs non-brand</h3></div>
      <div className="ads-psub">Where revenue actually comes from · {rangeLabel(from, to)}</div>
      <div className="abrk">
        <div className="abrk-row abrk-head">
          <span>Segment</span>
          <span />
          <span className="abrk-val">Spend</span>
          <span className="abrk-pct">Rev %</span>
          <span className="abrk-roas">ROAS</span>
        </div>
        {rows.map((r) => (
          <div className="abrk-row" key={r.key}>
            <span className="abrk-label">{r.label}</span>
            <span className="abrk-track"><span className="abrk-bar" style={{ width: `${Math.max(3, (r.revenue / totalRev) * 100)}%` }} /></span>
            <span className="abrk-val num">{money(r.spend)}</span>
            <span className="abrk-pct num">{`${Math.round((r.revenue / totalRev) * 100)}%`}</span>
            <span className="abrk-roas num">{formatRoas(r.roas)}</span>
          </div>
        ))}
      </div>
      {brand && (
        <div className="ads-psub" style={{ margin: '12px 0 0' }}>
          Brand is {Math.round((brand.revenue / totalRev) * 100)}% of revenue on {totalSpend > 0 ? Math.round((brand.spend / totalSpend) * 100) : 0}% of spend — brand return is typically inflated by shoppers who'd have bought anyway. Non-brand ROAS is the truer growth signal.
        </div>
      )}
    </div>
  );
}

// Google-only channel mix: campaigns folded into PMax / Search·Brand / Search·
// Generic / Shopping / … by name. Reuses the Audience .abrk bar layout.
function ChannelMix({ bd, currency, from, to }: { bd: AdsByCountry; currency: string; from: string; to: string }) {
  const money = (v: number | null) => formatMoney(v, currency, { whole: true });
  const rows = bd.rows.slice(0, 8);
  const max = Math.max(1, ...rows.map((r) => r.spend));

  return (
    <div className="ads-panel">
      <div className="ads-ph"><h3>Channel mix</h3></div>
      <div className="ads-psub">Spend by Google campaign type · {rangeLabel(from, to)}</div>
      <div className="abrk">
        <div className="abrk-row abrk-head">
          <span>Channel</span>
          <span />
          <span className="abrk-val">Spend</span>
          <span className="abrk-pct">%</span>
          <span className="abrk-roas">ROAS</span>
        </div>
        {rows.map((r) => (
          <div className="abrk-row" key={r.key}>
            <span className="abrk-label" title={r.label}>{r.label}</span>
            <span className="abrk-track"><span className="abrk-bar" style={{ width: `${Math.max(3, (r.spend / max) * 100)}%` }} /></span>
            <span className="abrk-val num">{money(r.spend)}</span>
            <span className="abrk-pct num">{`${r.pct}%`}</span>
            <span className="abrk-roas num">{formatRoas(r.roas)}</span>
          </div>
        ))}
      </div>
      {bd.top && (
        <div className="ads-psub" style={{ margin: '12px 0 0' }}>
          Top: <strong>{bd.top.label}</strong> · {formatNumber(bd.top.purchases)} purchases · {bd.top.pct}% of spend
        </div>
      )}
    </div>
  );
}

function rangeLabel(from: string, to: string): string {
  return `${shortDate(from)} – ${shortDate(to)}`;
}
