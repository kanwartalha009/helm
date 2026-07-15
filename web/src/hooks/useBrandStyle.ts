import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { toast } from '@/stores/toastStore';

/**
 * GO-4.4 (master plan §7.4) — brand moodboard / style. `status` is the confirm
 * gate: 'none' (nothing saved), 'draft' (suggestions, GO-5 refuses), or
 * 'confirmed' (operator signed off). `winners` are the brand's own verified
 * top creatives, always returned live so the moodboard shows them even before
 * a style is saved.
 */
export interface StyleSwatch {
  hex: string;
  weight?: number;
}

export interface StyleWinner {
  adId: string;
  adName: string | null;
  thumbnailUrl: string;
  roas: number;
  spend: number;
}

export interface BrandStyleResponse {
  status: 'none' | 'draft' | 'confirmed';
  palette: StyleSwatch[];
  toneWords: string[];
  doDont: { do: string[]; dont: string[] };
  refs: unknown[];
  winners: StyleWinner[];
  confirmedBy: number | null;
  confirmedAt: string | null;
}

export interface StyleSuggestion {
  palette: StyleSwatch[];
  toneWords: string[];
  winners: StyleWinner[];
}

export interface SaveBrandStyleBody {
  palette?: StyleSwatch[];
  toneWords?: string[];
  doDont?: { do: string[]; dont: string[] };
  refs?: unknown[];
  confirm?: boolean;
}

export function useBrandStyle(slug: string | undefined) {
  return useQuery({
    queryKey: ['brand', slug, 'style'],
    enabled: !!slug,
    queryFn: async (): Promise<BrandStyleResponse> => {
      const { data } = await api.get<BrandStyleResponse>(`/brands/${slug}/style`);
      return data;
    },
  });
}

/** The expensive best-effort suggest step (palette extraction + LLM tone). Returns suggestions, saves nothing. */
export function useSuggestBrandStyle(slug: string | undefined) {
  return useMutation({
    mutationFn: async (): Promise<StyleSuggestion> => {
      const { data } = await api.post<StyleSuggestion>(`/brands/${slug}/style/suggest`);
      return data;
    },
    onError: (err: any) =>
      toast.error('Couldn’t generate suggestions', err?.response?.data?.message ?? err?.message ?? 'Unknown error'),
  });
}

export function useSaveBrandStyle(slug: string | undefined) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (body: SaveBrandStyleBody) => {
      const { data } = await api.put(`/brands/${slug}/style`, body);
      return data;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['brand', slug, 'style'] }),
    onError: (err: any) =>
      toast.error('Couldn’t save the style', err?.response?.data?.message ?? err?.message ?? 'Admins and managers only.'),
  });
}
