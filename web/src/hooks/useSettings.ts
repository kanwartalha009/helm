import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { toast } from '@/stores/toastStore';
import type {
  PlatformCredential,
  PlatformCredentialSchema,
  User,
} from '@/types/domain';
import type { ReportBranding } from '@/types/reports';

// These hooks hit the REAL Laravel API at /api/* — no mocks.
// They're the production data path for the Settings page.

/* ---- Workspace settings (General tab) -------------------------------- */

export interface WorkspaceSettings {
  workspace_name: string;
  // primary_currency removed in Phase 1 — every brand on the dashboard
  // renders in its own native currency. The backend column is kept for
  // backwards-compat but unused.
  daily_sync_time: string;
  // White-label theme for client reports (read into CSS vars by ReportDocument).
  report_branding: ReportBranding;
}

export function useWorkspaceSettings() {
  return useQuery({
    queryKey: ['workspace-settings'],
    queryFn: async () => {
      const { data } = await api.get<WorkspaceSettings>('/workspace-settings');
      return data;
    },
  });
}

export function useUpdateWorkspaceSettings() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (changes: Partial<WorkspaceSettings>) => {
      const { data } = await api.patch('/workspace-settings', changes);
      return data as { settings: WorkspaceSettings; changed: string[] };
    },
    onSuccess: (data) => {
      qc.setQueryData(['workspace-settings'], data.settings);
      if (data.changed.length > 0) {
        toast.success('Workspace settings saved', `Updated ${data.changed.join(', ')}.`);
      } else {
        toast.info('No changes to save.');
      }
    },
    onError: (err: any) => {
      toast.error('Couldn\'t save settings', firstApiError(err));
    },
  });
}

/* ---- Current user (Account + Notifications tabs) --------------------- */

export function useCurrentUser() {
  // Don't fire when there's no token — the request would just 401 and
  // trigger the global unauthenticated handler unnecessarily on page loads
  // where the user is logged out (e.g. /login itself).
  const hasToken = typeof window !== 'undefined' && !!localStorage.getItem('helm.auth.token');

  return useQuery({
    queryKey: ['auth', 'me'],
    queryFn: async () => {
      const { data } = await api.get<User>('/auth/me');
      return data;
    },
    staleTime: 60_000,
    enabled: hasToken,
    retry: false, // 401 should fail fast, not retry — the listener handles it
  });
}

export function useUpdateProfile() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (changes: Partial<User>) => {
      const { data } = await api.patch<User>('/auth/me', changes);
      return data;
    },
    onSuccess: (user) => {
      qc.setQueryData(['auth', 'me'], user);
      toast.success('Profile saved');
    },
    onError: (err: any) => {
      toast.error('Couldn\'t save profile', firstApiError(err));
    },
  });
}

export interface ChangePasswordInput {
  current_password: string;
  new_password: string;
  new_password_confirmation: string;
}

export function useChangePassword() {
  return useMutation({
    mutationFn: async (input: ChangePasswordInput) => {
      const { data } = await api.post('/auth/password', input);
      return data as { message: string };
    },
    onSuccess: (data) => {
      toast.success('Password updated', data.message);
    },
    onError: (err: any) => {
      const fieldErrors = err?.response?.data?.errors;
      const firstError =
        fieldErrors?.current_password?.[0] ??
        fieldErrors?.new_password?.[0] ??
        err?.response?.data?.message ??
        err.message;
      toast.error('Couldn\'t change password', firstError);
      throw err; // re-throw so the form can keep the values visible
    },
  });
}

export function useUpdateNotificationPrefs() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (prefs: Record<string, boolean>) => {
      const { data } = await api.patch<User>('/auth/me', { notification_prefs: prefs });
      return data;
    },
    onSuccess: (user) => {
      qc.setQueryData(['auth', 'me'], user);
      toast.success('Notification preferences saved');
    },
    onError: (err: any) => {
      toast.error('Couldn\'t save preferences', firstApiError(err));
    },
  });
}

/* ---- Onboarding + avatar --------------------------------------------- */

export interface OnboardingInput {
  name?: string;
  display_initials?: string;
  timezone?: string;
  workspace_name?: string;
}

export function useCompleteOnboarding() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (input: OnboardingInput) => {
      // Only send fields the user actually filled — empty strings can trip
      // validation rules and don't represent intent anyway.
      const payload = Object.fromEntries(
        Object.entries(input).filter(([, v]) => v !== '' && v !== null && v !== undefined)
      );
      const { data } = await api.post<User>('/auth/onboarding', payload);
      return data;
    },
    onSuccess: (user) => {
      qc.setQueryData(['auth', 'me'], user);
      qc.invalidateQueries({ queryKey: ['workspace-settings'] });
      toast.success('Setup complete', 'Welcome to Roasdriven.');
    },
    onError: (err: any) => {
      toast.error('Couldn\'t save', firstApiError(err));
    },
  });
}

export function useUploadAvatar() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (file: File) => {
      const form = new FormData();
      form.append('avatar', file);
      const { data } = await api.post<User>('/auth/avatar', form, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      return data;
    },
    onSuccess: (user) => {
      qc.setQueryData(['auth', 'me'], user);
      toast.success('Avatar updated');
    },
    onError: (err: any) => {
      toast.error('Upload failed', firstApiError(err));
    },
  });
}

export function useDeleteAvatar() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async () => {
      const { data } = await api.delete<User>('/auth/avatar');
      return data;
    },
    onSuccess: (user) => {
      qc.setQueryData(['auth', 'me'], user);
      toast.success('Avatar removed');
    },
  });
}

/* ---- Platform credentials (Platform keys tab) ------------------------ */

export function usePlatformCredentialsLive() {
  return useQuery({
    queryKey: ['platform-credentials', 'live'],
    queryFn: async () => {
      const { data } = await api.get<{ data: PlatformCredential[] }>('/platform-credentials');
      // Laravel resource collections wrap in { data: [...] }
      return Array.isArray(data) ? data : data.data;
    },
  });
}

export function usePlatformCredentialSchemaLive() {
  return useQuery({
    queryKey: ['platform-credential-schema', 'live'],
    queryFn: async () => {
      const { data } = await api.get<PlatformCredentialSchema>('/platform-credentials/schema');
      return data;
    },
    staleTime: 60 * 60_000,
  });
}

export function useSaveCredential() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (input: {
      platform: string;
      key: string;
      value: string;
      label?: string;
    }) => {
      const { data } = await api.post('/platform-credentials', input);
      return data;
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['platform-credentials'] });
      toast.success('Credential saved');
    },
    onError: (err: any) => {
      toast.error('Couldn\'t save credential', firstApiError(err));
    },
  });
}

export function useRevokeCredential() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number) => {
      await api.delete(`/platform-credentials/${id}`);
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['platform-credentials'] });
      toast.success('Credential revoked');
    },
    onError: (err: any) => {
      toast.error('Couldn\'t revoke', firstApiError(err));
    },
  });
}

/**
 * POST /api/platform-credentials/{id}/reveal — returns the decrypted value
 * once, after the user re-enters their password. Server writes an audit row.
 * Not cached — every reveal is a fresh round-trip on purpose.
 */
export function useRevealCredential() {
  return useMutation({
    mutationFn: async (input: { id: number; password: string }) => {
      const { data } = await api.post<{ value: string }>(
        `/platform-credentials/${input.id}/reveal`,
        { password: input.password }
      );
      return data;
    },
    onError: (err: any) => {
      toast.error("Couldn't reveal credential", firstApiError(err));
    },
  });
}

export interface ConnectionTestResult {
  ok: boolean;
  message: string;
  testedAt: string;
}

export function useTestConnection() {
  return useMutation({
    mutationFn: async (platform: string) => {
      const { data } = await api.post<ConnectionTestResult>(
        `/platform-credentials/${platform}/test`
      );
      return { platform, ...data };
    },
    onSuccess: (result) => {
      if (result.ok) {
        toast.success(`${labelOf(result.platform)} connection works`, result.message);
      } else {
        toast.error(`${labelOf(result.platform)} connection failed`, result.message);
      }
    },
    onError: (err: any, platform) => {
      toast.error(`${labelOf(platform as string)} test errored`, firstApiError(err));
    },
  });
}

function labelOf(platform: string): string {
  return {
    shopify: 'Shopify',
    meta: 'Meta',
    google: 'Google',
    tiktok: 'TikTok',
  }[platform] ?? platform;
}

/**
 * Pull the most useful human-readable message out of an axios error.
 * Laravel 422 returns `{ message, errors: { field: [msg1, msg2] } }` — we
 * want to surface the first field error, not the generic "given data is invalid".
 */
function firstApiError(err: any): string {
  const errors = err?.response?.data?.errors;
  if (errors && typeof errors === 'object') {
    const firstField = Object.keys(errors)[0];
    const firstMsg = errors[firstField]?.[0];
    if (firstMsg) return firstMsg;
  }
  return err?.response?.data?.message ?? err?.message ?? 'Unknown error';
}
