import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { Banner, Wordmark } from '@/components/ui';

interface Entry {
  title: ReactNode;
  sub: ReactNode;
  to: string;
}

function Section({ title, items, className }: { title: string; items: Entry[]; className?: string }) {
  return (
    <>
      <h3 className="section-title">{title}</h3>
      <div className={`card ${className ?? 'mb-24'}`} style={{ overflow: 'hidden' }}>
        {items.map((it, i) => (
          <Link
            key={i}
            to={it.to}
            className="list-row"
            style={{ textDecoration: 'none', color: 'inherit' }}
          >
            <div className="list-row-main">
              <div className="list-row-title">{it.title}</div>
              <div className="list-row-sub">{it.sub}</div>
            </div>
            <span className="text-xs muted">→</span>
          </Link>
        ))}
      </div>
    </>
  );
}

export function SitemapPage() {
  return (
    <div className="container" style={{ padding: '48px 24px', maxWidth: 920 }}>
      <div className="page-header">
        <div>
          <div style={{ marginBottom: 12 }}>
            <Wordmark to="/" />
          </div>
          <h2 className="page-title" style={{ marginTop: 16 }}>Design preview — site map</h2>
          <p className="page-subtitle">Every page in the current click-through. Built as static HTML before the React + Vite scaffold.</p>
        </div>
      </div>

      <Banner
        variant="info"
        icon={
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75">
            <circle cx="12" cy="12" r="10" />
            <line x1="12" y1="8" x2="12" y2="12" />
            <line x1="12" y1="16" x2="12.01" y2="16" />
          </svg>
        }
      >
        Static HTML only. No real data, no real auth, no API yet. The visual language and click-paths are the deliverable here.
      </Banner>

      <div style={{ marginTop: 24 }}>
        <Section
          title="Marketing"
          items={[{ title: 'Landing', sub: '/ · product surface, sign-in CTA', to: '/' }]}
        />

        <Section
          title="Auth"
          items={[
            { title: 'Sign in', sub: '/login', to: '/login' },
            { title: 'Accept invitation', sub: '/accept-invite · token-based password creation', to: '/accept-invite' },
            { title: 'Reset password (request)', sub: '/forgot-password', to: '/forgot-password' },
            { title: 'Reset password (set)', sub: '/reset-password', to: '/reset-password' },
            { title: 'Two-factor setup', sub: '/mfa-setup · QR code + TOTP', to: '/mfa-setup' },
            { title: 'Two-factor verify', sub: '/mfa-verify · sign-in challenge', to: '/mfa-verify' },
          ]}
        />

        <Section
          title="Phase 1 — Dashboard"
          items={[
            { title: 'Dashboard', sub: '/dashboard · brands table with deltas, sync warnings', to: '/dashboard' },
            { title: 'Sync health', sub: '/sync-health · failed, recent, all syncs', to: '/sync-health' },
            { title: 'Brands', sub: '/brands · list view of every store', to: '/brands' },
            { title: 'Brand detail', sub: '/brands/:slug · overview, connections, sync log, settings tabs', to: '/brands/meller' },
            { title: 'Add brand — step 1: details', sub: '/add-brand', to: '/add-brand' },
            { title: 'Add brand — step 2: connect platforms', sub: '/add-brand/connect', to: '/add-brand/connect' },
            { title: 'Add brand — step 3: initial sync', sub: '/add-brand/sync', to: '/add-brand/sync' },
            { title: 'Settings', sub: '/settings · workspace, account, MFA, API tokens, notifications, danger', to: '/settings' },
            { title: 'Profile', sub: '/profile', to: '/profile' },
          ]}
        />

        <Section
          title="Phase 1.5 — RBAC"
          items={[
            { title: 'Team', sub: '/team · active, invited, disabled tabs', to: '/team' },
            { title: 'Invite user', sub: '/team/invite · role, brand scope, personal note', to: '/team/invite' },
            { title: 'User detail', sub: '/team/users/:slug · role, brand access, security, activity', to: '/team/users/jordan' },
            { title: 'Audit log', sub: '/audit-log · append-only event ledger', to: '/audit-log' },
          ]}
        />

        <Section
          title="Phase 2 — Deep analytics (per brand)"
          items={[
            { title: 'Ad performance', sub: '/brands/:slug/ads · campaign / ad set / ad with scale and underperformer flags', to: '/brands/meller/ads' },
            { title: 'Product performance', sub: '/brands/:slug/products · per-SKU revenue and refund rate', to: '/brands/meller/products' },
            { title: 'Store audit', sub: '/brands/:slug/audit · page speed, broken events, checkout drop', to: '/brands/meller/audit' },
          ]}
        />

        <Section
          title="Phase 3 — Ticketing"
          items={[
            { title: 'Tickets', sub: '/tickets · triage inbox', to: '/tickets' },
            { title: 'New ticket', sub: '/tickets/new', to: '/tickets/new' },
            { title: 'Ticket detail', sub: '/tickets/:id · public + internal threads', to: '/tickets/1148' },
          ]}
        />

        <Section
          title="Errors"
          className="mb-48"
          items={[{ title: '404 — not found', sub: '/404', to: '/404' }]}
        />

        <div className="text-xs muted text-center" style={{ marginBottom: 48 }}>
          Total pages: 26 · Modals: 8 · Last built 2026-05-16
        </div>
      </div>
    </div>
  );
}
