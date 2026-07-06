import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { AdsOverviewResponse, AdsPeriod } from '@/types/ads';

/**
 * GET /api/brands/{slug}/ads — the Ads hub Overview for one brand over a
 * selectable window. Native brand currency (single-brand view). `from`/`to` are
 * only sent for a custom range; the server ignores them otherwise. Gated on a
 * brand being selected.
 */
export function useAdsOverview(
  slug: string | undefined,
  period: AdsPeriod,
  from?: string,
  to?: string,
  enabled: boolean = true,
) {
  const custom = period === 'custom' && !!from && !!to;
  return useQuery({
    queryKey: ['ads-overview', slug, period, custom ? from : '', custom ? to : ''],
    enabled: !!slug && enabled,
    queryFn: async (): Promise<AdsOverviewResponse> => {
      const params: Record<string, string> = { period };
      if (custom) {
        params.from = from as string;
        params.to = to as string;
      }
      const { data } = await api.get<AdsOverviewResponse>(`/brands/${slug}/ads`, { params });
      return data;
    },
  });
}
