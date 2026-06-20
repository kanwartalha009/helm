import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { AuthLayout } from '@/components/shell/AuthLayout';
import { Button, Input } from '@/components/ui';
import { login } from '@/lib/auth';
import { toast } from '@/stores/toastStore';

export function LoginPage() {
  const navigate = useNavigate();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      const result = await login(email, password);
      if (result.mfa_required && result.pending_token) {
        // Stash the pending challenge for /mfa/verify. sessionStorage so it
        // disappears if they close the tab — won't survive a refresh outside
        // the same tab, which is the security intent.
        sessionStorage.setItem('helm.mfa.pending', result.pending_token);
        navigate('/mfa/verify');
        return;
      }
      if (!result.user || !result.token) {
        setError('Sign-in returned an unexpected response. Try again.');
        return;
      }
      toast.success('Signed in', `Welcome back, ${result.user.name}.`);
      navigate('/dashboard');
    } catch (err: any) {
      const status = err?.response?.status;
      const msg =
        err?.response?.data?.message ??
        err?.response?.data?.errors?.email?.[0] ??
        err.message ??
        'Sign-in failed.';
      if (status === 422 || status === 401) {
        setError(msg);
      } else if (status === 500) {
        setError('Server error. Check the API logs.');
      } else {
        setError(msg);
      }
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <AuthLayout>
      <div className="auth-card">
        <div style={{ textAlign: 'center', marginBottom: 32 }}>
          <h2>Sign in to Roasdriven</h2>
          <p className="mt-8 text-sm">Use the email tied to your invitation.</p>
        </div>

        {error && (
          <div
            className="banner banner-warning mb-16"
            style={{ marginBottom: 16 }}
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75">
              <circle cx="12" cy="12" r="10" />
              <line x1="12" y1="8" x2="12" y2="12" />
              <line x1="12" y1="16" x2="12.01" y2="16" />
            </svg>
            <span>{error}</span>
          </div>
        )}

        <form className="flex flex-col gap-12" onSubmit={handleSubmit}>
          <Input
            label="Email"
            id="email"
            type="email"
            placeholder="you@agency.com"
            autoComplete="email"
            required
            value={email}
            onChange={(e) => setEmail(e.target.value)}
          />
          <Input
            label="Password"
            id="password"
            type="password"
            placeholder="••••••••"
            autoComplete="current-password"
            required
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            trailing={
              <Link to="/forgot-password" className="text-xs muted">
                Forgot password?
              </Link>
            }
          />
          <Button
            type="submit"
            variant="primary"
            size="lg"
            className="w-full mt-8"
            disabled={submitting}
          >
            {submitting ? 'Signing in…' : 'Sign in'}
          </Button>
        </form>

        <div className="mt-32" style={{ textAlign: 'center' }}>
          <p className="text-xs muted">
            Need an account?{' '}
            <a href="#" style={{ color: 'var(--text)', fontWeight: 500 }}>
              Ask your admin for an invitation
            </a>
            .
          </p>
        </div>
      </div>
    </AuthLayout>
  );
}
