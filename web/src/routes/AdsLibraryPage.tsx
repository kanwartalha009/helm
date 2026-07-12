import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { AppLayout } from '@/components/shell/AppLayout';
import { Card, Chip } from '@/components/ui';
import { cn } from '@/lib/cn';
import { useAdsLibraryWinners } from '@/hooks/useAdsLibrary';
import { useCurrentUser } from '@/hooks/useSettings';
import { MarketView } from '@/components/adslibrary/MarketView';
import { BoardsView } from '@/components/adslibrary/BoardsView';
import { SaveToBoardButton } from '@/components/adslibrary/SaveToBoardButton';
import { formatMoney, formatRoas } from '@/lib/formatters';
import type { AdsLibraryWindow, WinnerRow, WinnerSort, WinnersFilters } from '@/types/adsLibrary';
import '@/styles/adslibrary.css';

const WINDOWS: { key: AdsLibraryWindow; label: string }[] = [
  { key: 'last30', label: 'Last 30 days' },
  { key: 'last90', label: 'Last 90 days' },
];
const SORTS: { key: WinnerSort; label: string }[] = [
  { key: 'roas', label: 'ROAS' },
  { key: 'spend', label: 'Spend' },
  { key: 'ctr', label: 'CTR' },
];

/**
 * Ads Library — internal winners (Phase 1). "What's actually working across our
 * brands" by REAL ROAS, evidence-gated, every card badged Verified — our data.
 * Market and Boards tabs arrive in later phases (shown disabled). Top-level page
 * in the Analyze nav section; white-label neutral copy.
 */
export function AdsLibraryPage() {
  const { data: user } = useCurrentUser();
  const canManage = user?.role === 'master_admin' || user?.role === 'manager';
  const [params] = useSearchParams();
  const deepTab = params.get('tab');
  const deepNiche = params.get('niche') ?? '';
  const [tab, setTab] = useState<'winners' | 'market' | 'boards'>(
    deepTab === 'market' || deepTab === 'boards' ? deepTab : 'winners',
  );
  const [window, setWindow] = useState<AdsLibraryWindow>('last30');
  const [sort, setSort] = useState<WinnerSort>('roas');
  const [niche, setNiche] = useState('');
  const [platform, setPlatform] = useState('');
  const [media, setMedia] = useState('');
  const [search, setSearch] = useState('');

  const filters: WinnersFilters = {
    window,
    sort,
    ...(niche ? { niche } : {}),
    ...(platform ? { platform: platform as WinnersFilters['platform'] } : {}),
    ...(media ? { media_type: media as WinnersFilters['media_type'] } : {}),
    ...(search.trim() ? { search: search.trim() } : {}),
  };
  const { data, isLoading, isError } = useAdsLibraryWinners(filters);

  const staleHrs = data?.asOf ? (Date.now() - new Date(data.asOf).getTime()) / 3_600_000 : null;
  const stale = staleHrs !== null && staleHrs > 48;

  return (
    <AppLayout title="Ads Library">
      <div className="page-header">
        <div>
          <h2 className="page-title">Ads library</h2>
          <p className="page-subtitle">Winning creatives across your brands, ranked by real ROAS — verified from your own ad accounts.</p>
        </div>
      </div>

      <div className="adlib-tabs">
        <button type="button" className={cn('adlib-tab', tab === 'winners' && 'active')} onClick={() => setTab('winners')}>Winners</button>
        <button type="button" className={cn('adlib-tab', tab === 'market' && 'active')} onClick={() => setTab('market')}>Market</button>
        <button type="button" className={cn('adlib-tab', tab === 'boards' && 'active')} onClick={() => setTab('boards')}>Boards</button>
      </div>

      {tab === 'market' && <MarketView canManage={canManage} initialNiche={deepNiche} />}
      {tab === 'boards' && <BoardsView />}

      {tab === 'winners' && (
      <>
      <div className="filter-bar mb-16" style={{ flexWrap: 'wrap', gap: 8 }}>
        {WINDOWS.map((w) => (
          <Chip key={w.key} active={window === w.key} onClick={() => setWindow(w.key)}>{w.label}</Chip>
        ))}
        <span style={{ width: 8 }} />
        <select className="input" style={{ maxWidth: 150 }} value={sort} onChange={(e) => setSort(e.target.value as WinnerSort)}>
          {SORTS.map((s) => <option key={s.key} value={s.key}>Sort: {s.label}</option>)}
        </select>
        <select className="input" style={{ maxWidth: 160 }} value={niche} onChange={(e) => setNiche(e.target.value)}>
          <option value="">All niches</option>
          {(data?.niches ?? []).map((n) => <option key={n} value={n}>{n}</option>)}
          <option value="__unassigned">Unassigned</option>
        </select>
        <select className="input" style={{ maxWidth: 140 }} value={platform} onChange={(e) => setPlatform(e.target.value)}>
          <option value="">All platforms</option>
          <option value="meta">Meta</option>
          <option value="google">Google</option>
          <option value="tiktok">TikTok</option>
        </select>
        <select className="input" style={{ maxWidth: 130 }} value={media} onChange={(e) => setMedia(e.target.value)}>
          <option value="">All formats</option>
          <option value="video">Video</option>
          <option value="image">Image</option>
        </select>
        <span style={{ flex: 1 }} />
        <input
          className="input"
          type="text"
          placeholder="Search hook, ad or brand…"
          value={search}
          maxLength={100}
          onChange={(e) => setSearch(e.target.value)}
          style={{ maxWidth: 260 }}
        />
      </div>

      {data && (
        <div className="text-xs muted mb-16">
          {data.total > 0
            ? <>Showing top {Math.min(data.total, data.cap)} of {data.total} verified winner{data.total === 1 ? '' : 's'} · {data.periodStart} – {data.periodEnd}</>
            : <>{data.periodStart} – {data.periodEnd}</>}
          {data.asOf && (
            <> · data as of {new Date(data.asOf).toLocaleDateString()}{stale && <span style={{ color: 'var(--warning)' }}> (may be stale)</span>}</>
          )}
        </div>
      )}

      {isLoading && <div className="muted" style={{ padding: 24 }}>Loading winners…</div>}
      {isError && <div className="muted" style={{ padding: 24 }}>Couldn’t load winners. Try refreshing.</div>}

      {data && data.rows.length === 0 && !isLoading && (
        <Card style={{ padding: 28, textAlign: 'center' }}>
          <div style={{ fontWeight: 600, marginBottom: 6 }}>No winners cleared the evidence floor</div>
          <div className="muted text-sm" style={{ maxWidth: 520, margin: '0 auto' }}>
            Only creatives with at least $50 of spend in this window are ranked — so we never call something a winner we can’t verify.
            Widen the window or clear filters. Copy search fills in once a fresh creatives sync has run.
          </div>
        </Card>
      )}

      {data && data.rows.length > 0 && (
        <div className="adlib-grid">
          {data.rows.map((r) => <WinnerCard key={`${r.platform}:${r.adId}`} row={r} />)}
        </div>
      )}
      </>
      )}
    </AppLayout>
  );
}

const PLATFORM_LABEL: Record<string, string> = { meta: 'Meta', google: 'Google', tiktok: 'TikTok' };

function WinnerCard({ row }: { row: WinnerRow }) {
  const [imgOk, setImgOk] = useState(true);
  const c = row.currency;
  return (
    <Card className="adlib-card" style={{ padding: 0, overflow: 'hidden' }}>
      <div className="adlib-thumb">
        {row.thumbnailUrl && imgOk ? (
          <img src={row.thumbnailUrl} alt="" loading="lazy" onError={() => setImgOk(false)} />
        ) : (
          <div className="adlib-thumb-ph">No preview</div>
        )}
        <span className="adlib-media">{row.mediaType === 'video' ? 'Video' : 'Image'}</span>
      </div>

      <div className="adlib-body">
        <div className="adlib-chips">
          <span className="adlib-chip">{row.brand.name}</span>
          <span className="adlib-chip subtle">{row.niche ?? 'Unassigned'}</span>
          <span className="adlib-chip subtle">{PLATFORM_LABEL[row.platform] ?? row.platform}</span>
        </div>

        <div className="adlib-name" title={row.name}>{row.name}</div>
        {row.bodyText && <div className="adlib-copy">{row.bodyText}</div>}

        <div className="adlib-metrics">
          <div className="adlib-metric">
            <span className="l">ROAS</span>
            <span className="v primary">{formatRoas(row.roas)}</span>
          </div>
          <div className="adlib-metric">
            <span className="l">CTR</span>
            <span className="v">{row.ctr !== null ? `${row.ctr}%` : '—'}</span>
          </div>
          <div className="adlib-metric">
            <span className="l">CPA</span>
            <span className="v">{row.cpa !== null ? formatMoney(row.cpa, c) : '—'}</span>
          </div>
          <div className="adlib-metric">
            <span className="l">Spend</span>
            <span className="v">{formatMoney(row.spend, c, { whole: true })}</span>
          </div>
          {row.mediaType === 'video' && (
            <>
              <div className="adlib-metric"><span className="l">Thumbstop</span><span className="v">{row.thumbstop !== null ? `${row.thumbstop}%` : '—'}</span></div>
              <div className="adlib-metric"><span className="l">Hold</span><span className="v">{row.hold !== null ? `${row.hold}%` : '—'}</span></div>
            </>
          )}
        </div>

        <div className="adlib-badges">
          <span className="adlib-verified">Verified — our data</span>
          {row.confidence === 'early' && <span className="adlib-early">Early signal</span>}
        </div>
        <div className="mt-8">
          <SaveToBoardButton source="internal" refId={row.adId} />
        </div>
      </div>
    </Card>
  );
}
