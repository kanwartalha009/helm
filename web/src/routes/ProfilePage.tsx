import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { AppLayout } from '@/components/shell/AppLayout';
import { Avatar, Banner, Button, Card, Dot, Modal, Input, PageHeader, Tag } from '@/components/ui';
import { useAuditLogs } from '@/hooks/useApiData';
import { useCurrentUser } from '@/hooks/useSettings';
import { mfaDisable } from '@/lib/auth';
import { toast } from '@/stores/toastStore';

const ROLE_LABEL: Record<string, string> = {
  master_admin: 'Master admin',
  manager: 'Manager',
  team_member: 'Team member',
  brand_user: 'Brand user',
};

export function ProfilePage() {
  const { data: user, isLoading, isError, error } = useCurrentUser();
  // The audit-log endpoint is gated to master_admin|manager, so for brand
  // users the request fails — swallow the error and treat that as "empty".
  const { data: auditPage } = useAuditLogs();
  const auditLog = auditPage?.data ?? [];

  if (isLoading) {
    return (
      <AppLayout title="Profile">
        <div className="muted">Loading…</div>
      </AppLayout>
    );
  }

  if (isError || !user) {
    return (
      <AppLayout title="Profile">
        <Banner
          variant="warning"
          icon={
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75">
              <circle cx="12" cy="12" r="10" />
              <line x1="12" y1="8" x2="12" y2="12" />
              <line x1="12" y1="16" x2="12.01" y2="16" />
            </svg>
          }
        >
          Couldn&rsquo;t load your profile: {(error as Error)?.message ?? 'unknown'}.
        </Banner>
      </AppLayout>
    );
  }

  // Filter the workspace-wide audit log to just this user's actions.
  // Optional chain because system events have null actor.
  const myActivity = auditLog.filter((entry) => entry.actor?.id === user.id).slice(0, 8);

  return (
    <AppLayout title="Profile">
      <PageHeader
        leading={
          user.avatarUrl ? (
            <img
              src={user.avatarUrl}
              alt=""
              style={{
                width: 56,
                height: 56,
                borderRadius: '50%',
                objectFit: 'cover',
                border: '1px solid var(--border)',
              }}
            />
          ) : (
            <Avatar
              initials={user.displayInitials}
              size={56}
              round
              inverted
              style={{ fontSize: 20 }}
            />
          )
        }
        title={user.name}
        subtitle={`${ROLE_LABEL[user.role] ?? user.role} · ${user.email}`}
      />

      <div style={{ maxWidth: 640 }}>
        <h3 className="section-title">Profile</h3>
        <Card style={{ padding: 24 }}>
          <div className="form-grid" style={{ gap: 14 }}>
            <Field label="Name" value={user.name} />
            <Field label="Email" value={user.email} />
            <Field label="Role" value={ROLE_LABEL[user.role] ?? user.role} />
            <Field label="Timezone" value={user.timezone} />
            <Field label="Status" value={statusTag(user.status)} />
          </div>
          <div className="flex items-center gap-8 mt-24">
            <Link to="/settings" className="btn btn-primary btn-sm">
              Edit profile
            </Link>
            <Link to="/settings" className="btn btn-secondary btn-sm">
              Change password
            </Link>
          </div>
        </Card>

        <h3 className="section-title mt-32">Sign-in security</h3>
        <Card style={{ overflow: 'hidden' }}>
          <div className="list-row">
            <div className="list-row-main">
              <div className="list-row-title">Two-factor authentication</div>
              <div
                className="list-row-sub"
                style={{ color: user.mfaEnabled ? 'var(--success)' : 'var(--warning)' }}
              >
                {user.mfaEnabled
                  ? 'Enabled via authenticator app'
                  : user.role === 'master_admin'
                  ? 'Not enabled · Required for master admin'
                  : 'Not enabled'}
              </div>
            </div>
            {user.mfaEnabled ? (
              <DisableMfaButton />
            ) : (
              <Link to="/mfa/setup" className="btn btn-primary btn-sm">
                Set up
              </Link>
            )}
          </div>
          <div className="list-row">
            <div className="list-row-main">
              <div className="list-row-title">Last sign-in</div>
              <div className="list-row-sub">
                {user.lastLoginAt
                  ? new Date(user.lastLoginAt).toLocaleString()
                  : 'Never'}
              </div>
            </div>
            {/* "Sign out elsewhere" requires a backend session-revoke endpoint
                that isn't wired yet. Removed rather than left as a toast-only
                placeholder so users don't think they actually revoked anything. */}
          </div>
        </Card>

        <h3 className="section-title mt-32">Recent activity</h3>
        <Card style={{ overflow: 'hidden' }}>
          {myActivity.length === 0 ? (
            <div className="empty-state" style={{ padding: '32px 16px' }}>
              <p>No activity yet.</p>
            </div>
          ) : (
            <table className="log-table">
              <thead>
                <tr>
                  <th>Event</th>
                  <th>Target</th>
                  <th>IP</th>
                  <th>When</th>
                </tr>
              </thead>
              <tbody>
                {myActivity.map((entry) => (
                  <tr key={entry.id}>
                    <td className="mono">{entry.action}</td>
                    <td>{entry.target}</td>
                    <td className="mono">{entry.ip}</td>
                    <td className="muted">{entry.createdAt}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
          <div style={{ padding: '12px 16px', textAlign: 'center' }}>
            <Link to="/audit-log" className="text-sm">
              See full audit log →
            </Link>
          </div>
        </Card>
      </div>
    </AppLayout>
  );
}

function Field({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div
      className="flex items-center"
      style={{ borderBottom: '1px solid var(--border)', paddingBottom: 10 }}
    >
      <div style={{ width: 140, fontSize: 13, color: 'var(--text-muted)' }}>{label}</div>
      <div style={{ flex: 1, fontSize: 14, color: 'var(--text)' }}>{value}</div>
    </div>
  );
}

/**
 * "Disable MFA" button + password-confirm modal. Kept local to ProfilePage
 * because it's the only place that disables — we don't expose it on
 * UserDetailPage since one user shouldn't disable another user's MFA
 * (that's a separate admin-flow).
 */
function DisableMfaButton() {
  const qc = useQueryClient();
  const [open, setOpen] = useState(false);
  const [password, setPassword] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const onConfirm = async () => {
    setError(null);
    setSubmitting(true);
    try {
      await mfaDisable(password);
      qc.invalidateQueries({ queryKey: ['auth', 'me'] });
      toast.success('MFA disabled', 'Your next sign-in won’t ask for a code.');
      setOpen(false);
      setPassword('');
    } catch (err: any) {
      setError(err?.response?.data?.message ?? err.message ?? 'Disable failed.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <>
      <Button size="sm" variant="ghost" style={{ color: 'var(--danger)' }} onClick={() => setOpen(true)}>
        Disable
      </Button>
      {open && (
        <Modal
          open
          onClose={() => { setOpen(false); setPassword(''); setError(null); }}
          title="Disable two-factor auth?"
          footer={
            <>
              <Button size="sm" variant="ghost" onClick={() => setOpen(false)} disabled={submitting}>
                Cancel
              </Button>
              <Button
                size="sm"
                variant="primary"
                style={{ background: 'var(--danger)', borderColor: 'var(--danger)' }}
                onClick={onConfirm}
                disabled={!password || submitting}
              >
                {submitting ? 'Disabling…' : 'Disable MFA'}
              </Button>
            </>
          }
        >
          <Banner variant="warning" className="mb-16">
            Disabling MFA weakens your account security. You’ll need to re-enroll if you change your mind.
          </Banner>
          {error && <Banner variant="warning" className="mb-16">{error}</Banner>}
          <Input
            label="Confirm with your password"
            type="password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            autoFocus
            required
          />
        </Modal>
      )}
    </>
  );
}

function statusTag(status: string) {
  if (status === 'active')
    return (
      <Tag variant="success">
        <Dot variant="success" />
        Active
      </Tag>
    );
  if (status === 'invited')
    return (
      <Tag variant="warning">
        <Dot variant="warning" />
        Invited
      </Tag>
    );
  return (
    <Tag>
      <Dot variant="muted" />
      Disabled
    </Tag>
  );
}
