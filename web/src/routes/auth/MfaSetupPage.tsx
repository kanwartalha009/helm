import { useEffect, useState } from 'react';
import { APP_NAME } from '@/lib/branding';
import { Link, useNavigate } from 'react-router-dom';
import { AuthLayout } from '@/components/shell/AuthLayout';
import { Banner, Button, Input, Tag } from '@/components/ui';
import { mfaSetup, mfaVerifyEnrollment, type MfaSetupResponse } from '@/lib/auth';
import { queryClient } from '@/lib/queryClient';
import { toast } from '@/stores/toastStore';

/**
 * Authenticated-user MFA enrollment page.
 *   1. On mount → POST /auth/mfa/setup (server generates secret + QR).
 *   2. Render QR (data: URI from server) + secret for manual entry.
 *   3. User enters 6-digit code from their authenticator app.
 *   4. POST /auth/mfa/verify { code } → server persists the secret iff the
 *      code matches → user.mfa_enabled flips true.
 */
export function MfaSetupPage() {
  const navigate = useNavigate();
  const [setupState, setSetupState] = useState<{
    loading: boolean;
    data: MfaSetupResponse | null;
    error: string | null;
  }>({ loading: true, data: null, error: null });
  const [code, setCode] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [verifyError, setVerifyError] = useState<string | null>(null);
  // After a successful enroll the server returns single-use recovery codes,
  // shown here exactly once before the user continues.
  const [recoveryCodes, setRecoveryCodes] = useState<string[] | null>(null);

  useEffect(() => {
    let cancelled = false;
    mfaSetup()
      .then((data) => {
        if (!cancelled) setSetupState({ loading: false, data, error: null });
      })
      .catch((err: any) => {
        if (cancelled) return;
        const msg = err?.response?.data?.message ?? err.message ?? 'Could not start MFA setup.';
        setSetupState({ loading: false, data: null, error: msg });
      });
    return () => {
      cancelled = true;
    };
  }, []);

  const onSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (code.length !== 6) return;
    setVerifyError(null);
    setSubmitting(true);
    try {
      const { user, recoveryCodes: codes } = await mfaVerifyEnrollment(code);
      // Refresh the cached current user so AuthGate's MFA gate releases
      // immediately (mfaRequired flips false) instead of bouncing back here.
      queryClient.setQueryData(['auth', 'me'], user);
      // Show the recovery codes step (don't navigate yet) — this is the only
      // time the plaintext codes are ever available.
      setRecoveryCodes(codes ?? []);
    } catch (err: any) {
      setVerifyError(err?.response?.data?.message ?? err.message ?? 'Verification failed.');
    } finally {
      setSubmitting(false);
    }
  };

  const copyCodes = () => {
    if (!recoveryCodes) return;
    navigator.clipboard?.writeText(recoveryCodes.join('\n')).then(
      () => toast.success('Copied', 'Recovery codes copied to your clipboard.'),
      () => toast.error('Copy failed', 'Select and copy the codes manually.'),
    );
  };

  const downloadCodes = () => {
    if (!recoveryCodes) return;
    const body = `${APP_NAME} — two-factor recovery codes\nGenerated ${new Date().toISOString()}\n\n${recoveryCodes.join('\n')}\n\nEach code works once. Keep them somewhere safe and private.\n`;
    const url = URL.createObjectURL(new Blob([body], { type: 'text/plain' }));
    const a = document.createElement('a');
    a.href = url;
    a.download = 'helm-recovery-codes.txt';
    a.click();
    URL.revokeObjectURL(url);
  };

  // Step 2 — recovery codes. Shown once, gated behind an explicit acknowledge.
  if (recoveryCodes) {
    return (
      <AuthLayout homeTo="/dashboard">
        <div className="auth-card" style={{ maxWidth: 460 }}>
          <div style={{ textAlign: 'center', marginBottom: 20 }}>
            <Tag style={{ marginBottom: 16 }}>Save your recovery codes</Tag>
            <h2>Two-factor is on</h2>
            <p className="mt-8 text-sm">
              Store these codes somewhere safe. Each one signs you in <strong>once</strong> if you lose
              your authenticator. This is the only time they&rsquo;re shown.
            </p>
          </div>

          <div
            style={{
              display: 'grid',
              gridTemplateColumns: '1fr 1fr',
              gap: 8,
              padding: 16,
              background: 'var(--surface-subtle)',
              border: '1px solid var(--border)',
              borderRadius: 'var(--radius)',
              fontFamily: 'var(--font-mono)',
              fontSize: 14,
              letterSpacing: '0.04em',
              marginBottom: 16,
            }}
          >
            {recoveryCodes.map((c) => (
              <span key={c} style={{ textAlign: 'center', color: 'var(--text)' }}>{c}</span>
            ))}
          </div>

          <div className="flex gap-12" style={{ marginBottom: 20 }}>
            <Button type="button" variant="secondary" size="sm" className="w-full" onClick={copyCodes}>
              Copy
            </Button>
            <Button type="button" variant="secondary" size="sm" className="w-full" onClick={downloadCodes}>
              Download .txt
            </Button>
          </div>

          <Button
            type="button"
            variant="primary"
            size="lg"
            className="w-full"
            onClick={() => {
              toast.success('MFA enabled', 'You’ll be asked for a code on your next sign-in.');
              navigate('/profile', { replace: true });
            }}
          >
            I&rsquo;ve saved my recovery codes
          </Button>
        </div>
      </AuthLayout>
    );
  };

  if (setupState.loading) {
    return (
      <AuthLayout homeTo="/dashboard">
        <div className="auth-card" style={{ maxWidth: 460 }}>
          <p className="muted" style={{ textAlign: 'center' }}>Generating your enrollment secret…</p>
        </div>
      </AuthLayout>
    );
  }

  if (setupState.error || !setupState.data) {
    return (
      <AuthLayout homeTo="/dashboard">
        <div className="auth-card" style={{ maxWidth: 460 }}>
          <Banner variant="warning" className="mb-16">
            {setupState.error ?? 'Could not start MFA setup.'}
          </Banner>
          <Link to="/profile" className="btn btn-secondary btn-sm">← Back to profile</Link>
        </div>
      </AuthLayout>
    );
  }

  const { secret, qrCodeSvg } = setupState.data;

  return (
    <AuthLayout homeTo="/dashboard">
      <div className="auth-card" style={{ maxWidth: 460 }}>
        <div style={{ textAlign: 'center', marginBottom: 24 }}>
          <Tag style={{ marginBottom: 16 }}>Two-factor auth</Tag>
          <h2>Set up your authenticator</h2>
          <p className="mt-8 text-sm">
            Scan the code below with Google Authenticator, Authy, 1Password, or Bitwarden.
          </p>
        </div>

        <div
          style={{
            background: 'var(--surface)',
            border: '1px solid var(--border)',
            borderRadius: 'var(--radius)',
            padding: 20,
            display: 'flex',
            justifyContent: 'center',
            marginBottom: 16,
          }}
        >
          <img src={qrCodeSvg} alt="MFA QR code" width={220} height={220} />
        </div>

        <details style={{ marginBottom: 24 }}>
          <summary style={{ cursor: 'pointer', fontSize: 13, color: 'var(--text-secondary)', padding: '6px 0' }}>
            Can&rsquo;t scan? Enter the code manually
          </summary>
          <div
            style={{
              marginTop: 8,
              padding: '10px 12px',
              background: 'var(--surface-subtle)',
              border: '1px solid var(--border)',
              borderRadius: 'var(--radius)',
              fontFamily: 'var(--font-mono)',
              fontSize: 13,
              letterSpacing: '0.05em',
              color: 'var(--text)',
              wordBreak: 'break-all',
            }}
          >
            {secret}
          </div>
          <p className="text-xs muted mt-8">
            Account name: <span className="mono">{APP_NAME}</span> · Type: <span className="mono">Time-based (TOTP)</span> · Digits: <span className="mono">6</span>
          </p>
        </details>

        {verifyError && (
          <Banner variant="warning" className="mb-16">
            {verifyError}
          </Banner>
        )}

        <form className="flex flex-col gap-12" onSubmit={onSubmit}>
          <Input
            label="6-digit code from your app"
            id="code"
            type="text"
            inputMode="numeric"
            maxLength={6}
            placeholder="000000"
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
            disabled={code.length !== 6 || submitting}
          >
            {submitting ? 'Enabling…' : 'Enable two-factor auth'}
          </Button>
        </form>

        <div className="mt-32" style={{ textAlign: 'center' }}>
          <p className="text-xs muted">
            <Link to="/profile" style={{ color: 'var(--text)', fontWeight: 500 }}>
              Cancel and go back
            </Link>
          </p>
        </div>
      </div>
    </AuthLayout>
  );
}
