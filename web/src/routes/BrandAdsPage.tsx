import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { AppLayout } from '@/components/shell/AppLayout';
import { Breadcrumb } from '@/components/ui';
import { cn } from '@/lib/cn';
import { AdsBrandOverview } from '@/components/ads/AdsBrandOverview';
import { useBrand } from '@/hooks/useDashboardData';
import type { AdsPeriod, AdsPlatform } from '@/types/ads';

const PERIODS: { key: AdsPeriod; label: string }[] = [
  { key: 'last7', label: 'Last 7 days' },
  { key: 'last30', label: 'Last 30 days' },
  { key: 'mtd', label: 'Month to date' },
];

/**
 * Per-brand Ads Overview — the deep link from a brand's pages
 * (/brands/:slug/ads). Same view as the /ads hub, scoped to one brand.
 */
export function BrandAdsPage() {
  const { slug } = useParams();
  const { data: brand } = useBrand(slug);
  const [period, setPeriod] = useState<AdsPeriod>('last30');
  const [platform, setPlatform] = useState<AdsPlatform>('meta');

  return (
    <AppLayout title="Ad performance">
      <Breadcrumb
        crumbs={[
          { label: 'Brands', to: '/brands' },
          { label: brand?.name ?? 'Brand', to: `/brands/${slug}` },
          { label: 'Ads' },
        ]}
      />

      <div className="filter-bar mb-16" style={{ marginTop: 12 }}>
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

      <AdsBrandOverview slug={slug} period={period} platform={platform} />
    </AppLayout>
  );
}
