import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { toast } from '@/stores/toastStore';

/**
 * GO-5.1 (master plan §8) — creative testing engine, text-only. Drafts start as
 * 'draft' and an operator moves them forward (approved → exported → launched);
 * nothing is ever auto-published. Generation REFUSES (422, reason
 * 'unconfirmed_style') until the brand's moodboard is confirmed (GO-4.4).
 */
export type CreativeKind = 'copy' | 'hook' | 'ugc_script';
export type CreativeStatus = 'draft' | 'approved' | 'exported' | 'launched';

export interface CreativeDraft {
  id: number;
  modality: string;
  kind: CreativeKind;
  content: Record<string, string>;
  status: CreativeStatus;
  model: string | null;
  launchedAdId: string | null;
  createdAt: string | null;
}

export function useCreativeDrafts(slug: string | undefined) {
  return useQuery({
    queryKey: ['brand', slug, 'creative-drafts'],
    enabled: !!slug,
    queryFn: async (): Promise<CreativeDraft[]> => {
      const { data } = await api.get<{ drafts: CreativeDraft[] }>(`/brands/${slug}/creative/drafts`);
      return data.drafts;
    },
  });
}

export function useGenerateCreative(slug: string | undefined) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (body: { n?: number; moment?: string }) => {
      const { data } = await api.post<{ generated: number }>(`/brands/${slug}/creative/generate`, body);
      return data;
    },
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: ['brand', slug, 'creative-drafts'] });
      toast.success('Variants generated', `${res.generated} draft${res.generated === 1 ? '' : 's'} to review.`);
    },
    onError: (err: any) => {
      const reason = err?.response?.data?.reason;
      if (reason === 'unconfirmed_style') {
        toast.error('Confirm your moodboard first', 'Creative generation needs a confirmed brand style — set it up in the Moodboard card above.');
      } else if (err?.response?.status === 422) {
        toast.error('Couldn’t generate', err?.response?.data?.message ?? 'Check the brand’s data.');
      } else {
        toast.error('Couldn’t generate', err?.response?.data?.message ?? err?.message ?? 'Admins and managers only.');
      }
    },
  });
}

export function useUpdateCreativeDraft(slug: string | undefined) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, ...body }: { id: number; content?: Record<string, string>; status?: CreativeStatus; launchedAdId?: string | null }) => {
      const { data } = await api.put(`/brands/${slug}/creative/drafts/${id}`, body);
      return data;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['brand', slug, 'creative-drafts'] }),
    onError: (err: any) => toast.error('Couldn’t update the draft', err?.response?.data?.message ?? 'Admins and managers only.'),
  });
}

export function useDiscardCreativeDraft(slug: string | undefined) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number) => {
      await api.delete(`/brands/${slug}/creative/drafts/${id}`);
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['brand', slug, 'creative-drafts'] }),
  });
}
