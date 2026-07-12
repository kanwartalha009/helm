import { useState } from 'react';
import { Button, Card, Chip, Drawer } from '@/components/ui';
import { formatNumber } from '@/lib/formatters';
import {
  useAdsLibraryMarket,
  useLiveSearch,
  useMarketAlerts,
  useResolvePage,
  useTrackedPages,
  useTrackPage,
  useUntrackPage,
} from '@/hooks/useAdsLibrary';
import { toast } from '@/stores/toastStore';
import { SaveToBoardButton } from '@/components/adslibrary/SaveToBoardButton';
import type { MarketFilters, MarketRow, MarketSort } from '@/types/adsLibrary';

const SORTS: { key: MarketSort; label: string }[] = [
  { key: 'signal', label: 'Signal score' },
  { key: 'rising', label: 'Rising' },
  { key: 'newest', label: 'Newest' },
  { key: 'longevity', label: 'Longest running' },
  { key: 'reach', label: 'EU reach' },
];

/**
 * Market library tab (Phase 3) — the EU Ad Library corpus, ranked by the disclosed
 * Signal Score, collapsed to one card per concept. Every metric is Proxy — public
 * signals; the coverage banner and badges say so. Tracking + "Search Meta live"
 * are gated to admin/manager (`canManage`).
 */
export function MarketView({ canManage, initialNiche = '' }: { canManage: boolean; initialNiche?: string }) {
  const [sort, setSort] = useState<MarketSort>('signal');
  const [niche, setNiche] = useState(initialNiche);
  const [media, setMedia] = useState('');
  const [active, setActive] = useState<'1' | 'all'>('1');
  const [search, setSearch] = useState('');
  const [open, setOpen] = useState<MarketRow | null>(null);

  const filters: MarketFilters = {
    sort,
    active,
    ...(niche ? { niche } : {}),
    ...(media ? { media_type: media as MarketFilters['media_type'] } : {}),
    ...(search.trim() ? { q: search.trim() } : {}),
  };
  const { data, isLoading } = useAdsLibraryMarket(filters);
  const { data: alerts = [] } = useMarketAlerts(niche || undefined);
  const liveSearch = useLiveSearch();

  const weights = data?.scoreWeights;
  const tooltip = sort === 'rising'
    ? 'Rising = EU reach ÷ days live — surfaces young ads pulling fast reach. A disclosed Proxy sort key, never performance.'
    : weights
      ? `Signal = ${weights.longevity_weight ?? 0.45}×longevity + ${weights.reach_weight ?? 0.3}×reach + ${weights.variants_weight ?? 0.25}×variants (percentiles within niche). A sort key — never performance.`
      : 'A disclosed sort key — never performance.';
  const sortLabel = sort === 'rising' ? 'Rising (reach velocity)' : 'Signal score';

  const runLive = () => {
    if (!search.trim()) return;
    liveSearch.mutate(
      { q: search.trim(), niche: niche || null, ...(media ? { media_type: media.toUpperCase() as 'IMAGE' | 'VIDEO' } : {}) },
      { onSuccess: (r) => toast.success('Corpus updated', `${r.upserted} ads pulled from Meta for “${search.trim()}”.`) },
    );
  };

  return (
    <>
      <div className="adlib-banner">
        {data?.coverageNote ?? 'EU delivery only — metrics are Proxy — public signals (reach, longevity, variants), never spend or ROAS.'}
      </div>

      <div className="filter-bar mb-16" style={{ flexWrap: 'wrap', gap: 8 }}>
        <select className="input" style={{ maxWidth: 170 }} value={sort} onChange={(e) => setSort(e.target.value as MarketSort)}>
          {SORTS.map((s) => <option key={s.key} value={s.key}>Sort: {s.label}</option>)}
        </select>
        <select className="input" style={{ maxWidth: 150 }} value={media} onChange={(e) => setMedia(e.target.value)}>
          <option value="">All formats</option>
          <option value="video">Video</option>
          <option value="image">Image</option>
        </select>
        <input className="input" style={{ maxWidth: 160 }} placeholder="Niche" value={niche} onChange={(e) => setNiche(e.target.value)} />
        <Chip active={active === '1'} onClick={() => setActive(active === '1' ? 'all' : '1')}>{active === '1' ? 'Active only' : 'Active + inactive'}</Chip>
        <span style={{ flex: 1 }} />
        <input
          className="input"
          placeholder="Keyword or competitor…"
          value={search}
          maxLength={100}
          onChange={(e) => setSearch(e.target.value)}
          style={{ maxWidth: 240 }}
        />
        {canManage && (
          <Button size="sm" variant="secondary" disabled={!search.trim() || liveSearch.isPending} onClick={runLive}>
            {liveSearch.isPending ? 'Searching Meta…' : 'Search Meta live'}
          </Button>
        )}
      </div>

      {alerts.length > 0 && (
        <Card style={{ padding: 14, marginBottom: 16 }}>
          <div style={{ fontWeight: 600, marginBottom: 8 }}>This week in your market</div>
          <div style={{ display: 'grid', gap: 5 }}>
            {alerts.map((a, i) => (
              <div key={i} className="flex items-center gap-8 text-sm">
                <span aria-hidden style={{ width: 7, height: 7, borderRadius: '50%', flexShrink: 0, background: a.severity === 'warn' ? 'var(--warning, #9a6700)' : 'var(--text-muted)' }} />
                <span>{a.message}</span>
              </div>
            ))}
          </div>
        </Card>
      )}

      {canManage && <TrackedCompetitors />}

      {data && (
        <div className="text-xs muted mb-16">
          {data.total > 0
            ? <>Top {Math.min(data.total, data.cap)} of {data.total} concept{data.total === 1 ? '' : 's'} · <span title={tooltip} style={{ borderBottom: '1px dotted var(--text-muted)', cursor: 'help' }}>{sortLabel} ⓘ</span> · Proxy — public signals</>
            : <>No stored ads yet — track a competitor page or run a live search.</>}
        </div>
      )}

      {isLoading && <div className="muted" style={{ padding: 24 }}>Loading market…</div>}

      {data && data.rows.length > 0 && (
        <div className="adlib-grid">
          {data.rows.map((r) => <MarketCard key={r.adArchiveId} row={r} onOpen={() => setOpen(r)} />)}
        </div>
      )}

      <Drawer open={open != null} onClose={() => setOpen(null)} size="lg" title={open?.pageName ?? 'Ad detail'}>
        {open && <MarketDetail row={open} />}
      </Drawer>
    </>
  );
}

function MarketCard({ row, onOpen }: { row: MarketRow; onOpen: () => void }) {
  const body = row.bodies[0] ?? row.linkTitles[0] ?? '';
  return (
    <Card className="adlib-card" style={{ padding: 0, overflow: 'hidden', cursor: 'pointer' }} onClick={onOpen}>
      <div className="adlib-body" style={{ paddingTop: 14 }}>
        <div className="adlib-chips">
          <span className="adlib-chip">{row.pageName ?? row.pageId}</span>
          {row.niche && <span className="adlib-chip subtle">{row.niche}</span>}
          {row.mediaType && <span className="adlib-chip subtle">{row.mediaType}</span>}
          {row.variants > 1 && <span className="adlib-chip subtle">{row.variants} variants</span>}
        </div>
        {body && <div className="adlib-copy" style={{ WebkitLineClamp: 4 }}>{body}</div>}
        <div className="adlib-metrics" style={{ gridTemplateColumns: 'repeat(3, 1fr)' }}>
          <div className="adlib-metric"><span className="l">Running</span><span className="v">{row.longevityDays !== null ? `${row.longevityDays}d` : '—'}</span></div>
          <div className="adlib-metric"><span className="l">EU reach</span><span className="v">{row.euReach !== null ? formatNumber(row.euReach) : '—'}</span></div>
          <div className="adlib-metric"><span className="l">Signal</span><span className="v primary">{row.signalScore !== null ? row.signalScore.toFixed(2) : '—'}</span></div>
        </div>
        <div className="adlib-badges">
          <span className="adlib-proxy">Proxy — public signals</span>
          {!row.isActive && <span className="adlib-chip subtle">inactive</span>}
        </div>
      </div>
    </Card>
  );
}

function MarketDetail({ row }: { row: MarketRow }) {
  return (
    <div style={{ display: 'grid', gap: 16 }}>
      <div className="adlib-banner">Proxy — public signals. EU delivery only. No spend or ROAS exists for commercial ads.</div>

      <div className="flex items-center gap-8" style={{ flexWrap: 'wrap' }}>
        <span className="adlib-chip">{row.pageName ?? row.pageId}</span>
        {row.niche && <span className="adlib-chip subtle">{row.niche}</span>}
        <span className="adlib-chip subtle">Running {row.longevityDays ?? '—'}d</span>
        <span className="adlib-chip subtle">{row.euReach !== null ? `${formatNumber(row.euReach)} reached in EU` : 'reach —'}</span>
        {row.variants > 1 && <span className="adlib-chip subtle">{row.variants} live variants</span>}
      </div>

      {row.bodies.length > 0 && (
        <Section title="Creative text">
          {row.bodies.map((b, i) => <p key={i} style={{ margin: '0 0 8px', lineHeight: 1.5 }}>{b}</p>)}
        </Section>
      )}
      {row.linkTitles.length > 0 && (
        <Section title="Headlines">{row.linkTitles.map((t, i) => <div key={i} className="muted text-sm">{t}</div>)}</Section>
      )}

      <Section title="Targeting (EU-disclosed)">
        <div className="muted text-sm">Gender: {row.targetGender ?? '—'}</div>
        <div className="muted text-sm">Ages: {row.targetAges ? JSON.stringify(row.targetAges) : '—'}</div>
        <div className="muted text-sm">Locations: {row.targetLocations ? JSON.stringify(row.targetLocations) : '—'}</div>
        <div className="muted text-sm">Payer / beneficiary: {row.beneficiaryPayers ? JSON.stringify(row.beneficiaryPayers) : '—'}</div>
      </Section>

      <div>
        {row.permalink && (
          <a href={row.permalink} target="_blank" rel="noreferrer">
            <Button variant="secondary" size="sm">View live on Facebook ↗</Button>
          </a>
        )}
        <span style={{ marginLeft: 8, display: 'inline-block' }}><SaveToBoardButton source="market" refId={row.adArchiveId} /></span>
      </div>
    </div>
  );
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div>
      <div className="text-xs" style={{ fontWeight: 700, letterSpacing: '.06em', textTransform: 'uppercase', color: 'var(--text-secondary)', marginBottom: 6 }}>{title}</div>
      {children}
    </div>
  );
}

function TrackedCompetitors() {
  const { data: pages = [] } = useTrackedPages();
  const resolve = useResolvePage();
  const track = useTrackPage();
  const untrack = useUntrackPage();
  const [input, setInput] = useState('');
  const [niche, setNiche] = useState('');

  const add = () => {
    if (!input.trim()) return;
    resolve.mutate(input.trim(), {
      onSuccess: (r) => {
        if (r.pageId) {
          track.mutate({ page_id: r.pageId, niche: niche || null }, { onSuccess: () => { setInput(''); toast.success('Tracking', `Page ${r.pageId} added.`); } });
        } else if (r.candidates.length > 0) {
          toast.info('Multiple matches', 'Paste the exact Ad Library URL to disambiguate.');
        } else {
          toast.error('Not found', 'Paste an Ad Library URL or numeric page id.');
        }
      },
    });
  };

  return (
    <Card style={{ padding: 14, marginBottom: 16 }}>
      <div className="flex items-center justify-between mb-8" style={{ flexWrap: 'wrap', gap: 8 }}>
        <div style={{ fontWeight: 600 }}>Tracked competitors</div>
        <div className="flex items-center gap-8" style={{ flexWrap: 'wrap' }}>
          <input className="input" style={{ maxWidth: 220 }} placeholder="Ad Library URL or page id" value={input} onChange={(e) => setInput(e.target.value)} />
          <input className="input" style={{ maxWidth: 120 }} placeholder="Niche" value={niche} onChange={(e) => setNiche(e.target.value)} />
          <Button size="sm" variant="secondary" disabled={!input.trim() || resolve.isPending || track.isPending} onClick={add}>Track</Button>
        </div>
      </div>
      {pages.length === 0 ? (
        <div className="muted text-sm">No competitors tracked yet. The nightly refresh sweeps each tracked page’s EU ads into the corpus.</div>
      ) : (
        <div style={{ display: 'grid', gap: 6 }}>
          {pages.map((p) => (
            <div key={p.id} className="flex items-center justify-between" style={{ fontSize: 13 }}>
              <span>{p.pageName ?? p.pageId} {p.niche && <span className="muted">· {p.niche}</span>} {p.status === 'paused' && <span className="muted">· paused</span>}</span>
              <span className="flex items-center gap-8">
                <span className="muted text-xs">{p.activeAds} active · {p.newThisWeek} new/wk</span>
                {p.status !== 'paused' && <button type="button" className="muted text-xs" style={{ background: 'none', border: 0, cursor: 'pointer' }} onClick={() => untrack.mutate(p.id)}>remove</button>}
              </span>
            </div>
          ))}
        </div>
      )}
    </Card>
  );
}
