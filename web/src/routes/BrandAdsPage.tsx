import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { AppLayout } from '@/components/shell/AppLayout';
import { Breadcrumb } from '@/components/ui';
import { AdsBrandOverview } from '@/components/ads/AdsBrandOverview';
import { AdPlatformToggle, adPlatformsOf } from '@/components/ads/AdPlatformToggle';
import { useBrand } from '@/hooks/useDashboardData';
import type { AdsPeriod, AdsPlatform } from '@/types/ads';

const PERIODS: { key: AdsPeriod; label: string }[] = [
  { key: 'last7', label: 'Last 7 days' },
  { key: 'last30', label: 'Last 30 days' },
  { key: 'lastmonth', label: 'Last month' },
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

  // Only offer platforms actually connected to this brand; if the current pick
  // isn't connected, fall back to the first one that is.
  const available = adPlatformsOf(brand?.platforms);
  useEffect(() => {
    if (available.length > 0 && !available.includes(platform)) setPlatform(available[0]);
  }, [available, platform]);

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
        <div className="segmented">
          {PERIODS.map((p) => (
            <button key={p.key} type="button" className={period === p.key ? 'active' : ''} onClick={() => setPeriod(p.key)}>{p.label}</button>
          ))}
        </div>
        <span style={{ flex: 1 }} />
        <AdPlatformToggle available={available} value={platform} onChange={setPlatform} />
      </div>

      <AdsBrandOverview slug={slug} period={period} platform={platform} />
    </AppLayout>
  );
}
