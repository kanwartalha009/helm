import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';

/**
 * Data-quality score (GO-1.3). Every component is measured, carries its weight and
 * the exact gap, and — where a backfill closes it — the `fix` dataset key that the
 * coverage card can run in one click.
 *
 * `meetsGate` is what GO-3/GO-4 recommendations will require: below the threshold,
 * Helm declines to advise and says what's missing instead of guessing.
 */
export interface QualityComponent {
  key: 'platforms' | 'freshness' | 'history' | 'grain' | 'costs';
  label: string;
  weight: number;
  applicable: boolean;
  ratio: number;
  points: number;
  detail: string;
  fix: 'history' | 'campaigns' | 'creatives' | null;
}

export interface DataQualityScore {
  score: number;
  threshold: number;
  meetsGate: boolean;
  tier: 'good' | 'ok' | 'poor';
  components: QualityComponent[];
}

export function useDataQuality(slug: string | undefined) {
  return useQuery({
    queryKey: ['brand', slug, 'data-quality'],
    enabled: !!slug,
    queryFn: async (): Promise<DataQualityScore> => {
      const { data } = await api.get<DataQualityScore>(`/brands/${slug}/data-quality`);
      return data;
    },
  });
}

export interface BrandQualityRow {
  brandId: number;
  slug: string;
  score: number;
  tier: 'good' | 'ok' | 'poor';
  meetsGate: boolean;
}

/**
 * Scores for every accessible brand — merged into the dashboard client-side. This is a
 * separate endpoint on purpose: the dashboard runs two engines behind a parity gate,
 * and quality has no business inside that blast radius.
 */
export function useBrandsQuality(enabled = true) {
  return useQuery({
    queryKey: ['brands-quality'],
    enabled,
    staleTime: 5 * 60 * 1000,
    queryFn: async (): Promise<{ threshold: number; rows: BrandQualityRow[] }> => {
      const { data } = await api.get<{ threshold: number; rows: BrandQualityRow[] }>('/brands-quality');
      return data;
    },
  });
}
