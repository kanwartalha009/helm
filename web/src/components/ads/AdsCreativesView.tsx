import { useAdsCreatives } from '@/hooks/useAdsCreatives';
import { formatMoney, formatNumber, formatRoas } from '@/lib/formatters';
import type { AdsCreative, AdsPeriod, AdsPlatform } from '@/types/ads';
import '@/styles/ads.css';

/**
 * Creatives tab (Phase D) — a thumbnail grid of the brand's top ads by spend,
 * each with ROAS + key metrics, from ad_creative_daily. Fetches lazily via its
 * own hook (only when the tab is open); shows a "not synced" state with the
 * backfill command until meta:backfill-creatives has run.
 */
export function AdsCreativesView({ slug, period, platform }: { slug?: string; period: AdsPeriod; platform: AdsPlatform }) {
  const q = useAdsCreatives(slug, period, !!slug, platform);
  const d = q.data;
  const currency = d ? (d.currency === 'usd' ? 'USD' : d.baseCurrency || 'EUR') : 'EUR';
  const money = (v: number | null) => formatMoney(v, currency, { whole: true });

  if (q.isError) {
    return <div className="ads-root"><div className="ads-panel"><div className="ads-empty">Couldn’t load creatives. Try refreshing.</div></div></div>;
  }
  if (!d && q.isLoading) {
    return <div className="ads-root"><div className="ads-panel"><div className="ads-empty">Loading creatives…</div></div></div>;
  }
  if (!d) return null;

  return (
    <div className="ads-root">
      <div className="ads-panel">
        <div className="ads-ph"><h3>Creatives</h3></div>
        <div className="ads-psub">Top ads by spend · {d.from} – {d.to}</div>
        {d.hasData ? (
          <div className="acrea-grid">
            {d.rows.map((c) => <CreativeCard key={c.adId} c={c} money={money} />)}
          </div>
        ) : (
          <div className="ads-empty">
            Not synced yet. Run <code>meta:backfill-creatives</code> for this brand.
          </div>
        )}
      </div>
    </div>
  );
}

function CreativeCard({ c, money }: { c: AdsCreative; money: (v: number | null) => string }) {
  return (
    <div className="acrea-card">
      <div className="acrea-thumb">
        {c.thumbnail ? (
          <img src={c.thumbnail} alt="" loading="lazy" referrerPolicy="no-referrer" />
        ) : (
          <span className="acrea-noimg">No preview</span>
        )}
        {c.roas != null && <span className="acrea-roas">{formatRoas(c.roas)}</span>}
      </div>
      <div className="acrea-body">
        <div className="acrea-name" title={c.name}>{c.name}</div>
        <div className="acrea-metrics">
          <span><b>{money(c.spend)}</b> spend</span>
          <span><b>{formatNumber(c.purchases)}</b> purch.</span>
          <span><b>{c.ctr != null ? `${c.ctr}%` : '—'}</b> CTR</span>
        </div>
      </div>
    </div>
  );
}
