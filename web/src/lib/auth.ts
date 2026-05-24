// Tiny auth helper. The Sanctum bearer token lives in localStorage under
// `helm.auth.token`. The axios interceptor in lib/api.ts reads it back.

import { api } from './api';
import type { User } from '@/types/domain';

const TOKEN_KEY = 'helm.auth.token';

export interface LoginResponse {
  user?: User;
  token?: string;
  mfa_required?: boolean;
  pending_token?: string;
}

export async function login(email: string, password: string): Promise<LoginResponse> {
  const { data } = await api.post<LoginResponse>('/auth/login', { email, password });
  // Only persist the token when MFA wasn't required. If MFA is required the
  // backend returned mfa_required + pending_token (and no Sanctum token yet).
  if (data.token && !data.mfa_required) {
    localStorage.setItem(TOKEN_KEY, data.token);
  }
  return data;
}

export interface MfaVerifyChallengeResponse {
  user: User;
  token: string;
}

export async function verifyMfaChallenge(input: {
  pending_token: string;
  code: string;
}): Promise<MfaVerifyChallengeResponse> {
  const { data } = await api.post<MfaVerifyChallengeResponse>('/auth/mfa/verify', input);
  if (data.token) {
    localStorage.setItem(TOKEN_KEY, data.token);
  }
  return data;
}

export interface MfaSetupResponse {
  secret: string;
  otpauthUrl: string;
  qrCodeSvg: string;
  instructions: string;
}

export async function mfaSetup(): Promise<MfaSetupResponse> {
  const { data } = await api.post<MfaSetupResponse>('/auth/mfa/setup');
  return data;
}

export async function mfaVerifyEnrollment(code: string): Promise<{ enabled: boolean; user: User }> {
  const { data } = await api.post<{ enabled: boolean; user: User }>('/auth/mfa/verify', { code });
  return data;
}

export async function mfaDisable(currentPassword: string): Promise<{ enabled: boolean; user: User }> {
  const { data } = await api.post<{ enabled: boolean; user: User }>('/auth/mfa/disable', {
    current_password: currentPassword,
  });
  return data;
}

export async function logout(): Promise<void> {
  try {
    await api.post('/auth/logout');
  } catch {
    // ignore — we're clearing local state regardless
  }
  localStorage.removeItem(TOKEN_KEY);
}

export interface InvitationPreview {
  email: string;
  role: 'manager' | 'team_member' | 'brand_user';
  invitedBy: { name: string; email: string } | null;
  expiresAt: string;
}

export async function previewInvitation(token: string): Promise<InvitationPreview> {
  const { data } = await api.get<InvitationPreview>('/auth/invitations/preview', {
    params: { token },
  });
  return data;
}

export interface AcceptInvitationResponse {
  user: User;
  token: string;
}

export async function acceptInvitation(input: {
  token: string;
  name: string;
  password: string;
  password_confirmation: string;
}): Promise<AcceptInvitationResponse> {
  const { data } = await api.post<AcceptInvitationResponse>('/auth/invitations/accept', input);
  if (data.token) {
    localStorage.setItem(TOKEN_KEY, data.token);
  }
  return data;
}

export function getToken(): string | null {
  return localStorage.getItem(TOKEN_KEY);
}

export function isAuthenticated(): boolean {
  return !!getToken();
}

// Listener for the 401 event dispatched from the axios interceptor.
// Only acts if we *had* a token — i.e. a 401 on an actual authed call.
// Without this guard, transient 401s on unauthed pages (e.g. /login itself)
// would clear localStorage state needlessly.
window.addEventListener('helm:auth:unauthenticated', () => {
  const hadToken = !!localStorage.getItem(TOKEN_KEY);
  if (!hadToken) return;

  localStorage.removeItem(TOKEN_KEY);

  // Avoid bouncing if we're already on a public route.
  const publicPaths = ['/login', '/forgot-password', '/reset-password', '/accept-invite', '/'];
  if (!publicPaths.some((p) => window.location.pathname === p || window.location.pathname.startsWith(p + '/'))) {
    window.location.href = '/login';
  }
});
