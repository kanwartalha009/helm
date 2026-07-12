import { useEffect, useState } from 'react';
import { APP_NAME } from '@/lib/branding';
import { Link, useNavigate } from 'react-router-dom';
import { AuthLayout } from '@/components/shell/AuthLayout';
import { Banner, Button, Input, Tag } from '@/components/ui';
import { verifyMfaChallenge } from '@/lib/auth';
import { toast } from '@/stores/toastStore';

/**
 * Second factor of sign-in. /auth/login returns mfa_required + pending_token
 * when the account is MFA-enrolled. LoginPage stashes the pending_token in
 * sessionStorage and routes here. This page collects the 6-digit code, POSTs
 * to /auth/mfa/verify, and on success stores the real Sanctum token.
 *
 * The pending_token expires server-side after 5 minutes — if the user
 * refreshes outside the same tab or the cache entry expires, we send them
 * back to /login with a clear message.
 */
export function MfaVerifyPage() {
  const navigate = useNavigate();
  const [code, setCode] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [pendingToken, setPendingToken] = useState<string | null>(null);

  useEffect(() => {
    const token = sessionStorage.getItem('helm.mfa.pending');
    if (!token) {
      // No challenge in flight — sane fallback rather than a stuck empty form.
      navigate('/login', { replace: true });
      return;
    }
    setPendingToken(token);
  }, [navigate]);

  const onSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!pendingToken || code.length !== 6) return;
    setError(null);
    setSubmitting(true);
    try {
      const res = await verifyMfaChallenge({ pending_token: pendingToken, code });
      sessionStorage.removeItem('helm.mfa.pending');
      toast.success('Signed in', `Welcome back, ${res.user.name}.`);
      navigate('/dashboard', { replace: true });
    } catch (err: any) {
      const status = err?.response?.status;
      const msg = err?.response?.data?.message ?? err.message ?? 'Verification failed.';
      // 410 = pending_token expired or already consumed — restart the flow.
      if (status === 410) {
        sessionStorage.removeItem('helm.mfa.pending');
        toast.error('Sign-in expired', msg);
        navigate('/login', { replace: true });
        return;
      }
      setError(msg);
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <AuthLayout homeTo="/dashboard">
      <div className="auth-card" style={{ maxWidth: 420 }}>
        <div style={{ textAlign: 'center', marginBottom: 24 }}>
          <Tag style={{ marginBottom: 16 }}>Two-factor auth</Tag>
          <h2>Enter your 6-digit code</h2>
          <p className="mt-8 text-sm">
            Open your authenticator app (Google Authenticator, Authy, 1Password) and enter the
            current code for {APP_NAME}.
          </p>
        </div>

        {error && (
          <Banner variant="warning" className="mb-16">
            {error}
          </Banner>
        )}

        <form className="flex flex-col gap-12" onSubmit={onSubmit}>
          <Input
            label="6-digit code"
            id="code"
            type="text"
            inputMode="numeric"
            maxLength={6}
            placeholder="000000"
            autoComplete="one-time-code"
            value={code}
            onChange={(e) => setCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
            autoFocus
            required
            style={{
              fontFamily: 'var(--font-mono)',
              letterSpacing: '0.2em',
              textAlign: 'center',
              fontSize: 18,
            }}
          />
          <Button
            type="submit"
            variant="primary"
            size="lg"
            className="w-full mt-8"
            disabled={code.length !== 6 || submitting || !pendingToken}
          >
            {submitting ? 'Verifying…' : 'Verify & continue'}
          </Button>
        </form>

        <div className="mt-32" style={{ textAlign: 'center' }}>
          <p className="text-xs muted">
            Lost your authenticator?{' '}
            <Link to="/login" style={{ color: 'var(--text)', fontWeight: 500 }}>
              Ask your admin to reset MFA
            </Link>
            .
          </p>
        </div>
      </div>
    </AuthLayout>
  );
}
