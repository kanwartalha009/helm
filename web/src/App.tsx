import type React from 'react';
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
import { BrandPlanningPage } from '@/routes/BrandPlanningPage';
import { BrandAskPage } from '@/routes/BrandAskPage';
import { ReportsPage } from '@/routes/ReportsPage';
import { ReportViewPage } from '@/routes/ReportViewPage';
import { MomReportPage } from '@/routes/MomReportPage';
import { PublicReportPage } from '@/routes/PublicReportPage';
import { InventoryPage } from '@/routes/InventoryPage';
import { AdsPage } from '@/routes/AdsPage';
import { ProductsPage } from '@/routes/ProductsPage';
import { StoreAuditPage } from '@/routes/StoreAuditPage';
import { AdsLibraryPage } from '@/routes/AdsLibraryPage';

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

/**
 * Every authenticated route = AuthGate (session/onboarding/MFA gates) +
 * ErrorBoundary. Before 2026-07-10 only /onboarding and the settings tabs
 * had a boundary, so one render error white-screened the whole dashboard
 * (audit 2026-07-10, layer: error surfaces). The boundary sits INSIDE the
 * gate so auth redirects still work when a page crashes.
 */
function Guarded({ children }: { children: React.ReactNode }) {
  return (
    <AuthGate>
      <ErrorBoundary>{children}</ErrorBoundary>
    </AuthGate>
  );
}

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

          {/* Public shared report — token-gated, no auth (a client opens the link Bosco sent) */}
          <Route path="/r/:token" element={<PublicReportPage />} />

          {/* Onboarding — gated separately because the gate redirects to/from it */}
          <Route
            path="/onboarding"
            element={
              <Guarded>
                <OnboardingPage />
              </Guarded>
            }
          />

          {/* Phase 1 — every authed route runs through AuthGate */}
          <Route path="/dashboard" element={<Guarded><DashboardPage /></Guarded>} />
          <Route path="/sync-health" element={<Guarded><SyncHealthPage /></Guarded>} />
          <Route path="/brands" element={<Guarded><BrandsPage /></Guarded>} />
          <Route path="/brands/:slug" element={<Guarded><BrandDetailPage /></Guarded>} />
          <Route path="/brands/:slug/ads" element={<Guarded><BrandAdsPage /></Guarded>} />
          <Route path="/brands/:slug/products" element={<Guarded><BrandProductsPage /></Guarded>} />
          <Route path="/brands/:slug/audit" element={<Guarded><BrandAuditPage /></Guarded>} />
          {/* GO-2.2 — budget planner (a plan document; Helm never writes to ad platforms). */}
          <Route path="/brands/:slug/planning" element={<Guarded><BrandPlanningPage /></Guarded>} />
          {/* Ask-the-data chat (D-016 LLM layer, admin/manager only server-side) */}
          <Route path="/brands/:slug/ask" element={<Guarded><BrandAskPage /></Guarded>} />

          {/* Inventory Intelligence — per-brand stock × Meta spend (Phase 2). Top-level
              hub with an in-page brand switcher, so it's not under /brands/:slug. */}
          <Route path="/inventory" element={<Guarded><InventoryPage /></Guarded>} />

          {/* Ads hub — per-brand ad-platform Overview (Meta today). Top-level hub
              with an in-page brand switcher; /brands/:slug/ads deep-links a brand. */}
          <Route path="/ads" element={<Guarded><AdsPage /></Guarded>} />
          <Route path="/products" element={<Guarded><ProductsPage /></Guarded>} />
          <Route path="/store-audit" element={<Guarded><StoreAuditPage /></Guarded>} />
          <Route path="/ads-library" element={<Guarded><AdsLibraryPage /></Guarded>} />

          {/* Reporting & Creative Intelligence (Phase 2, slice 2.0) */}
          <Route path="/reports" element={<Guarded><ReportsPage /></Guarded>} />
          {/* REV2 (monthly-report-v2-mom.md) — mom is section-streamed (M0),
              so it gets its own document component/route rather than
              ReportViewPage's monolithic useReport() fetch. React Router v6
              ranks this literal 'mom' segment above the ':type' route below
              regardless of declaration order, so both coexist safely. */}
          <Route path="/brands/:slug/reports/mom" element={<Guarded><MomReportPage /></Guarded>} />
          <Route path="/brands/:slug/reports/:type" element={<Guarded><ReportViewPage /></Guarded>} />

          {/* /add-brand legacy URLs redirect to dashboard — the drawer is the
              only entry point now. */}
          <Route path="/add-brand" element={<Navigate to="/dashboard" replace />} />
          <Route path="/add-brand/connect" element={<Navigate to="/dashboard" replace />} />
          <Route path="/add-brand/sync" element={<Navigate to="/dashboard" replace />} />

          <Route path="/settings" element={<Guarded><SettingsPage /></Guarded>} />
          <Route path="/profile" element={<Guarded><ProfilePage /></Guarded>} />

          {/* Phase 1.5 */}
          <Route path="/team" element={<Guarded><TeamPage /></Guarded>} />
          <Route path="/team/invite" element={<Navigate to="/team" replace />} />
          <Route path="/team/users/:slug" element={<Guarded><UserDetailPage /></Guarded>} />
          <Route path="/audit-log" element={<Guarded><AuditLogPage /></Guarded>} />

          {/* Phase 3 */}
          <Route path="/tickets" element={<Guarded><TicketsPage /></Guarded>} />
          <Route path="/tickets/new" element={<Navigate to="/tickets" replace />} />
          <Route path="/tickets/:id" element={<Guarded><TicketDetailPage /></Guarded>} />

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
