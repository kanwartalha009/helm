import { Navigate, useLocation } from 'react-router-dom';
import { useCurrentUser } from '@/hooks/useSettings';
import { isAuthenticated } from '@/lib/auth';

/**
 * Wraps every authenticated route. Enforces three things in order:
 *   1. There must be a token in localStorage → otherwise bounce to /login.
 *   2. /api/auth/me must succeed → otherwise show a loader (the axios 401
 *      interceptor will redirect to /login on auth failure).
 *   3. The user must have completed onboarding → otherwise bounce to /onboarding.
 *
 * Hooks are called unconditionally at the top to comply with React's Rules
 * of Hooks. `useCurrentUser` internally checks for a token before firing,
 * so calling it when unauthed is a no-op.
 */
export function AuthGate({ children }: { children: React.ReactNode }) {
  // ALL hooks must run on every render — never conditionally.
  const location = useLocation();
  const authed = isAuthenticated();
  const { data: user, isLoading, isError } = useCurrentUser();

  // After hooks, branch with returns.
  if (!authed) {
    return <Navigate to="/login" replace state={{ from: location.pathname }} />;
  }

  if (isLoading) {
    return <FullScreenLoader />;
  }

  if (isError || !user) {
    // Token present but API rejected it. The axios 401 listener handles the
    // token wipe; this Navigate is the redirect that follows.
    return <Navigate to="/login" replace />;
  }

  // First-run onboarding gate.
  if (!user.onboardingComplete && location.pathname !== '/onboarding') {
    return <Navigate to="/onboarding" replace />;
  }
  if (user.onboardingComplete && location.pathname === '/onboarding') {
    return <Navigate to="/dashboard" replace />;
  }

  // Mandatory MFA for master_admin (spec §08) — force enrollment once
  // onboarding is done. /mfa/setup is an un-gated route, so this never loops.
  if (user.onboardingComplete && user.mfaRequired && location.pathname !== '/mfa/setup') {
    return <Navigate to="/mfa/setup" replace />;
  }

  return <>{children}</>;
}

function FullScreenLoader() {
  return (
    <div
      style={{
        minHeight: '100vh',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        color: 'var(--text-muted)',
        fontSize: 13,
        gap: 12,
      }}
    >
      <svg
        width="16"
        height="16"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth="2"
        style={{ animation: 'helm-spin 1s linear infinite' }}
      >
        <circle cx="12" cy="12" r="10" opacity="0.25" />
        <path d="M22 12a10 10 0 0 1-10 10" />
      </svg>
      Loading Roasdriven…
      <style>{`@keyframes helm-spin { to { transform: rotate(360deg); } }`}</style>
    </div>
  );
}
