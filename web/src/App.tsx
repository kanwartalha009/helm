import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { QueryClientProvider } from '@tanstack/react-query';
import { queryClient } from '@/lib/queryClient';
import { ErrorBoundary, Toaster } from '@/components/ui';
import { InvitationAcceptUrlModal } from '@/components/team/InvitationAcceptUrlModal';

import { LandingPage } from '@/routes/LandingPage';
import { OnboardingPage } from '@/routes/OnboardingPage';
import { LoginPage } from '@/routes/auth/LoginPage';
import { AcceptInvitePage } from '@/routes/auth/AcceptInvitePage';
import { ForgotPasswordPage } from '@/routes/auth/ForgotPasswordPage';
import { ResetPasswordPage } from '@/routes/auth/ResetPasswordPage';
import { MfaSetupPage } from '@/routes/auth/MfaSetupPage';
import { MfaVerifyPage } from '@/routes/auth/MfaVerifyPage';
import { AuthGate } from '@/components/shell/AuthGate';

import { DashboardPage } from '@/routes/DashboardPage';
import { SyncHealthPage } from '@/routes/SyncHealthPage';
import { BrandsPage } from '@/routes/BrandsPage';
import { BrandDetailPage } from '@/routes/BrandDetailPage';
import { BrandAdsPage } from '@/routes/BrandAdsPage';
import { BrandProductsPage } from '@/routes/BrandProductsPage';
import { BrandAuditPage } from '@/routes/BrandAuditPage';

// /add-brand routes removed — brand creation is now an in-place drawer
// triggered from the topbar / empty states / search palette.

import { SettingsPage } from '@/routes/SettingsPage';
import { ProfilePage } from '@/routes/ProfilePage';

import { TeamPage } from '@/routes/TeamPage';
import { UserDetailPage } from '@/routes/UserDetailPage';
import { AuditLogPage } from '@/routes/AuditLogPage';

import { TicketsPage } from '@/routes/TicketsPage';
import { TicketDetailPage } from '@/routes/TicketDetailPage';
// Invite-user and New-ticket are drawer-only flows now; the routes below
// redirect to the parent list pages so anyone hitting a stale bookmark
// lands somewhere sensible.

import { NotFoundPage } from '@/routes/NotFoundPage';
import { SitemapPage } from '@/routes/SitemapPage';

export function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <BrowserRouter
        future={{
          // Opt into v7 behavior early — silences the console warnings and
          // makes the upgrade later a no-op.
          v7_startTransition: true,
          v7_relativeSplatPath: true,
        }}
      >
        <Toaster />
        <InvitationAcceptUrlModal />
        <Routes>
          {/* Marketing */}
          <Route path="/" element={<LandingPage />} />
          <Route path="/sitemap" element={<SitemapPage />} />

          {/* Auth */}
          <Route path="/login" element={<LoginPage />} />
          <Route path="/accept-invite" element={<AcceptInvitePage />} />
          <Route path="/forgot-password" element={<ForgotPasswordPage />} />
          <Route path="/reset-password" element={<ResetPasswordPage />} />
          <Route path="/mfa/setup" element={<MfaSetupPage />} />
          <Route path="/mfa/verify" element={<MfaVerifyPage />} />

          {/* Onboarding — gated separately because the gate redirects to/from it */}
          <Route
            path="/onboarding"
            element={
              <AuthGate>
                <ErrorBoundary>
                  <OnboardingPage />
                </ErrorBoundary>
              </AuthGate>
            }
          />

          {/* Phase 1 — every authed route runs through AuthGate */}
          <Route path="/dashboard" element={<AuthGate><DashboardPage /></AuthGate>} />
          <Route path="/sync-health" element={<AuthGate><SyncHealthPage /></AuthGate>} />
          <Route path="/brands" element={<AuthGate><BrandsPage /></AuthGate>} />
          <Route path="/brands/:slug" element={<AuthGate><BrandDetailPage /></AuthGate>} />
          <Route path="/brands/:slug/ads" element={<AuthGate><BrandAdsPage /></AuthGate>} />
          <Route path="/brands/:slug/products" element={<AuthGate><BrandProductsPage /></AuthGate>} />
          <Route path="/brands/:slug/audit" element={<AuthGate><BrandAuditPage /></AuthGate>} />

          {/* /add-brand legacy URLs redirect to dashboard — the drawer is the
              only entry point now. */}
          <Route path="/add-brand" element={<Navigate to="/dashboard" replace />} />
          <Route path="/add-brand/connect" element={<Navigate to="/dashboard" replace />} />
          <Route path="/add-brand/sync" element={<Navigate to="/dashboard" replace />} />

          <Route path="/settings" element={<AuthGate><SettingsPage /></AuthGate>} />
          <Route path="/profile" element={<AuthGate><ProfilePage /></AuthGate>} />

          {/* Phase 1.5 */}
          <Route path="/team" element={<AuthGate><TeamPage /></AuthGate>} />
          <Route path="/team/invite" element={<Navigate to="/team" replace />} />
          <Route path="/team/users/:slug" element={<AuthGate><UserDetailPage /></AuthGate>} />
          <Route path="/audit-log" element={<AuthGate><AuditLogPage /></AuthGate>} />

          {/* Phase 3 */}
          <Route path="/tickets" element={<AuthGate><TicketsPage /></AuthGate>} />
          <Route path="/tickets/new" element={<Navigate to="/tickets" replace />} />
          <Route path="/tickets/:id" element={<AuthGate><TicketDetailPage /></AuthGate>} />

          {/* Convenience redirects from the old HTML filenames so old bookmarks resolve */}
          <Route path="/dashboard.html" element={<Navigate to="/dashboard" replace />} />
          <Route path="/index.html" element={<Navigate to="/" replace />} />

          {/* 404 */}
          <Route path="*" element={<NotFoundPage />} />
        </Routes>
      </BrowserRouter>
    </QueryClientProvider>
  );
}
