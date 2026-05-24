import { Banner, Button, Drawer } from '@/components/ui';

interface NewTicketDrawerProps {
  open: boolean;
  onClose: () => void;
}

/**
 * Phase 3 placeholder drawer. The ticketing tables (tickets, ticket_comments,
 * ticket_attachments per spec §7.4) don't exist yet, so submitting from here
 * would have nowhere to go. Drawer keeps the click-path consistent with the
 * other entity-create surfaces while messaging the actual state honestly.
 */
export function NewTicketDrawer({ open, onClose }: NewTicketDrawerProps) {
  return (
    <Drawer
      open={open}
      onClose={onClose}
      size="lg"
      title="Raise a ticket"
      footer={
        <>
          <span className="text-xs muted">Phase 3 · backend not yet wired</span>
          <Button size="sm" variant="primary" onClick={onClose}>
            Close
          </Button>
        </>
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
        Ticket creation is a <strong>Phase 3</strong> feature. The form fields, attachments, and
        triage flow are designed but not wired to a database yet.
      </Banner>

      <div className="mt-24">
        <h3 className="section-title">What lands in Phase 3</h3>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
          <Step
            n={1}
            title="Title, description, category"
            body="Brand users describe the issue. Categories: bug, change, question, urgent."
          />
          <Step
            n={2}
            title="Attachments"
            body="Screenshots and exports up to 10 MB each, stored on S3."
          />
          <Step
            n={3}
            title="Triage routing"
            body="Tickets land in the internal team inbox sorted by priority + age. Internal-only notes hidden from the brand user."
          />
          <Step
            n={4}
            title="Two-way external sync"
            body="Tickets moved to in_progress mirror to ClickUp / Linear / Asana — and status changes sync back."
          />
        </div>
      </div>
    </Drawer>
  );
}

function Step({ n, title, body }: { n: number; title: string; body: string }) {
  return (
    <div className="flex items-start gap-12" style={{ padding: '12px 0', borderBottom: '1px solid var(--border)' }}>
      <span
        style={{
          display: 'inline-flex',
          alignItems: 'center',
          justifyContent: 'center',
          width: 22,
          height: 22,
          borderRadius: '50%',
          background: 'var(--accent)',
          color: 'var(--accent-fg)',
          fontSize: 12,
          fontWeight: 500,
          flexShrink: 0,
        }}
      >
        {n}
      </span>
      <div>
        <div style={{ fontWeight: 500, marginBottom: 4 }}>{title}</div>
        <div className="text-sm muted" style={{ lineHeight: 1.55 }}>
          {body}
        </div>
      </div>
    </div>
  );
}
