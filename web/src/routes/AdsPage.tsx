import { useEffect, useMemo, useState } from 'react';
import { AppLayout } from '@/components/shell/AppLayout';
import { cn } from '@/lib/cn';
import { AdsBrandOverview } from '@/components/ads/AdsBrandOverview';
import { useDashboardData } from '@/hooks/useDashboardData';
import type { AdsPeriod, AdsPlatform } from '@/types/ads';
import type { DashboardRow, DashboardRowBrand } from '@/types/domain';

const PERIODS: { key: AdsPeriod; label: string }[] = [
  { key: 'last7', label: 'Last 7 days' },
  { key: 'last30', label: 'Last 30 days' },
  { key: 'mtd', label: 'Month to date' },
];

/**
 * Ads hub — a top-level page (like Inventory) that lands on a brand chooser and,
 * once a brand is picked, shows its ad-platform Overview with an in-page switcher.
 * White-label friendly: reads as "pick a client".
 */
export function AdsPage() {
  const [selectedSlug, setSelectedSlug] = useState<string | undefined>(undefined);
  const [period, setPeriod] = useState<AdsPeriod>('last30');
  const [platform, setPlatform] = useState<AdsPlatform>('meta');

  const { data: rows = [], isLoading } = useDashboardData('me');

  const brands = useMemo<DashboardRowBrand[]>(() => {
    const seen = new Set<string>();
    const uniq: DashboardRow[] = [];
    for (const r of rows) {
      if (!seen.has(r.brand.slug)) {
        seen.add(r.brand.slug);
        uniq.push(r);
      }
    }
    // Best-performing first — same default as the dashboard/inventory.
    uniq.sort((a, b) => {
      const av = a.rolling.totalSales;
      const bv = b.rolling.totalSales;
      const aDead = av == null || av === 0;
      const bDead = bv == null || bv === 0;
      if (aDead !== bDead) return aDead ? 1 : -1;
      if (!aDead && !bDead && av !== bv) return (bv as number) - (av as number);
      return a.brand.name.localeCompare(b.brand.name);
    });
    return uniq.map((r) => r.brand);
  }, [rows]);

  // Drop back to the chooser if a selected brand leaves scope.
  useEffect(() => {
    if (selectedSlug && brands.length > 0 && !brands.some((b) => b.slug === selectedSlug)) {
      setSelectedSlug(undefined);
    }
  }, [brands, selectedSlug]);

  const selected = brands.find((b) => b.slug === selectedSlug);

  if (!selectedSlug) {
    return (
      <AppLayout title="Ads">
        <Chooser brands={brands} loading={isLoading} onSelect={setSelectedSlug} />
      </AppLayout>
    );
  }

  return (
    <AppLayout title="Ads">
      <div className="filter-bar mb-16">
        <button type="button" className="filter-btn" onClick={() => setSelectedSlug(undefined)}>
          <span className="brand-avatar" style={{ width: 20, height: 20, fontSize: 9 }}>{selected?.initials}</span>
          <strong style={{ fontWeight: 500 }}>{selected?.name ?? 'Brand'}</strong>
          <span className="muted" style={{ marginLeft: 6 }}>Change</span>
        </button>
        <span style={{ width: 10 }} />
        {PERIODS.map((p) => (
          <button key={p.key} type="button" className={cn('chip', period === p.key && 'active')} onClick={() => setPeriod(p.key)}>
            {p.label}
          </button>
        ))}
        <span style={{ flex: 1 }} />
        <div className="segmented">
          <button type="button" className={platform === 'meta' ? 'active' : ''} onClick={() => setPlatform('meta')}>Meta</button>
          <button type="button" className={platform === 'google' ? 'active' : ''} onClick={() => setPlatform('google')}>Google</button>
          <button type="button" disabled title="Coming soon">TikTok</button>
        </div>
      </div>

      <AdsBrandOverview slug={selectedSlug} period={period} platform={platform} />
    </AppLayout>
  );
}

function Chooser({
  brands,
  loading,
  onSelect,
}: {
  brands: DashboardRowBrand[];
  loading: boolean;
  onSelect: (slug: string) => void;
}) {
  const [q, setQ] = useState('');
  const filtered = brands.filter((b) => b.name.toLowerCase().includes(q.trim().toLowerCase()));

  return (
    <div style={{ maxWidth: 560, margin: '7vh auto 0' }}>
      <h2 style={{ textAlign: 'center', marginBottom: 6 }}>Choose a brand</h2>
      <p className="lede" style={{ textAlign: 'center', margin: '0 auto 22px', maxWidth: 440 }}>
        Open a store to see its Meta ad performance — ROAS, spend, funnel, regions and campaigns.
      </p>

      <div style={{ position: 'relative' }}>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="16" height="16" style={{ position: 'absolute', left: 12, top: 12, color: 'var(--text-muted)', pointerEvents: 'none' }}>
          <circle cx="11" cy="11" r="8" />
          <path d="m21 21-4.3-4.3" />
        </svg>
        <input autoFocus className="input" style={{ height: 40, paddingLeft: 36, width: '100%' }} placeholder="Search brands…" value={q} onChange={(e) => setQ(e.target.value)} />
      </div>

      <div style={{ marginTop: 10, background: 'var(--surface)', border: '1px solid var(--border)', borderRadius: 'var(--radius-lg)', overflow: 'hidden' }}>
        {loading ? (
          <div style={{ padding: 24, textAlign: 'center', color: 'var(--text-muted)', fontSize: 14 }}>Loading brands…</div>
        ) : filtered.length === 0 ? (
          <div style={{ padding: 24, textAlign: 'center', color: 'var(--text-muted)', fontSize: 14 }}>No brands match.</div>
        ) : (
          <div style={{ maxHeight: 420, overflow: 'auto' }}>
            {filtered.map((b, i) => (
              <button
                key={b.slug}
                type="button"
                onClick={() => onSelect(b.slug)}
                style={{
                  display: 'flex', alignItems: 'center', gap: 11, width: '100%', textAlign: 'left',
                  padding: '11px 14px', border: 0, borderTop: i === 0 ? 'none' : '1px solid var(--border)',
                  background: 'transparent', font: 'inherit', cursor: 'pointer', color: 'var(--text)',
                }}
                onMouseEnter={(e) => (e.currentTarget.style.background = 'var(--surface-subtle)')}
                onMouseLeave={(e) => (e.currentTarget.style.background = 'transparent')}
              >
                <span className="brand-avatar" style={{ width: 22, height: 22, fontSize: 10 }}>{b.initials}</span>
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ fontWeight: 500 }}>{b.name}</div>
                  <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>{[b.region, b.baseCurrency].filter(Boolean).join(' · ')}</div>
                </div>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15" style={{ color: 'var(--text-muted)', flex: '0 0 auto' }}>
                  <path d="m9 18 6-6-6-6" />
                </svg>
              </button>
            ))}
          </div>
        )}
      </div>

      {!loading && (
        <div className="text-xs muted" style={{ textAlign: 'center', marginTop: 12 }}>
          {filtered.length} of {brands.length} {brands.length === 1 ? 'brand' : 'brands'}
        </div>
      )}
    </div>
  );
}
