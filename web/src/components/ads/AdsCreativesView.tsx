import { useEffect, useState } from 'react';
import { api } from '@/lib/api';
import { useAdsCreatives } from '@/hooks/useAdsCreatives';
import { formatMoney, formatNumber, formatRoas } from '@/lib/formatters';
import type { AdsCreative, AdsPeriod, AdsPlatform } from '@/types/ads';
import '@/styles/ads.css';

/**
 * Creatives tab (Phase D) — a thumbnail grid of the brand's top ads by spend,
 * each with ROAS + key metrics, from ad_creative_daily. Thumbnails are the full
 * image / video poster (not Meta's tiny thumbnail_url), so they aren't pixelated.
 * Video ads get an IMG/VIDEO badge + a play button that opens an inline player;
 * the source URL is fetched fresh on click because Meta's are short-lived.
 */
export function AdsCreativesView({ slug, period, platform }: { slug?: string; period: AdsPeriod; platform: AdsPlatform }) {
  const q = useAdsCreatives(slug, period, !!slug, platform);
  const [playing, setPlaying] = useState<AdsCreative | null>(null);
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
            {d.rows.map((c) => (
              <CreativeCard key={c.adId} c={c} money={money} onPlay={() => setPlaying(c)} />
            ))}
          </div>
        ) : (
          <div className="ads-empty">
            Not synced yet. Run <code>meta:backfill-creatives</code> for this brand.
          </div>
        )}
      </div>
      {playing && slug && <VideoModal slug={slug} c={playing} onClose={() => setPlaying(null)} />}
    </div>
  );
}

function CreativeCard({ c, money, onPlay }: { c: AdsCreative; money: (v: number | null) => string; onPlay: () => void }) {
  const isVideo = c.mediaType === 'video';
  return (
    <div className={`acrea-card${isVideo ? ' is-video' : ''}`}>
      <div
        className="acrea-thumb"
        onClick={isVideo ? onPlay : undefined}
        role={isVideo ? 'button' : undefined}
        tabIndex={isVideo ? 0 : undefined}
        onKeyDown={isVideo ? (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onPlay(); } } : undefined}
      >
        {c.thumbnail ? (
          <img src={c.thumbnail} alt="" loading="lazy" referrerPolicy="no-referrer" />
        ) : (
          <span className="acrea-noimg">No preview</span>
        )}
        <span className="acrea-badge">{isVideo ? 'Video' : 'Image'}</span>
        {isVideo && <span className="acrea-play"><span /></span>}
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

/**
 * Inline player. On open it asks the API for a fresh video source URL (short-
 * lived on Meta's side, so resolved per-view). Falls back to the poster image +
 * a message when Meta won't return a source (dark posts / permission-gated),
 * rather than showing a broken player.
 */
function VideoModal({ slug, c, onClose }: { slug: string; c: AdsCreative; onClose: () => void }) {
  const [state, setState] = useState<'loading' | 'ready' | 'unavailable'>('loading');
  const [url, setUrl] = useState<string | null>(null);

  // Resolve a fresh source when the modal opens; ignore the result if the user
  // closes (or switches ads) before it lands.
  useEffect(() => {
    let live = true;
    api
      .get<{ url: string | null }>(`/brands/${slug}/ads/creatives/${encodeURIComponent(c.adId)}/video`)
      .then(({ data }) => {
        if (!live) return;
        if (data.url) { setUrl(data.url); setState('ready'); }
        else setState('unavailable');
      })
      .catch(() => { if (live) setState('unavailable'); });
    return () => { live = false; };
  }, [slug, c.adId]);

  return (
    <div className="acrea-modal" onClick={onClose}>
      <div className="acrea-modal-inner" onClick={(e) => e.stopPropagation()}>
        <button className="acrea-modal-close" onClick={onClose} aria-label="Close">×</button>
        {state === 'ready' && url ? (
          // eslint-disable-next-line jsx-a11y/media-has-caption
          <video src={url} poster={c.thumbnail ?? undefined} controls autoPlay playsInline />
        ) : (
          <div className="acrea-modal-state">
            {c.thumbnail && <img src={c.thumbnail} alt="" referrerPolicy="no-referrer" />}
            <span>{state === 'loading' ? 'Loading video…' : 'Video preview unavailable for this ad.'}</span>
          </div>
        )}
        <div className="acrea-modal-cap" title={c.name}>{c.name}</div>
      </div>
    </div>
  );
}
