import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { AdsCampaignDetail, AdsPeriod, AdsPlatform } from '@/types/ads';

/**
 * GET /api/brands/{slug}/ads/campaigns/{campaign} — one campaign's detail for the
 * drill-down drawer. Gated on the drawer being open (a campaign selected).
 */
export function useAdsCampaign(
  slug: string | undefined,
  campaignId: string | undefined,
  period: AdsPeriod,
  enabled: boolean = true,
  platform: AdsPlatform = 'meta',
) {
  return useQuery({
    queryKey: ['ads-campaign', slug, campaignId, period, platform],
    enabled: enabled && !!slug && !!campaignId,
    queryFn: async (): Promise<AdsCampaignDetail> => {
      const { data } = await api.get<AdsCampaignDetail>(
        `/brands/${slug}/ads/campaigns/${encodeURIComponent(campaignId as string)}`,
        { params: { period, platform } },
      );
      return data;
    },
  });
}
