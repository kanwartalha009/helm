import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { toast } from '@/stores/toastStore';
import { useUiStore } from '@/stores/uiStore';
import type { User } from '@/types/domain';

export interface InviteUserInput {
  email: string;
  role: 'manager' | 'team_member' | 'brand_user';
  brand_ids?: number[];
  note?: string;
}

export interface Invitation {
  id: number;
  email: string;
  role: 'manager' | 'team_member' | 'brand_user';
  note: string | null;
  brandIds: number[];
  status: 'pending' | 'accepted' | 'revoked' | 'expired';
  invitedBy: { id: number; name: string; email: string } | null;
  expiresAt: string | null;
  acceptedAt: string | null;
  revokedAt: string | null;
  createdAt: string | null;
}

interface InviteResponse {
  invitation: Invitation;
  acceptUrl: string;
}

/**
 * POST /api/invitations — creates an invitation row + (Phase-1.5 follow-up)
 * sends the email. Today we return the accept URL inline so the agency owner
 * can paste it into a manual email while SMTP isn't connected.
 */
export function useInviteUser() {
  const qc = useQueryClient();
  const showAcceptUrl = useUiStore((s) => s.setInvitationAcceptUrl);
  return useMutation({
    mutationFn: async (input: InviteUserInput): Promise<InviteResponse> => {
      const { data } = await api.post<InviteResponse>('/invitations', input);
      return data;
    },
    onSuccess: (data) => {
      qc.invalidateQueries({ queryKey: ['users'] });
      qc.invalidateQueries({ queryKey: ['invitations'] });
      // Persistent modal because SMTP isn't wired yet — the admin needs to
      // copy/paste the accept URL to the invitee, and a 7-second toast loses
      // it forever (audit item).
      showAcceptUrl({
        email: data.invitation.email,
        acceptUrl: data.acceptUrl,
      });
      toast.success('Invitation created', `Accept link is ready for ${data.invitation.email}.`);
    },
    onError: (err: any) => {
      const msg =
        err?.response?.data?.errors?.email?.[0] ??
        err?.response?.data?.message ??
        err.message;
      toast.error("Couldn't send invitation", msg);
    },
  });
}

/** GET /api/invitations — every invitation row, most recent first. */
export function useInvitations() {
  return useQuery({
    queryKey: ['invitations'],
    queryFn: async () => {
      const { data } = await api.get<Invitation[] | { data: Invitation[] }>('/invitations');
      return Array.isArray(data) ? data : data.data;
    },
  });
}

/** DELETE /api/invitations/{id} — revokes a pending invitation. */
export function useRevokeInvitation() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number) => {
      await api.delete(`/invitations/${id}`);
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['invitations'] });
      toast.success('Invitation revoked');
    },
    onError: (err: any) => {
      toast.error("Couldn't revoke invitation", err?.response?.data?.message ?? err.message);
    },
  });
}

/* ---- User CRUD (TeamPage + UserDetailPage) -------------------------- */

export interface UpdateUserInput {
  name?: string;
  role?: 'manager' | 'team_member' | 'brand_user' | 'master_admin';
  brand_ids?: number[];
  status?: 'active' | 'disabled';
}

/**
 * PATCH /api/users/{id} — partial update. Server validates with `sometimes`,
 * so callers only send the fields they're changing.
 */
export function useUpdateUser() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (input: { id: number; patch: UpdateUserInput }): Promise<User> => {
      const { data } = await api.patch<User>(`/users/${input.id}`, input.patch);
      return data;
    },
    onSuccess: (user) => {
      qc.invalidateQueries({ queryKey: ['users'] });
      qc.invalidateQueries({ queryKey: ['user', String(user.id)] });
      toast.success('User updated', `${user.name} saved.`);
    },
    onError: (err: any) => {
      const errors = err?.response?.data?.errors;
      const first = errors ? Object.values(errors)[0] : null;
      const msg = Array.isArray(first) ? first[0] : (err?.response?.data?.message ?? err.message);
      toast.error("Couldn't update user", msg);
    },
  });
}

/** DELETE /api/users/{id} — soft-disable. master_admin and self are blocked server-side. */
export function useDisableUser() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number) => {
      await api.delete(`/users/${id}`);
    },
    onSuccess: (_d, id) => {
      qc.invalidateQueries({ queryKey: ['users'] });
      qc.invalidateQueries({ queryKey: ['user', String(id)] });
      toast.success('User disabled');
    },
    onError: (err: any) => {
      toast.error("Couldn't disable user", err?.response?.data?.message ?? err.message);
    },
  });
}

/**
 * DELETE /api/users/{id}/permanent — hard-delete a DISABLED user. Irreversible.
 * The server requires the user to already be disabled and blocks master_admin +
 * self, so this is only wired to the Disabled tab.
 */
export function useDeleteUser() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number) => {
      await api.delete(`/users/${id}/permanent`);
    },
    onSuccess: (_d, id) => {
      qc.invalidateQueries({ queryKey: ['users'] });
      qc.invalidateQueries({ queryKey: ['user', String(id)] });
      toast.success('User removed', 'The account was permanently deleted.');
    },
    onError: (err: any) => {
      toast.error("Couldn't remove user", err?.response?.data?.message ?? err.message);
    },
  });
}
