import { countryName } from './countryNames';
import { formatMoney, formatNumber, formatRoas } from '@/lib/formatters';
import type { AdsByCountry, AdsOverviewResponse } from '@/types/ads';
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
  const s = data.summary;

  const panels: { title: string; subtitle: string; bd: AdsByCountry; prettify: (s: string) => string }[] = [
    { title: 'Age', subtitle: 'Attributed spend by age', bd: data.byAge, prettify: (x) => x },
    { title: 'Age & gender', subtitle: 'Attributed spend by age × gender', bd: data.byAgeGender, prettify: prettyAgeGender },
    { title: 'Placement', subtitle: 'By publisher platform', bd: data.byPlacement, prettify: prettyPlacement },
    { title: 'Placement detail', subtitle: 'By platform & position', bd: data.byPlacementDetail, prettify: prettyPlacementDetail },
    { title: 'Device', subtitle: 'By impression device', bd: data.byDeviceDetail, prettify: prettyDevice },
    { title: 'Country', subtitle: 'By country', bd: data.byCountry, prettify: countryName },
  ];
  const visible = panels.filter((p) => p.bd.hasData);
  const hasAny = data.byGender.hasData || visible.length > 0;

  return (
    <div className="ads-root">
      <div className="ads-panel">
        <div className="ads-ph"><h3>Audience overview</h3></div>
        <div className="ads-psub">All attributed performance · {data.from} – {data.to}</div>
        <div className="acrea-kpis" style={{ marginTop: 10 }}>
          <Kpi label="Spend" value={money(s.spend)} />
          <Kpi label="Revenue" value={money(s.revenue)} />
          <Kpi label="ROAS" value={formatRoas(s.roas)} />
          <Kpi label="Purchases" value={formatNumber(s.purchases)} />
          <Kpi label="CTR" value={s.ctr != null ? `${s.ctr}%` : '—'} />
          <Kpi label="CPA" value={s.cpa != null ? money(s.cpa) : '—'} />
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
          <GenderSplit bd={data.byGender} money={money} />
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

function Kpi({ label, value }: { label: string; value: string }) {
  return (
    <div className="acrea-kpi">
      <div className="k-label">{label}</div>
      <div className="k-val">{value}</div>
    </div>
  );
}

function GenderSplit({ bd, money }: { bd: AdsByCountry; money: (v: number | null) => string }) {
  if (!bd.hasData) return null;
  const total = bd.rows.reduce((a, r) => a + r.spend, 0) || 1;
  const color = (l: string) => (/female/i.test(l) ? '#EC4899' : /male/i.test(l) ? '#2563EB' : '#94A3B8');
  const rows = [...bd.rows].sort((a, b) => b.spend - a.spend);

  return (
    <div className="ads-panel">
      <div className="ads-ph"><h3>Gender</h3></div>
      <div className="ads-psub">Attributed spend split by gender</div>
      <div className="agender-bar">
        {rows.map((r) => (
          <span key={r.key} style={{ width: `${(r.spend / total) * 100}%`, background: color(r.label) }} title={`${r.label} · ${Math.round((r.spend / total) * 100)}%`} />
        ))}
      </div>
      <div className="agender-legend">
        {rows.map((r) => (
          <div className="agender-item" key={r.key}>
            <span className="dot" style={{ background: color(r.label) }} />
            <b>{r.label}</b>
            <span className="agender-meta">{Math.round((r.spend / total) * 100)}% · {money(r.spend)} · {formatRoas(r.roas)}</span>
          </div>
        ))}
      </div>
    </div>
  );
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
