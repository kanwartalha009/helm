import { useEffect, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { AuthLayout } from '@/components/shell/AuthLayout';
import { Banner, Button, Input, Tag } from '@/components/ui';
import { api } from '@/lib/api';
import { toast } from '@/stores/toastStore';

/**
 * Consumes ?token + ?email from the URL the reset email contained and lets
 * the user pick a new password. Validates locally for length + match before
 * submit so the user gets immediate feedback.
 */
export function ResetPasswordPage() {
  const navigate = useNavigate();
  const [params] = useSearchParams();
  const token = params.get('token') ?? '';
  const email = params.get('email') ?? '';

  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [missingParams, setMissingParams] = useState(false);

  useEffect(() => {
    if (!token || !email) setMissingParams(true);
  }, [token, email]);

  const valid =
    password.length >= 12 && password === confirm && !!token && !!email;

  const onSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!valid) return;
    setError(null);
    setSubmitting(true);
    try {
      await api.post('/auth/password/reset', {
        token,
        email,
        password,
        password_confirmation: confirm,
      });
      toast.success('Password updated', 'Sign in with the new password.');
      navigate('/login', { replace: true });
    } catch (err: any) {
      setError(err?.response?.data?.message ?? err.message ?? 'Reset failed.');
    } finally {
      setSubmitting(false);
    }
  };

  if (missingParams) {
    return (
      <AuthLayout>
        <div className="auth-card">
          <div style={{ textAlign: 'center', marginBottom: 16 }}>
            <Tag variant="warning" style={{ marginBottom: 16 }}>Link incomplete</Tag>
            <h2>This reset link is missing parts</h2>
            <p className="mt-8 text-sm">
              Open the link from your email exactly as sent. If it’s been more than 60 minutes,
              request a fresh one.
            </p>
          </div>
          <Link to="/forgot-password" className="btn btn-primary btn-sm w-full">
            Request a new reset link
          </Link>
        </div>
      </AuthLayout>
    );
  }

  return (
    <AuthLayout>
      <div className="auth-card">
        <div style={{ textAlign: 'center', marginBottom: 32 }}>
          <h2>Choose a new password</h2>
          <p className="mt-8 text-sm">
            For <strong style={{ color: 'var(--text)', fontWeight: 500 }}>{email}</strong>
          </p>
        </div>

        {error && <Banner variant="warning" className="mb-16">{error}</Banner>}

        <form className="flex flex-col gap-12" onSubmit={onSubmit}>
          <Input
            label="New password"
            type="password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            placeholder="At least 12 characters"
            autoComplete="new-password"
            required
            hint="12 characters minimum. Mix of letters, numbers, and a symbol."
          />
          <Input
            label="Confirm password"
            type="password"
            value={confirm}
            onChange={(e) => setConfirm(e.target.value)}
            autoComplete="new-password"
            required
          />
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
            {submitting ? 'Updating…' : 'Update password'}
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
