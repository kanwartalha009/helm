import { useEffect, useState } from 'react';
import { APP_NAME } from '@/lib/branding';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { AuthLayout } from '@/components/shell/AuthLayout';
import { Banner, Button, Input, Tag } from '@/components/ui';
import { acceptInvitation, previewInvitation, type InvitationPreview } from '@/lib/auth';
import { toast } from '@/stores/toastStore';

const ROLE_LABEL: Record<InvitationPreview['role'], string> = {
  manager: 'manager',
  team_member: 'team member',
  brand_user: 'brand user',
};

/**
 * Real accept-invitation flow:
 *   1. ?token= in URL → GET /auth/invitations/preview to confirm validity.
 *   2. If valid, render the form with the invited email shown read-only.
 *   3. POST /auth/invitations/accept → store the returned Sanctum token →
 *      route to /onboarding so the new user picks their timezone/avatar.
 */
export function AcceptInvitePage() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const token = searchParams.get('token') ?? '';

  const [previewState, setPreviewState] = useState<{
    loading: boolean;
    preview: InvitationPreview | null;
    error: string | null;
  }>({ loading: true, preview: null, error: null });

  const [name, setName] = useState('');
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [submitting, setSubmitting] = useState(false);

  // Resolve the invitation on mount. We hit a 404 with a clear `message` if
  // the token is missing/expired/already-accepted — surface that as the error.
  useEffect(() => {
    if (!token) {
      setPreviewState({ loading: false, preview: null, error: 'This page expects an invitation token in the URL.' });
      return;
    }
    let cancelled = false;
    previewInvitation(token)
      .then((preview) => {
        if (!cancelled) setPreviewState({ loading: false, preview, error: null });
      })
      .catch((err: any) => {
        if (cancelled) return;
        const msg = err?.response?.data?.message ?? 'This invitation can no longer be redeemed.';
        setPreviewState({ loading: false, preview: null, error: msg });
      });
    return () => {
      cancelled = true;
    };
  }, [token]);

  const valid =
    name.trim().length > 0 &&
    password.length >= 12 &&
    password === confirm &&
    !!previewState.preview;

  const onSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!valid || !previewState.preview) return;
    setSubmitting(true);
    try {
      await acceptInvitation({
        token,
        name: name.trim(),
        password,
        password_confirmation: confirm,
      });
      toast.success(`Welcome to ${APP_NAME}`, `Signed in as ${previewState.preview.email}.`);
      navigate('/onboarding', { replace: true });
    } catch (err: any) {
      const errors = err?.response?.data?.errors;
      const firstField = errors ? Object.values(errors)[0] : null;
      const msg = Array.isArray(firstField)
        ? firstField[0]
        : (err?.response?.data?.message ?? err.message);
      toast.error("Couldn't accept invitation", msg);
    } finally {
      setSubmitting(false);
    }
  };

  if (previewState.loading) {
    return (
      <AuthLayout>
        <div className="auth-card">
          <p className="muted" style={{ textAlign: 'center' }}>Verifying invitation…</p>
        </div>
      </AuthLayout>
    );
  }

  if (previewState.error || !previewState.preview) {
    return (
      <AuthLayout>
        <div className="auth-card">
          <div style={{ textAlign: 'center', marginBottom: 24 }}>
            <Tag variant="warning" style={{ marginBottom: 16 }}>Invitation unavailable</Tag>
            <h2>We couldn’t open this invitation</h2>
            <p className="mt-8 text-sm">
              {previewState.error ?? 'This invitation can no longer be redeemed.'}
            </p>
          </div>
          <Banner variant="info">
            If you think this is wrong, ask the person who invited you to send a fresh link.
          </Banner>
          <div className="mt-24" style={{ textAlign: 'center' }}>
            <Link to="/login" style={{ color: 'var(--text)', fontWeight: 500 }}>
              Back to sign in
            </Link>
          </div>
        </div>
      </AuthLayout>
    );
  }

  const { email, role, invitedBy } = previewState.preview;

  return (
    <AuthLayout>
      <div className="auth-card">
        <div style={{ textAlign: 'center', marginBottom: 32 }}>
          <Tag style={{ marginBottom: 16 }}>Invitation</Tag>
          <h2>Set your password</h2>
          <p className="mt-8 text-sm">
            {invitedBy ? (
              <>
                You were invited by{' '}
                <strong style={{ color: 'var(--text)', fontWeight: 500 }}>
                  {invitedBy.name}
                </strong>{' '}
                as a{' '}
                <strong style={{ color: 'var(--text)', fontWeight: 500 }}>{ROLE_LABEL[role]}</strong>.
              </>
            ) : (
              <>
                You were invited as a{' '}
                <strong style={{ color: 'var(--text)', fontWeight: 500 }}>{ROLE_LABEL[role]}</strong>.
              </>
            )}
          </p>
        </div>

        <form className="flex flex-col gap-12" onSubmit={onSubmit}>
          <Input
            label="Email"
            id="email"
            type="email"
            value={email}
            disabled
            readOnly
            onChange={() => {}}
            style={{ background: 'var(--surface-subtle)', color: 'var(--text-secondary)' }}
            hint="The email your invitation was sent to."
          />
          <Input
            label="Your name"
            id="name"
            value={name}
            onChange={(e) => setName(e.target.value)}
            autoComplete="name"
            placeholder="e.g. Jordan Reeves"
            autoFocus
            required
          />
          <Input
            label="Password"
            id="password"
            type={showPassword ? 'text' : 'password'}
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            placeholder="At least 12 characters"
            autoComplete="new-password"
            required
            hint="12 characters minimum. Mix of letters, numbers, and a symbol."
          />
          <Input
            label="Confirm password"
            id="confirm"
            type={showPassword ? 'text' : 'password'}
            value={confirm}
            onChange={(e) => setConfirm(e.target.value)}
            autoComplete="new-password"
            required
          />
          <label className="text-xs muted" style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
            <input
              type="checkbox"
              checked={showPassword}
              onChange={(e) => setShowPassword(e.target.checked)}
            />
            Show passwords
          </label>
          {password && confirm && password !== confirm && (
            <span className="text-xs" style={{ color: 'var(--danger)' }}>
              Passwords don’t match yet.
            </span>
          )}
          <Button
            type="submit"
            variant="primary"
            size="lg"
            className="w-full mt-8"
            disabled={!valid || submitting}
          >
            {submitting ? 'Creating your account…' : 'Create account & sign in'}
          </Button>
        </form>

        <div className="mt-32" style={{ textAlign: 'center' }}>
          <p className="text-xs muted">
            Already have an account?{' '}
            <Link to="/login" style={{ color: 'var(--text)', fontWeight: 500 }}>
              Sign in
            </Link>
          </p>
        </div>
      </div>
    </AuthLayout>
  );
}
