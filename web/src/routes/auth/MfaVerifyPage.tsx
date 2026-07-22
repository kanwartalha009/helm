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
  const [mode, setMode] = useState<'totp' | 'recovery'>('totp');
  const [code, setCode] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [pendingToken, setPendingToken] = useState<string | null>(null);
  // "Trust this device for 14 days" — default ON so most users stop getting
  // asked every login (Kanwar, 2026-07-22). Uncheck on a shared/public computer.
  const [rememberDevice, setRememberDevice] = useState(true);

  useEffect(() => {
    const token = sessionStorage.getItem('helm.mfa.pending');
    if (!token) {
      // No challenge in flight — sane fallback rather than a stuck empty form.
      navigate('/login', { replace: true });
      return;
    }
    setPendingToken(token);
  }, [navigate]);

  const ready = mode === 'totp' ? code.length === 6 : code.trim().length >= 8;

  const switchMode = (next: 'totp' | 'recovery') => {
    setMode(next);
    setCode('');
    setError(null);
  };

  const onSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!pendingToken || !ready) return;
    setError(null);
    setSubmitting(true);
    try {
      const res = await verifyMfaChallenge({ pending_token: pendingToken, code: code.trim(), remember_device: rememberDevice });
      sessionStorage.removeItem('helm.mfa.pending');
      if (res.recoveryUsed) {
        const left = res.user.mfaRecoveryCodesRemaining ?? 0;
        toast.success('Signed in with a recovery code', `${left} recovery code${left === 1 ? '' : 's'} left — regenerate them in your profile.`);
      } else {
        toast.success('Signed in', `Welcome back, ${res.user.name}.`);
      }
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
          <h2>{mode === 'totp' ? 'Enter your 6-digit code' : 'Enter a recovery code'}</h2>
          <p className="mt-8 text-sm">
            {mode === 'totp'
              ? <>Open your authenticator app (Google Authenticator, Authy, 1Password) and enter the current code for {APP_NAME}.</>
              : <>Enter one of the single-use recovery codes you saved when you set up two-factor. Each code works once.</>}
          </p>
        </div>

        {error && (
          <Banner variant="warning" className="mb-16">
            {error}
          </Banner>
        )}

        <form className="flex flex-col gap-12" onSubmit={onSubmit}>
          {mode === 'totp' ? (
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
          ) : (
            <Input
              label="Recovery code"
              id="code"
              type="text"
              maxLength={20}
              placeholder="abcde-fghij"
              autoComplete="one-time-code"
              value={code}
              onChange={(e) => setCode(e.target.value.slice(0, 20))}
              autoFocus
              required
              style={{
                fontFamily: 'var(--font-mono)',
                letterSpacing: '0.1em',
                textAlign: 'center',
                fontSize: 16,
              }}
            />
          )}
          <label
            style={{ display: 'flex', alignItems: 'flex-start', gap: 8, fontSize: 13, cursor: 'pointer', userSelect: 'none' }}
          >
            <input
              type="checkbox"
              checked={rememberDevice}
              onChange={(e) => setRememberDevice(e.target.checked)}
              style={{ marginTop: 2 }}
            />
            <span>
              Trust this device for 14 days
              <span className="muted" style={{ display: 'block', fontSize: 11 }}>
                Skip the code on this browser next time. Leave off on a shared or public computer.
              </span>
            </span>
          </label>
          <Button
            type="submit"
            variant="primary"
            size="lg"
            className="w-full mt-8"
            disabled={!ready || submitting || !pendingToken}
          >
            {submitting ? 'Verifying…' : 'Verify & continue'}
          </Button>
        </form>

        <div className="mt-32" style={{ textAlign: 'center' }}>
          {mode === 'totp' ? (
            <p className="text-xs muted">
              Lost your authenticator?{' '}
              <button
                type="button"
                onClick={() => switchMode('recovery')}
                style={{ background: 'none', border: 0, padding: 0, color: 'var(--text)', fontWeight: 500, cursor: 'pointer', fontFamily: 'inherit' }}
              >
                Use a recovery code
              </button>
              .
            </p>
          ) : (
            <p className="text-xs muted">
              <button
                type="button"
                onClick={() => switchMode('totp')}
                style={{ background: 'none', border: 0, padding: 0, color: 'var(--text)', fontWeight: 500, cursor: 'pointer', fontFamily: 'inherit' }}
              >
                ← Back to authenticator code
              </button>
              {' · '}Out of codes?{' '}
              <Link to="/login" style={{ color: 'var(--text)', fontWeight: 500 }}>Ask your admin to reset MFA</Link>.
            </p>
          )}
        </div>
      </div>
    </AuthLayout>
  );
}
