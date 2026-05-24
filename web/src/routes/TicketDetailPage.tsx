import { useParams } from 'react-router-dom';
import { AppLayout } from '@/components/shell/AppLayout';
import { Banner, Breadcrumb, PageEmptyState } from '@/components/ui';

/**
 * Ticket detail — Phase 3. No mocked thread, no fake comments. Empty state
 * until the backend tables ship.
 */
export function TicketDetailPage() {
  const { id } = useParams();

  return (
    <AppLayout title={`Ticket #${id ?? ''}`}>
      <Breadcrumb crumbs={[{ label: 'Tickets', to: '/tickets' }, { label: `#${id ?? ''}` }]} />

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
        Individual ticket views ship in <strong>Phase 3a</strong> — comment threads, internal-only
        notes, status transitions, and the assignment dropdown all live here once tickets are real.
      </Banner>

      <PageEmptyState
        icon={
          <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75">
            <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z" />
          </svg>
        }
        title={`No ticket #${id ?? '—'} yet`}
        body="When Phase 3 ships, a ticket detail page renders the full thread: original message, public replies, internal notes hidden from the brand user, status timeline, assignee, and the external task URL if it's linked to ClickUp / Linear / Asana."
      />
    </AppLayout>
  );
}
