import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { AdSetsResponse, AdsPeriod, AdsPlatform } from '@/types/ads';

/**
 * GET /api/brands/{slug}/ads/campaigns/{campaign}/adsets — the campaign's ad sets
 * (Google ad groups / PMax asset groups, TikTok ad groups) with USD spend, ROAS,
 * budget snapshot, learning status and rules-driven flags (spec §4 Phase 4).
 * Gated on the drawer being open so it only fires for the selected campaign.
 */
export function useAdsCampaignAdsets(
  slug: string | undefined,
  campaignId: string | undefined,
  period: AdsPeriod,
  enabled: boolean,
  platform: AdsPlatform,
) {
  return useQuery({
    queryKey: ['ads-campaign-adsets', slug, campaignId, period, platform],
    enabled: enabled && !!slug && !!campaignId,
    queryFn: async (): Promise<AdSetsResponse> => {
      const { data } = await api.get<AdSetsResponse>(
        `/brands/${slug}/ads/campaigns/${encodeURIComponent(campaignId as string)}/adsets`,
        { params: { period, platform } },
      );
      return data;
    },
  });
}
