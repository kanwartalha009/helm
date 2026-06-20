import { useState } from 'react';
import { Banner, Button, Modal } from '@/components/ui';
import { useUiStore } from '@/stores/uiStore';

/**
 * Surfaces the freshly minted invitation accept URL after a successful invite.
 * Stays mounted until the admin dismisses it — necessary because SMTP isn't
 * wired yet and the admin needs to copy the link manually to deliver it.
 */
export function InvitationAcceptUrlModal() {
  const state = useUiStore((s) => s.invitationAcceptUrl);
  const close = useUiStore((s) => s.setInvitationAcceptUrl);
  const [copied, setCopied] = useState(false);

  if (!state) return null;

  const onCopy = async () => {
    try {
      await navigator.clipboard.writeText(state.acceptUrl);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      // Older browsers without async clipboard — fall back to selecting the input.
      const el = document.getElementById('invite-accept-url-field') as HTMLInputElement | null;
      el?.select();
      document.execCommand('copy');
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    }
  };

  return (
    <Modal
      open
      onClose={() => close(null)}
      title="Send this invitation link"
      footer={
        <>
          <Button size="sm" variant="ghost" onClick={() => close(null)}>
            Close
          </Button>
          <Button size="sm" variant="primary" onClick={onCopy}>
            {copied ? 'Copied!' : 'Copy link'}
          </Button>
        </>
      }
    >
      <Banner variant="info" className="mb-16">
        SMTP isn’t connected yet, so Roasdriven doesn’t auto-email invitees. Copy the link below and
        send it to <strong>{state.email}</strong> however you like (DM, manual email, etc.).
        The link expires in 7 days.
      </Banner>

      <label className="field-label">Accept link</label>
      <input
        id="invite-accept-url-field"
        className="input mono"
        readOnly
        value={state.acceptUrl}
        onFocus={(e) => e.currentTarget.select()}
        style={{ fontSize: 13 }}
      />
      <p className="text-xs muted mt-12">
        Anyone with this link can create an account at <strong>{state.email}</strong> and join
        the workspace with the role you picked. Don’t share it publicly.
      </p>
    </Modal>
  );
}
