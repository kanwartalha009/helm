import { useEffect, useMemo, useState } from 'react';
import { api } from '@/lib/api';
import { useAdsCreatives } from '@/hooks/useAdsCreatives';
import { formatMoney, formatPercent, formatRoas } from '@/lib/formatters';
import type { AdsCreative, AdsCreativeState, AdsPeriod, AdsPlatform } from '@/types/ads';
import '@/styles/ads.css';

/**
 * Creatives tab (Phase D) — a creative-analytics grid: each ad carries a state
 * badge (scaling/declining/holding/testing/hidden), WoW% and its efficiency
 * metrics, on top of a full-res image or a playable video. Filter by media type,
 * sort, and pick the period; the KPI strip recomputes for whatever is on screen.
 * Data from ad_creative_daily via useAdsCreatives; "not synced" until the
 * backfill has run — never a fake €0.
 */

type MediaFilter = 'all' | 'video' | 'image';
type SortKey = 'spend' | 'roas' | 'ctr' | 'wow';
type PeriodChip = 'last7' | 'last14' | 'last30';

const STATE_LABEL: Record<AdsCreativeState, string> = {
  scaling: 'Scaling',
  declining: 'Declining',
  holding: 'Holding',
  testing: 'Testing',
  hidden: 'Hidden',
};

const SORTS: { key: SortKey; label: string }[] = [
  { key: 'spend', label: 'Spend' },
  { key: 'roas', label: 'ROAS' },
  { key: 'ctr', label: 'CTR' },
  { key: 'wow', label: 'WoW' },
];

const PERIODS: { key: PeriodChip; label: string }[] = [
  { key: 'last7', label: '7D' },
  { key: 'last14', label: '14D' },
  { key: 'last30', label: '30D' },
];

const RENDER_CAP = 48; // grid shows the top N by the active sort; "Show all" reveals the rest

export function AdsCreativesView({ slug, period, platform }: { slug?: string; period: AdsPeriod; platform: AdsPlatform }) {
  const [media, setMedia] = useState<MediaFilter>('all');
  const [sortKey, setSortKey] = useState<SortKey>('spend');
  const [dir, setDir] = useState<'desc' | 'asc'>('desc');
  const [chip, setChip] = useState<PeriodChip>(period === 'last7' ? 'last7' : period === 'last30' ? 'last30' : 'last14');
  const [expanded, setExpanded] = useState(false);
  const [playing, setPlaying] = useState<AdsCreative | null>(null);

  const q = useAdsCreatives(slug, chip, !!slug, platform);
  const d = q.data;
  const currency = d ? (d.currency === 'usd' ? 'USD' : d.baseCurrency || 'EUR') : 'EUR';
  const money = (v: number | null) => formatMoney(v, currency, { whole: true });

  const rows = useMemo(() => d?.rows ?? [], [d]);
  const filtered = useMemo(
    () => (media === 'all' ? rows : rows.filter((r) => r.mediaType === media)),
    [rows, media],
  );

  const sorted = useMemo(() => {
    const val = (r: AdsCreative): number =>
      sortKey === 'spend' ? r.spend
      : sortKey === 'roas' ? (r.roas ?? -1)
      : sortKey === 'ctr' ? (r.ctr ?? -1)
      : (r.wow ?? Number.NEGATIVE_INFINITY);
    return [...filtered].sort((a, b) => (dir === 'desc' ? val(b) - val(a) : val(a) - val(b)));
  }, [filtered, sortKey, dir]);

  const shown = expanded ? sorted : sorted.slice(0, RENDER_CAP);

  const kpi = useMemo(() => {
    const sum = (sel: (r: AdsCreative) => number) => filtered.reduce((s, r) => s + sel(r), 0);
    const spend = sum((r) => r.spend);
    const rev = sum((r) => r.revenue);
    const clk = sum((r) => r.clicks);
    const imp = sum((r) => r.impressions);
    const shownSpend = shown.reduce((s, r) => s + r.spend, 0);
    return {
      count: filtered.length,
      spend,
      roas: spend > 0 ? rev / spend : null,
      ctr: imp > 0 ? (clk / imp) * 100 : null,
      visiblePct: spend > 0 ? (shownSpend / spend) * 100 : null,
    };
  }, [filtered, shown]);

  if (q.isError) {
    return <div className="ads-root"><div className="ads-panel"><div className="ads-empty">Couldn’t load creatives. Try refreshing.</div></div></div>;
  }
  if (!d && q.isLoading) {
    return <div className="ads-root"><div className="ads-panel"><div className="ads-empty">Loading creatives…</div></div></div>;
  }
  if (!d) return null;

  if (!d.hasData) {
    return (
      <div className="ads-root">
        <div className="ads-panel">
          <div className="ads-ph"><h3>Creatives</h3></div>
          <div className="ads-empty">Not synced yet. Run <code>{platform === 'tiktok' ? 'tiktok:backfill-creatives' : 'meta:backfill-creatives'}</code> for this brand.</div>
        </div>
      </div>
    );
  }

  const mediaLabel = media === 'video' ? 'Video ads' : media === 'image' ? 'Image ads' : 'All creatives';
  const mediaIcon = media === 'video' ? '🎬' : media === 'image' ? '🖼️' : '📊';

  return (
    <div className="ads-root">
      <div className="ads-panel">
        <div className="ads-ph"><h3>Creatives</h3></div>

        {/* controls */}
        <div className="acrea-bar">
          <Seg value={media} onChange={(v) => { setMedia(v); setExpanded(false); }} options={[['all', 'All'], ['video', 'Video'], ['image', 'Image']]} />
          <div className="acrea-sort">
            <span className="acrea-sort-lbl">Sort</span>
            <div className="acrea-seg">
              {SORTS.map((s) => (
                <button key={s.key} className={sortKey === s.key ? 'is-on' : ''} onClick={() => setSortKey(s.key)}>{s.label}</button>
              ))}
            </div>
            <button
              className="acrea-dir"
              onClick={() => setDir((x) => (x === 'desc' ? 'asc' : 'desc'))}
              title={dir === 'desc' ? 'Best first (high → low)' : 'Worst first (low → high)'}
            >
              {dir === 'desc' ? '↓' : '↑'}
            </button>
          </div>
          <Seg value={chip} onChange={setChip} options={PERIODS.map((p) => [p.key, p.label] as [PeriodChip, string])} className="acrea-seg-right" />
        </div>

        {/* KPI strip — full width, recomputed for the active media filter */}
        <div className="ads-kpis">
          <KpiCard label="Unique creatives" color="#64748B" value={String(kpi.count)} />
          <KpiCard label="Weighted ROAS" color="#2563EB" value={formatRoas(kpi.roas)} series={d.trend.map((t) => (t.spend > 0 ? t.revenue / t.spend : 0))} />
          <KpiCard label="Weighted CTR" color="#0EA5B7" value={kpi.ctr != null ? `${kpi.ctr.toFixed(2)}%` : '—'} series={d.trend.map((t) => (t.impressions > 0 ? (t.clicks / t.impressions) * 100 : 0))} />
          <KpiCard label="Total spend" color="#16A34A" value={money(kpi.spend)} series={d.trend.map((t) => t.spend)} />
          <KpiCard label="Spend shown" color="#EC4899" value={kpi.visiblePct != null ? `${Math.round(kpi.visiblePct)}%` : '—'} pct={kpi.visiblePct} />
        </div>
        <div className="acrea-head">
          {mediaIcon} {mediaLabel} · {kpi.count} {kpi.count === 1 ? 'creative' : 'creatives'} · {d.from} – {d.to}
        </div>

        {shown.length === 0 ? (
          <div className="ads-empty">No {media === 'all' ? '' : media} creatives with spend in this window.</div>
        ) : (
          <>
            <div className="acrea-grid">
              {shown.map((c) => (
                <CreativeCard key={c.adId} c={c} platform={platform} money={money} wRoas={kpi.roas} spendDenom={kpi.spend} onPlay={() => setPlaying(c)} />
              ))}
            </div>
            {sorted.length > RENDER_CAP && (
              <div className="ads-viewall">
                <button onClick={() => setExpanded((x) => !x)}>
                  {expanded ? 'Show top 48' : `Show all ${sorted.length}`}
                </button>
              </div>
            )}
          </>
        )}
      </div>
      {playing && slug && <VideoModal slug={slug} c={playing} onClose={() => setPlaying(null)} />}
    </div>
  );
}

function Seg<T extends string>({ value, onChange, options, className }: { value: T; onChange: (v: T) => void; options: [T, string][]; className?: string }) {
  return (
    <div className={`acrea-seg${className ? ` ${className}` : ''}`}>
      {options.map(([v, label]) => (
        <button key={v} className={value === v ? 'is-on' : ''} onClick={() => onChange(v)}>{label}</button>
      ))}
    </div>
  );
}

function KpiCard({ label, color, value, series, pct }: { label: string; color: string; value: string; series?: number[]; pct?: number | null }) {
  return (
    <div className="ads-kpi">
      <div className="akpi-top">
        <span className="akpi-tick" style={{ background: color }} />
        <span className="akpi-label">{label}</span>
      </div>
      <div className="akpi-bot">
        <span className="akpi-val">{value}</span>
        {series && series.length > 1 ? (
          <Spark series={series} color={color} />
        ) : pct != null ? (
          <span className="akpi-progress"><span style={{ width: `${Math.min(100, Math.max(0, Math.round(pct)))}%`, background: color }} /></span>
        ) : null}
      </div>
    </div>
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

function CreativeCard({
  c, platform, money, wRoas, spendDenom, onPlay,
}: {
  c: AdsCreative;
  platform: AdsPlatform;
  money: (v: number | null) => string;
  wRoas: number | null;
  spendDenom: number;
  onPlay: () => void;
}) {
  // A video is playable only if its asset actually resolved. TikTok video +
  // no thumbnail = the /ad/get + /file endpoints were denied (token missing the
  // Ads Management scope), so the source fetch would fail too — render a clean
  // tile, not a dead play button. Meta is unaffected (its source always resolves).
  const isVideo = c.mediaType === 'video';
  const playable = isVideo && (platform !== 'tiktok' || !!c.thumbnail);
  const spendPct = spendDenom > 0 ? (c.spend / spendDenom) * 100 : null;
  // ROAS colored against the set's weighted ROAS: green ≥ weighted, red < 90% of it.
  const roasTone = c.roas == null || wRoas == null || wRoas <= 0 ? '' : c.roas >= wRoas ? ' pos' : c.roas < 0.9 * wRoas ? ' neg' : '';
  const wowTone = c.wow == null ? '' : c.wow > 0 ? ' pos' : c.wow < 0 ? ' neg' : '';

  return (
    <div className={`acrea-card${isVideo ? ' is-video' : ''}`}>
      <div
        className="acrea-thumb"
        onClick={playable ? onPlay : undefined}
        role={playable ? 'button' : undefined}
        tabIndex={playable ? 0 : undefined}
        onKeyDown={playable ? (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onPlay(); } } : undefined}
      >
        {c.thumbnail ? (
          <img src={c.thumbnail} alt="" loading="lazy" referrerPolicy="no-referrer" />
        ) : (
          <span className="acrea-noimg">No preview</span>
        )}
        <span className={`acrea-state ${c.state}`}>{STATE_LABEL[c.state]}</span>
        {c.wow != null && <span className={`acrea-wow${wowTone}`}>WoW {formatPercent(c.wow, { signed: true, decimals: 0 })}</span>}
        {c.wow == null && <span className="acrea-wow new">New</span>}
        {playable && <span className="acrea-play" aria-hidden><span /></span>}
        <span className="acrea-badge">{isVideo ? 'Video' : 'Image'}</span>
      </div>
      <div className="acrea-body">
        <div className="acrea-name" title={c.name}>{c.name}</div>
        <div className="acrea-stats">
          <Stat label="Spend" value={money(c.spend)} />
          <Stat label="Spend%" value={spendPct != null ? `${spendPct.toFixed(1)}%` : '—'} />
          <Stat label="ROAS" value={formatRoas(c.roas)} tone={roasTone} />
          <Stat label="CTR" value={c.ctr != null ? `${c.ctr.toFixed(2)}%` : '—'} />
          {isVideo ? (
            <>
              <Stat label="TS" value={c.ts != null ? `${c.ts.toFixed(1)}%` : '—'} />
              <Stat label="HR" value={c.hr != null ? `${c.hr.toFixed(1)}%` : '—'} />
            </>
          ) : (
            <>
              <Stat label="CtP" value={c.ctp != null ? `${c.ctp.toFixed(2)}%` : '—'} />
              <Stat label="CtATC" value={c.ctatc != null ? `${c.ctatc.toFixed(1)}%` : '—'} />
            </>
          )}
        </div>
      </div>
    </div>
  );
}

function Stat({ label, value, tone = '' }: { label: string; value: string; tone?: string }) {
  return (
    <div className="acrea-stat">
      <span className="s-label">{label}</span>
      <span className={`s-val${tone}`}>{value}</span>
    </div>
  );
}

/**
 * Inline player. On open it asks the API for a fresh video source URL (short-
 * lived on Meta's side, so resolved per-view). Falls back to the poster image +
 * a message when Meta won't return a source (dark posts / permission-gated).
 */
function VideoModal({ slug, c, onClose }: { slug: string; c: AdsCreative; onClose: () => void }) {
  const [state, setState] = useState<'loading' | 'ready' | 'unavailable'>('loading');
  const [url, setUrl] = useState<string | null>(null);

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
