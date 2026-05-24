import { AppLayout } from '@/components/shell/AppLayout';
import { Banner, Breadcrumb, PageEmptyState } from '@/components/ui';

/**
 * New ticket — Phase 3. Route renders the empty state until the backend
 * ticket table ships. No mock form so we never accept input that goes nowhere.
 */
export function TicketNewPage() {
  return (
    <AppLayout title="New ticket">
      <Breadcrumb crumbs={[{ label: 'Tickets', to: '/tickets' }, { label: 'New' }]} />

      <Banner
        variant="info"
        icon={
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75">
            <circle cx="12" cy="12" r="10" />
            <line x1="12" y1="16" x2="12" y2="12" />
            <line x1="12" y1="8" x2="12.01" y2="8" />
          </svg>
        }
      >
        Ticket creation is a <strong>Phase 3</strong> feature. The form fields, attachments, and
        triage flow are designed but not wired to a database yet.
      </Banner>

      <PageEmptyState
        icon={
          <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75">
            <path d="M12 5v14M5 12h14" />
          </svg>
        }
        title="Ticket creation isn't live yet"
        body="When Phase 3 ships, this is where brand users will raise issues — bug reports, change requests, questions — with optional attachments. Your team will triage them from /tickets and sync each one to ClickUp / Linear / Asana."
      />
    </AppLayout>
  );
}
