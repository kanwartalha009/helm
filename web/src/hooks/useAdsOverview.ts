import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { AdsOverviewResponse, AdsPeriod, AdsPlatform } from '@/types/ads';

/**
 * GET /api/brands/{slug}/ads — the Ads hub Overview for one brand + platform over
 * a selectable window. Native brand currency. `from`/`to` are only sent for a
 * custom range. Gated on a brand being selected.
 */
export function useAdsOverview(
  slug: string | undefined,
  period: AdsPeriod,
  from?: string,
  to?: string,
  enabled: boolean = true,
  platform: AdsPlatform = 'meta',
) {
  const custom = period === 'custom' && !!from && !!to;
  return useQuery({
    queryKey: ['ads-overview', slug, period, platform, custom ? from : '', custom ? to : ''],
    enabled: !!slug && enabled,
    queryFn: async (): Promise<AdsOverviewResponse> => {
      const params: Record<string, string> = { period, platform };
      if (custom) {
        params.from = from as string;
        params.to = to as string;
      }
      const { data } = await api.get<AdsOverviewResponse>(`/brands/${slug}/ads`, { params });
      return data;
    },
  });
}
