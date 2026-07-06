import { type ReactNode } from 'react';
import { Banner } from '@/components/ui';
import { AdsOverviewView } from './AdsOverviewView';
import { useAdsOverview } from '@/hooks/useAdsOverview';
import type { AdsPeriod } from '@/types/ads';

/**
 * Loads one brand's Ads Overview for the chosen period and renders the states:
 * error, loading, the freshness banner (window not fully synced), then the view.
 * Shared by the /ads hub and the /brands/:slug/ads deep link.
 */
export function AdsBrandOverview({ slug, period }: { slug: string | undefined; period: AdsPeriod }) {
  const q = useAdsOverview(slug, period, undefined, undefined, !!slug);

  if (q.isError) {
    return <StateCard>Couldn’t load ad performance for this brand. Try refreshing, or check the brand’s Meta connection.</StateCard>;
  }
  if (!q.data && q.isLoading) {
    return <StateCard>Loading ad performance…</StateCard>;
  }
  if (!q.data) return null;

  return (
    <>
      {!q.data.isComplete && (
        <div style={{ marginBottom: 14 }}>
          <Banner variant="warning" icon={<WarnIcon />}>
            This window isn’t fully synced yet — some days are still pending, so these totals may rise once the sync completes.
          </Banner>
        </div>
      )}
      <AdsOverviewView data={q.data} />
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
