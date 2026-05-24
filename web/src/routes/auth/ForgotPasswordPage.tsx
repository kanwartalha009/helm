import { useState } from 'react';
import { Link } from 'react-router-dom';
import { AuthLayout } from '@/components/shell/AuthLayout';
import { Banner, Button, Input, Tag } from '@/components/ui';
import { api } from '@/lib/api';

/**
 * POST /api/auth/password/forgot. Server replies with a neutral message
 * whether the email exists or not (account-enumeration defense), so the
 * frontend mirrors that: same "if an account exists…" copy either way.
 */
export function ForgotPasswordPage() {
  const [email, setEmail] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [sent, setSent] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const onSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      await api.post('/auth/password/forgot', { email });
      setSent(true);
    } catch (err: any) {
      const status = err?.response?.status;
      if (status === 429) {
        setError('Too many reset attempts. Wait a minute and try again.');
      } else {
        setError(err?.response?.data?.message ?? err.message ?? 'Could not send reset link.');
      }
    } finally {
      setSubmitting(false);
    }
  };

  if (sent) {
    return (
      <AuthLayout>
        <div className="auth-card">
          <div style={{ textAlign: 'center', marginBottom: 16 }}>
            <Tag variant="success" style={{ marginBottom: 16 }}>Email queued</Tag>
            <h2>Check your inbox</h2>
            <p className="mt-8 text-sm">
              If an account exists for <strong>{email}</strong>, a reset link is on its way. The link expires in 60 minutes.
            </p>
          </div>
          <Banner variant="info">
            <strong>Dev note:</strong> if your install uses the <span className="mono">log</span> mailer, the reset URL is appended to
            <span className="mono"> api/storage/logs/laravel.log</span> instead of being emailed.
          </Banner>
          <div className="mt-32" style={{ textAlign: 'center' }}>
            <Link to="/login" className="text-xs muted" style={{ color: 'var(--text)' }}>
              ← Back to sign in
            </Link>
          </div>
        </div>
      </AuthLayout>
    );
  }

  return (
    <AuthLayout>
      <div className="auth-card">
        <div style={{ textAlign: 'center', marginBottom: 32 }}>
          <h2>Reset your password</h2>
          <p className="mt-8 text-sm">Enter the email on your account. We&rsquo;ll send a reset link.</p>
        </div>

        {error && (
          <Banner variant="warning" className="mb-16">{error}</Banner>
        )}

        <form className="flex flex-col gap-12" onSubmit={onSubmit}>
          <Input
            label="Email"
            type="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            required
            autoComplete="email"
            autoFocus
          />
          <Button
            type="submit"
            variant="primary"
            size="lg"
            className="w-full mt-8"
            disabled={submitting || !email}
          >
            {submitting ? 'Sending…' : 'Send reset link'}
          </Button>
        </form>

        <div className="mt-32" style={{ textAlign: 'center' }}>
          <p className="text-xs muted">
            <Link to="/login" style={{ color: 'var(--text)', fontWeight: 500 }}>
              ← Back to sign in
            </Link>
          </p>
        </div>
      </div>
    </AuthLayout>
  );
}
