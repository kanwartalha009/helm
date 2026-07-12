import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';

/**
 * Triangulated truth (GO-1.4). MER is the spine — store revenue ÷ total ad spend, from
 * money Shopify actually recorded. Each platform's reported ROAS sits beside it with
 * its documented bias direction.
 *
 * NOTE the shape: `platforms` is a LIST and there is deliberately no total of
 * reported revenue. Two platforms routinely claim the same order, so summing them
 * would produce a fiction. Never add a total here.
 */
export interface TruthPlatform {
  platform: 'meta' | 'google' | 'tiktok';
  spend: number;
  reportedRevenue: number;
  reportedRoas: number | null;
  label: string;
  annotation: string;
}

export interface TruthResponse {
  periodStart: string;
  periodEnd: string;
  currency: string;
  storeRevenue: number;
  totalSpend: number;
  mer: number | null;
  merLabel: string;
  merFormula: string;
  platforms: TruthPlatform[];
  divergenceNote: string;
}

export function useBrandTruth(slug: string | undefined, period = 'last30') {
  return useQuery({
    queryKey: ['brand', slug, 'truth', period],
    enabled: !!slug,
    queryFn: async (): Promise<TruthResponse> => {
      const { data } = await api.get<TruthResponse>(`/brands/${slug}/truth`, { params: { period } });
      return data;
    },
  });
}
