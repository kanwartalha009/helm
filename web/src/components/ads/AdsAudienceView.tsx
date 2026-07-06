import { formatMoney, formatNumber, formatRoas } from '@/lib/formatters';
import type { AdsByCountry, AdsOverviewResponse } from '@/types/ads';
import '@/styles/ads.css';

/**
 * Audience tab (Phase C) — the demographic sub-views folded into the Ads hub:
 * spend by age × gender and by placement. Both read meta_breakdown_daily; each
 * panel shows a "not synced yet" state (with the exact backfill command) until
 * its axis has been backfilled, never a fake €0. Location lives on the Overview
 * map; device lives on the Overview donut — this tab is the demographic cut.
 */
export function AdsAudienceView({ data }: { data: AdsOverviewResponse }) {
  const currency = data.currency === 'usd' ? 'USD' : data.brand.baseCurrency || 'EUR';
  const metaOnly = data.platform !== 'meta';

  return (
    <div className="ads-root">
      <div className="ads-grid-2">
        <BreakdownPanel
          title="Age & gender"
          subtitle="Attributed spend by age × gender"
          bd={data.byAgeGender}
          currency={currency}
          axis="age_gender"
          prettify={(s) => s}
          metaOnly={metaOnly}
        />
        <BreakdownPanel
          title="Placement"
          subtitle="Attributed spend by platform"
          bd={data.byPlacement}
          currency={currency}
          axis="placement_platform"
          prettify={prettyPlacement}
          metaOnly={metaOnly}
        />
      </div>
    </div>
  );
}

function BreakdownPanel({
  title,
  subtitle,
  bd,
  currency,
  axis,
  prettify,
  metaOnly,
}: {
  title: string;
  subtitle: string;
  bd: AdsByCountry;
  currency: string;
  axis: string;
  prettify: (s: string) => string;
  metaOnly: boolean;
}) {
  const money = (v: number | null) => formatMoney(v, currency, { whole: true });

  if (!bd.hasData) {
    return (
      <div className="ads-panel">
        <div className="ads-ph"><h3>{title}</h3></div>
        <div className="ads-psub">{subtitle}</div>
        <div className="ads-empty">
          {metaOnly ? 'Available for Meta only.' : (<>Not synced yet. Run <code>meta:backfill-breakdown --type={axis}</code> for this brand.</>)}
        </div>
      </div>
    );
  }

  const rows = bd.rows.slice(0, 8);
  const max = Math.max(1, ...rows.map((r) => r.spend));

  return (
    <div className="ads-panel">
      <div className="ads-ph"><h3>{title}</h3></div>
      <div className="ads-psub">{subtitle}</div>
      <div className="abrk">
        <div className="abrk-row abrk-head">
          <span>{title === 'Placement' ? 'Platform' : 'Segment'}</span>
          <span />
          <span className="abrk-val">Spend</span>
          <span className="abrk-roas">ROAS</span>
        </div>
        {rows.map((r) => (
          <div className="abrk-row" key={r.key}>
            <span className="abrk-label" title={prettify(r.label)}>{prettify(r.label)}</span>
            <span className="abrk-track"><span className="abrk-bar" style={{ width: `${Math.max(3, (r.spend / max) * 100)}%` }} /></span>
            <span className="abrk-val num">{money(r.spend)}</span>
            <span className="abrk-roas num">{formatRoas(r.roas)}</span>
          </div>
        ))}
      </div>
      {bd.top && (
        <div className="ads-psub" style={{ margin: '12px 0 0' }}>
          Top: <strong>{prettify(bd.top.label)}</strong> · {formatNumber(bd.top.purchases)} purchases · CPA{' '}
          {bd.top.cpa != null ? money(bd.top.cpa) : '—'}
        </div>
      )}
    </div>
  );
}

function prettyPlacement(s: string): string {
  const map: Record<string, string> = {
    facebook: 'Facebook',
    instagram: 'Instagram',
    audience_network: 'Audience Network',
    messenger: 'Messenger',
    unknown: 'Unknown',
  };
  return map[s.toLowerCase()] ?? s.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}
