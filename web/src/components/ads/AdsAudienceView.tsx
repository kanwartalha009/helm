import { countryName } from './countryNames';
import { formatMoney, formatNumber, formatRoas } from '@/lib/formatters';
import type { AdsByCountry, AdsOverviewResponse, AdsTrendPoint } from '@/types/ads';
import '@/styles/ads.css';

/**
 * Audience tab (Phase C) — the demographic deep-dive. An overall summary at the
 * top, a male/female gender split, then a panel per breakdown (age, age×gender,
 * placement, placement detail, device, country), each with each segment's share
 * of spend. Panels with no synced data are hidden, not shown as empty — so the
 * tab always reads as a finished view. All Meta (the tab only shows on Meta).
 */
export function AdsAudienceView({ data }: { data: AdsOverviewResponse }) {
  const currency = data.currency === 'usd' ? 'USD' : data.brand.baseCurrency || 'EUR';
  const money = (v: number | null) => formatMoney(v, currency, { whole: true });
  const unit = (v: number | null) => formatMoney(v, currency);
  const s = data.summary;

  const kpis: { key: 'roas' | 'revenue' | 'purchases' | 'cpa' | 'aov'; label: string; color: string; goodUp: boolean; fmt: (v: number | null) => string; series: (t: AdsTrendPoint) => number }[] = [
    { key: 'roas', label: 'ROAS', color: '#2563EB', goodUp: true, fmt: (v) => formatRoas(v), series: (t) => (t.spend > 0 ? t.revenue / t.spend : 0) },
    { key: 'revenue', label: 'Revenue', color: '#16A34A', goodUp: true, fmt: money, series: (t) => t.revenue },
    { key: 'purchases', label: 'Purchases', color: '#0EA5B7', goodUp: true, fmt: (v) => formatNumber(v), series: (t) => t.purchases },
    { key: 'cpa', label: 'CPA', color: '#64748B', goodUp: false, fmt: unit, series: (t) => (t.purchases > 0 ? t.spend / t.purchases : 0) },
    { key: 'aov', label: 'AOV', color: '#EC4899', goodUp: true, fmt: unit, series: (t) => (t.purchases > 0 ? t.revenue / t.purchases : 0) },
  ];

  const panels: { title: string; subtitle: string; bd: AdsByCountry; prettify: (s: string) => string }[] = [
    { title: 'Age', subtitle: 'Attributed spend by age', bd: data.byAge, prettify: (x) => x },
    { title: 'Age & gender', subtitle: 'Attributed spend by age × gender', bd: data.byAgeGender, prettify: prettyAgeGender },
    { title: 'Placement', subtitle: 'By publisher platform', bd: data.byPlacement, prettify: prettyPlacement },
    { title: 'Placement detail', subtitle: 'By platform & position', bd: data.byPlacementDetail, prettify: prettyPlacementDetail },
    { title: 'Device', subtitle: 'By impression device', bd: data.byDeviceDetail, prettify: prettyDevice },
    { title: 'Region', subtitle: 'Countries rolled up into regions', bd: data.byRegion, prettify: (x) => x },
    { title: 'Country', subtitle: 'By country', bd: data.byCountry, prettify: countryName },
  ];
  const visible = panels.filter((p) => p.bd.hasData);

  // Quick-read: the single best-spending segment on each axis.
  const highlights = [
    { axis: 'Audience', bd: data.byAudience, prettify: prettyAudience },
    { axis: 'Top age', bd: data.byAge, prettify: (x: string) => x },
    { axis: 'Top gender', bd: data.byGender, prettify: (x: string) => x },
    { axis: 'Top placement', bd: data.byPlacement, prettify: prettyPlacement },
    { axis: 'Top device', bd: data.byDeviceDetail, prettify: prettyDevice },
    { axis: 'Top region', bd: data.byRegion, prettify: (x: string) => x },
  ].filter((h) => h.bd.hasData && h.bd.top);

  const hasAny = data.byGender.hasData || data.byAudience.hasData || visible.length > 0;

  return (
    <div className="ads-root">
      <div className="ads-panel">
        <div className="ads-ph"><h3>Audience overview</h3></div>
        <div className="ads-psub">All attributed performance · {data.from} – {data.to}</div>
        <div className="ads-kpis">
          {kpis.map((k) => (
            <div className="ads-kpi" key={k.key}>
              <div className="akpi-top">
                <span className="akpi-tick" style={{ background: k.color }} />
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
          <EffStat label="Spend" value={money(s.spend)} />
          <EffStat label="CTR" value={s.ctr != null ? `${s.ctr}%` : '—'} />
          <EffStat label="CPM" value={unit(s.cpm)} />
          <EffStat label="CPC" value={unit(s.cpc)} />
          <EffStat label="Reach" value={s.reach != null ? formatNumber(s.reach) : '—'} />
          <EffStat label="Frequency" value={s.frequency != null ? s.frequency.toFixed(2) : '—'} />
        </div>
      </div>

      {!hasAny ? (
        <div className="ads-panel">
          <div className="ads-empty">
            No audience breakdowns synced yet. Run <code>meta:backfill-breakdown --type=all</code> for this brand.
          </div>
        </div>
      ) : (
        <>
          {highlights.length > 0 && (
            <div className="ads-panel">
              <div className="ads-ph"><h3>Top segments</h3></div>
              <div className="ads-psub">The single best-spending segment on each axis</div>
              <div className="ahi-grid">
                {highlights.map((h) => (
                  <div className="ahi-card" key={h.axis}>
                    <div className="ahi-axis">{h.axis}</div>
                    <div className="ahi-seg" title={h.prettify(h.bd.top!.label)}>{h.prettify(h.bd.top!.label)}</div>
                    <div className="ahi-meta">{h.bd.top!.pct}% of spend · {formatRoas(h.bd.top!.roas)}</div>
                  </div>
                ))}
              </div>
            </div>
          )}

          <div className="ads-grid-even">
            <SplitBar title="Gender" subtitle="Attributed spend by gender" bd={data.byGender} color={genderColor} prettify={(x) => x} money={money} />
            <SplitBar title="Audience" subtitle="New vs returning vs engaged" bd={data.byAudience} color={audienceColor} prettify={prettyAudience} money={money} />
          </div>

          <div className="ads-grid-2">
            {visible.map((p) => (
              <BreakdownPanel key={p.title} title={p.title} subtitle={p.subtitle} bd={p.bd} currency={currency} prettify={p.prettify} />
            ))}
          </div>
        </>
      )}
    </div>
  );
}

function Delta({ v, goodUp }: { v: number | null; goodUp: boolean }) {
  if (v == null) return <span className="akpi-delta">—</span>;
  const up = v >= 0;
  const good = goodUp ? up : !up;
  return (
    <span className="akpi-delta" style={{ color: good ? '#16A34A' : '#B91C1C' }}>
      {up ? '▲' : '▼'} {Math.abs(v)}%
    </span>
  );
}

function Spark({ series, color }: { series: number[]; color: string }) {
  if (series.length < 2) return <span className="akpi-spark" />;
  const max = Math.max(...series);
  const min = Math.min(...series);
  const rng = max - min || 1;
  const W = 74, H = 30;
  const pts = series.map((v, i) => `${((i / (series.length - 1)) * W).toFixed(1)},${(H - ((v - min) / rng) * H).toFixed(1)}`).join(' ');
  return (
    <svg className="akpi-spark" viewBox={`0 0 ${W} ${H}`} preserveAspectRatio="none">
      <polyline points={pts} fill="none" stroke={color} strokeWidth={1.6} strokeLinejoin="round" strokeLinecap="round" />
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

function SplitBar({
  title, subtitle, bd, color, prettify, money,
}: {
  title: string;
  subtitle: string;
  bd: AdsByCountry;
  color: (l: string) => string;
  prettify: (s: string) => string;
  money: (v: number | null) => string;
}) {
  if (!bd.hasData) return null;
  const total = bd.rows.reduce((a, r) => a + r.spend, 0) || 1;
  const rows = [...bd.rows].sort((a, b) => b.spend - a.spend);

  return (
    <div className="ads-panel">
      <div className="ads-ph"><h3>{title}</h3></div>
      <div className="ads-psub">{subtitle}</div>
      <div className="agender-bar">
        {rows.map((r) => (
          <span key={r.key} style={{ width: `${(r.spend / total) * 100}%`, background: color(r.label) }} title={`${prettify(r.label)} · ${Math.round((r.spend / total) * 100)}%`} />
        ))}
      </div>
      <div className="agender-legend">
        {rows.map((r) => (
          <div className="agender-item" key={r.key}>
            <span className="dot" style={{ background: color(r.label) }} />
            <b>{prettify(r.label)}</b>
            <span className="agender-meta">{Math.round((r.spend / total) * 100)}% · {money(r.spend)} · {formatRoas(r.roas)}</span>
          </div>
        ))}
      </div>
    </div>
  );
}

function genderColor(l: string): string {
  return /female/i.test(l) ? '#EC4899' : /male/i.test(l) ? '#2563EB' : '#94A3B8';
}

function audienceColor(l: string): string {
  const s = l.toLowerCase();
  if (s.includes('new')) return '#2563EB';
  if (s.includes('exist') || s.includes('return')) return '#16A34A';
  if (s.includes('engag')) return '#0EA5B7';
  return '#94A3B8';
}

function prettyAudience(s: string): string {
  return titleize(s);
}

function BreakdownPanel({
  title, subtitle, bd, currency, prettify,
}: {
  title: string;
  subtitle: string;
  bd: AdsByCountry;
  currency: string;
  prettify: (s: string) => string;
}) {
  const money = (v: number | null) => formatMoney(v, currency, { whole: true });
  const rows = bd.rows.slice(0, 8);
  const max = Math.max(1, ...rows.map((r) => r.spend));

  return (
    <div className="ads-panel">
      <div className="ads-ph"><h3>{title}</h3></div>
      <div className="ads-psub">{subtitle}</div>
      <div className="abrk">
        <div className="abrk-row abrk-head">
          <span>Segment</span>
          <span />
          <span className="abrk-val">Spend</span>
          <span className="abrk-pct">%</span>
          <span className="abrk-roas">ROAS</span>
        </div>
        {rows.map((r) => (
          <div className="abrk-row" key={r.key}>
            <span className="abrk-label" title={prettify(r.label)}>{prettify(r.label)}</span>
            <span className="abrk-track"><span className="abrk-bar" style={{ width: `${Math.max(3, (r.spend / max) * 100)}%` }} /></span>
            <span className="abrk-val num">{money(r.spend)}</span>
            <span className="abrk-pct num">{`${r.pct}%`}</span>
            <span className="abrk-roas num">{formatRoas(r.roas)}</span>
          </div>
        ))}
      </div>
      {bd.top && (
        <div className="ads-psub" style={{ margin: '12px 0 0' }}>
          Top: <strong>{prettify(bd.top.label)}</strong> · {formatNumber(bd.top.purchases)} purchases · {bd.top.pct}% of spend
        </div>
      )}
    </div>
  );
}

function titleize(s: string): string {
  return s.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function prettyPlacement(s: string): string {
  const map: Record<string, string> = {
    facebook: 'Facebook',
    instagram: 'Instagram',
    audience_network: 'Audience Network',
    messenger: 'Messenger',
    unknown: 'Unknown',
  };
  return map[s.toLowerCase()] ?? titleize(s);
}

function prettyPlacementDetail(s: string): string {
  return s.split('·').map((p) => prettyPlacement(p.trim()) === p.trim() ? titleize(p.trim()) : prettyPlacement(p.trim())).join(' · ');
}

function prettyDevice(s: string): string {
  const map: Record<string, string> = {
    desktop: 'Desktop',
    mobile_app: 'Mobile app',
    mobile_web: 'Mobile web',
    iphone: 'iPhone',
    ipad: 'iPad',
    android_smartphone: 'Android phone',
    android_tablet: 'Android tablet',
    unknown: 'Unknown',
  };
  return map[s.toLowerCase()] ?? titleize(s);
}

function prettyAgeGender(s: string): string {
  return s.split('·').map((p, i) => (i === 0 ? p.trim() : titleize(p.trim()))).join(' · ');
}
