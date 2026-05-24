import { AppLayout } from '@/components/shell/AppLayout';
import { Banner, Button, PageEmptyState } from '@/components/ui';
import { useUiStore } from '@/stores/uiStore';

/**
 * Tickets — Phase 3 surface. The backend ticket tables (tickets, ticket_comments,
 * ticket_attachments per spec §7.4) don't ship until Phase 3a. The empty state
 * messages this honestly; the "New ticket" CTA opens a drawer that also makes
 * the Phase-3 status explicit instead of pretending to submit nowhere.
 */
export function TicketsPage() {
  const openNewTicket = useUiStore((s) => s.setNewTicketDrawerOpen);

  return (
    <AppLayout
      title="Tickets"
      topbarActions={
        <Button
          size="sm"
          variant="primary"
          onClick={() => openNewTicket(true)}
          leftIcon={
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <path d="M12 5v14M5 12h14" />
            </svg>
          }
        >
          New ticket
        </Button>
      }
    >
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
        Ticketing is a <strong>Phase 3</strong> feature. The UI is ready; the backend tables
        (tickets, ticket_comments, ticket_attachments) ship in Phase 3a, then Phase 3b adds
        two-way sync to ClickUp / Linear / Asana.
      </Banner>

      <PageEmptyState
        icon={
          <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75">
            <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z" />
          </svg>
        }
        title="No tickets yet"
        body="When brand users start raising tickets, they show up here. Your internal team triages, adds internal-only notes, and resolves them — with the option to sync each ticket to your task tool of choice."
        primary={
          <button onClick={() => openNewTicket(true)} className="btn btn-primary btn-lg">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <path d="M12 5v14M5 12h14" />
            </svg>
            Preview new ticket
          </button>
        }
        steps={[
          {
            n: 1,
            title: 'Brand-side ticketing',
            body: 'Brand users can raise tickets with title, description, category, and attachments.',
          },
          {
            n: 2,
            title: 'Internal triage',
            body: 'Your team sees the inbox sorted by priority and age, with internal-only notes hidden from brand users.',
          },
          {
            n: 3,
            title: 'External task sync',
            body: 'Tickets moved to in_progress create a corresponding task in ClickUp, Linear, or Asana — and status changes sync back.',
          },
        ]}
      />
    </AppLayout>
  );
}
