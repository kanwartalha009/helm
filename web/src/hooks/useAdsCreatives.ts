import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { AdsCreativesResponse, AdsPeriod, AdsPlatform } from '@/types/ads';

/**
 * GET /api/brands/{slug}/ads/creatives — top ad-level creatives for the brand +
 * platform over the window. Fetched lazily (only when the Creatives tab is open).
 */
export function useAdsCreatives(
  slug: string | undefined,
  period: AdsPeriod,
  enabled: boolean = true,
  platform: AdsPlatform = 'meta',
) {
  return useQuery({
    queryKey: ['ads-creatives', slug, period, platform],
    enabled: enabled && !!slug,
    queryFn: async (): Promise<AdsCreativesResponse> => {
      const { data } = await api.get<AdsCreativesResponse>(`/brands/${slug}/ads/creatives`, { params: { period, platform } });
      return data;
    },
  });
}
