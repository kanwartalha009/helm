import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';

/**
 * Per-brand Klaviyo private key (GO-1.1). The key is WRITE-ONLY — the API never
 * returns it, only a `connected` flag and a live test result.
 */
export interface KlaviyoTestResult {
  ok: boolean;
  message: string;
}

export function useBrandKlaviyo(slug?: string) {
  return useQuery({
    queryKey: ['brand', slug, 'klaviyo'],
    enabled: !!slug,
    queryFn: async (): Promise<{ connected: boolean }> => {
      const { data } = await api.get<{ connected: boolean }>(`/brands/${slug}/klaviyo`);
      return data;
    },
  });
}

export function useSaveBrandKlaviyo(slug?: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (apiKey: string): Promise<{ connected: boolean; test: KlaviyoTestResult }> => {
      const { data } = await api.put<{ connected: boolean; test: KlaviyoTestResult }>(`/brands/${slug}/klaviyo`, { api_key: apiKey });
      return data;
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['brand', slug, 'klaviyo'] });
      qc.invalidateQueries({ queryKey: ['brand', slug, 'data-coverage'] });
    },
  });
}

export function useTestBrandKlaviyo(slug?: string) {
  return useMutation({
    mutationFn: async (): Promise<KlaviyoTestResult> => {
      const { data } = await api.post<KlaviyoTestResult>(`/brands/${slug}/klaviyo/test`);
      return data;
    },
  });
}

export function useRemoveBrandKlaviyo(slug?: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (): Promise<void> => {
      await api.delete(`/brands/${slug}/klaviyo`);
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['brand', slug, 'klaviyo'] });
      qc.invalidateQueries({ queryKey: ['brand', slug, 'data-coverage'] });
    },
  });
}
