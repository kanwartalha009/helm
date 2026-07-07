import { type ReactNode, useEffect, useState } from 'react';
import { Banner } from '@/components/ui';
import { cn } from '@/lib/cn';
import { AdsOverviewView } from './AdsOverviewView';
import { AdsAudienceView } from './AdsAudienceView';
import { AdsCreativesView } from './AdsCreativesView';
import { useAdsOverview } from '@/hooks/useAdsOverview';
import type { AdsPeriod, AdsPlatform } from '@/types/ads';

type AdsTab = 'overview' | 'audience' | 'creatives';

/**
 * Loads one brand's Ads Overview for the chosen period and renders the states:
 * error, loading, the freshness banner (window not fully synced), then the view.
 * Shared by the /ads hub and the /brands/:slug/ads deep link. Audience and
 * Creatives are Meta-only, so their tabs only appear on Meta — no dead tabs.
 */
export function AdsBrandOverview({ slug, period, platform }: { slug: string | undefined; period: AdsPeriod; platform: AdsPlatform }) {
  const q = useAdsOverview(slug, period, undefined, undefined, !!slug, platform);
  const [tab, setTab] = useState<AdsTab>('overview');

  // Meta-only tabs disappear off Meta; snap back to Overview so we never render
  // a hidden tab's content.
  const metaTabs = platform === 'meta';
  useEffect(() => {
    if (!metaTabs && tab !== 'overview') setTab('overview');
  }, [metaTabs, tab]);

  if (q.isError) {
    return <StateCard>Couldn’t load ad performance for this brand. Try refreshing, or check the brand’s Meta connection.</StateCard>;
  }
  if (!q.data && q.isLoading) {
    return <StateCard>Loading ad performance…</StateCard>;
  }
  if (!q.data) return null;

  const tabs: { key: AdsTab; label: string }[] = [
    { key: 'overview', label: 'Overview' },
    ...(metaTabs ? ([{ key: 'audience', label: 'Audience' }, { key: 'creatives', label: 'Creatives' }] as { key: AdsTab; label: string }[]) : []),
  ];
  const active: AdsTab = tabs.some((t) => t.key === tab) ? tab : 'overview';

  return (
    <>
      {!q.data.isComplete && (
        <div style={{ marginBottom: 14 }}>
          <Banner variant="warning" icon={<WarnIcon />}>
            This window isn’t fully synced yet — some days are still pending, so these totals may rise once the sync completes.
          </Banner>
        </div>
      )}
      {tabs.length > 1 && (
        <div className="filter-bar mb-12">
          {tabs.map((t) => (
            <button key={t.key} type="button" className={cn('chip', active === t.key && 'active')} onClick={() => setTab(t.key)}>{t.label}</button>
          ))}
        </div>
      )}
      {active === 'overview' ? (
        <AdsOverviewView data={q.data} slug={slug} period={period} platform={platform} />
      ) : active === 'audience' ? (
        <AdsAudienceView data={q.data} />
      ) : (
        <AdsCreativesView slug={slug} period={period} platform={platform} />
      )}
    </>
  );
}

function StateCard({ children }: { children: ReactNode }) {
  return (
    <div
      style={{
        background: 'var(--surface)',
        border: '1px solid var(--border)',
        borderRadius: 'var(--radius-lg)',
        padding: 48,
        textAlign: 'center',
        color: 'var(--text-muted)',
        fontSize: 14,
      }}
    >
      {children}
    </div>
  );
}

function WarnIcon() {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8">
      <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
      <line x1="12" y1="9" x2="12" y2="13" />
      <line x1="12" y1="17" x2="12.01" y2="17" />
    </svg>
  );
}
