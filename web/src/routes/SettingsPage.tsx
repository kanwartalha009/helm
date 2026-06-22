import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { AppLayout } from '@/components/shell/AppLayout';
import { Banner, Button, ErrorBoundary, Modal, PageHeader, Tabs } from '@/components/ui';
import { PlatformKeysSection } from '@/components/settings/PlatformKeysSection';
import {
  useChangePassword,
  useCurrentUser,
  useUpdateNotificationPrefs,
  useUpdateProfile,
  useUpdateWorkspaceSettings,
  useWorkspaceSettings,
} from '@/hooks/useSettings';
import { logout } from '@/lib/auth';
import { toast } from '@/stores/toastStore';
import { DEFAULT_BRANDING } from '@/types/reports';
import type { ReportBranding } from '@/types/reports';

export function SettingsPage() {
  return (
    <AppLayout title="Settings">
      <PageHeader title="Settings" subtitle="Workspace-wide configuration." />

      <Tabs
        tabs={[
          { id: 'general',        label: 'General',        content: <ErrorBoundary><GeneralTab /></ErrorBoundary> },
          { id: 'reports',        label: 'Reports',        content: <ErrorBoundary><ReportBrandingTab /></ErrorBoundary> },
          { id: 'account',        label: 'Account',        content: <ErrorBoundary><AccountTab /></ErrorBoundary> },
          { id: 'mfa',            label: 'Two-factor',     content: <ErrorBoundary><MfaTab /></ErrorBoundary> },
          { id: 'platform-keys',  label: 'Platform keys',  content: <ErrorBoundary><PlatformKeysSection /></ErrorBoundary> },
          { id: 'notifications',  label: 'Notifications',  content: <ErrorBoundary><NotificationsTab /></ErrorBoundary> },
          { id: 'danger',         label: 'Danger',         content: <ErrorBoundary><DangerTab /></ErrorBoundary> },
        ]}
      />
    </AppLayout>
  );
}

/* ---- General ---------------------------------------------------------- */

function GeneralTab() {
  const { data: settings, isLoading, isError, error } = useWorkspaceSettings();
  const updateMutation = useUpdateWorkspaceSettings();
  // Phase 1: no primary_currency on the workspace — every brand is rendered
  // in its own native currency on the dashboard.
  const [form, setForm] = useState({ workspace_name: '' });
  const [savedAt, setSavedAt] = useState<Date | null>(null);

  useEffect(() => {
    if (settings) {
      setForm({
        workspace_name: settings.workspace_name,
      });
    }
  }, [settings]);

  if (isLoading) return <div style={{ maxWidth: 640 }} className="muted">Loading…</div>;
  if (isError) return <ErrorBanner error={error} />;

  const dirty =
    settings &&
    (form.workspace_name !== settings.workspace_name);

  const handleSave = (e: React.FormEvent) => {
    e.preventDefault();
    updateMutation.mutate(form, {
      onSuccess: () => setSavedAt(new Date()),
    });
  };

  return (
    <div style={{ maxWidth: 640 }}>
      <h3 className="section-title">Workspace</h3>
      <form
        className="card"
        style={{ padding: 24 }}
        onSubmit={handleSave}
      >
        <div className="form-grid">
          <div className="field">
            <label className="field-label">Workspace name</label>
            <input
              className="input"
              type="text"
              value={form.workspace_name}
              onChange={(e) => setForm((f) => ({ ...f, workspace_name: e.target.value }))}
            />
          </div>
          <div className="field">
            <label className="field-label">Daily sync time</label>
            <input
              className="input"
              type="text"
              value={`${settings?.daily_sync_time ?? '13:00'} UTC`}
              disabled
              style={{ background: 'var(--surface-subtle)', color: 'var(--text-secondary)' }}
            />
            <span className="field-hint">Hardcoded to 13:00 UTC. Yesterday closes in every timezone by then.</span>
          </div>
        </div>
        <div className="flex items-center gap-8 mt-24">
          <Button
            type="submit"
            size="sm"
            variant="primary"
            disabled={!dirty || updateMutation.isPending}
          >
            {updateMutation.isPending ? 'Saving…' : 'Save changes'}
          </Button>
          {savedAt && !dirty && (
            <span
              className="text-xs"
              style={{
                color: 'var(--success)',
                display: 'inline-flex',
                alignItems: 'center',
                gap: 4,
              }}
            >
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
                <polyline points="20 6 9 17 4 12" />
              </svg>
              Saved {timeSince(savedAt)}
            </span>
          )}
        </div>
      </form>
    </div>
  );
}

function timeSince(date: Date): string {
  const secs = Math.floor((Date.now() - date.getTime()) / 1000);
  if (secs < 5) return 'just now';
  if (secs < 60) return `${secs}s ago`;
  const mins = Math.floor(secs / 60);
  if (mins < 60) return `${mins}m ago`;
  return date.toLocaleTimeString();
}

/* ---- Reports (white-label theme) ------------------------------------- */

function ReportBrandingTab() {
  const { data: settings, isLoading, isError, error } = useWorkspaceSettings();
  const updateMutation = useUpdateWorkspaceSettings();
  const [form, setForm] = useState<ReportBranding>(DEFAULT_BRANDING);
  const [savedAt, setSavedAt] = useState<Date | null>(null);

  useEffect(() => {
    if (settings?.report_branding) setForm({ ...DEFAULT_BRANDING, ...settings.report_branding });
  }, [settings]);

  if (isLoading) return <div style={{ maxWidth: 640 }} className="muted">Loading…</div>;
  if (isError) return <ErrorBanner error={error} />;

  const saved = settings?.report_branding ?? DEFAULT_BRANDING;
  const dirty =
    form.agency_name !== saved.agency_name ||
    form.accent !== saved.accent ||
    form.footer_text !== saved.footer_text;

  const handleSave = (e: React.FormEvent) => {
    e.preventDefault();
    updateMutation.mutate({ report_branding: form }, { onSuccess: () => setSavedAt(new Date()) });
  };

  return (
    <div style={{ maxWidth: 640 }}>
      <h3 className="section-title">Report branding</h3>
      <p className="text-sm muted" style={{ marginBottom: 16 }}>
        Applied to every white-label client report. The brand name sits on top; your agency name and accent run throughout; the footer carries your attribution.
      </p>
      <form className="card" style={{ padding: 24 }} onSubmit={handleSave}>
        <div className="form-grid">
          <div className="field">
            <label className="field-label">Agency name</label>
            <input
              className="input"
              type="text"
              value={form.agency_name}
              onChange={(e) => setForm((f) => ({ ...f, agency_name: e.target.value }))}
            />
            <span className="field-hint">Shown in the report footer.</span>
          </div>
          <div className="field">
            <label className="field-label">Accent colour</label>
            <div className="flex items-center gap-8">
              <input
                type="color"
                value={form.accent}
                onChange={(e) => setForm((f) => ({ ...f, accent: e.target.value }))}
                style={{ width: 44, height: 36, border: '1px solid var(--border)', borderRadius: 8, padding: 2, background: 'none', cursor: 'pointer' }}
              />
              <input
                className="input"
                type="text"
                value={form.accent}
                onChange={(e) => setForm((f) => ({ ...f, accent: e.target.value }))}
                style={{ maxWidth: 140 }}
              />
            </div>
          </div>
          <div className="field">
            <label className="field-label">Footer text</label>
            <input
              className="input"
              type="text"
              value={form.footer_text}
              onChange={(e) => setForm((f) => ({ ...f, footer_text: e.target.value }))}
            />
          </div>
        </div>

        <div style={{ marginTop: 20, border: '1px solid var(--border)', borderRadius: 10, padding: 16, background: 'var(--surface, #fff)' }}>
          <div style={{ fontSize: 10, letterSpacing: '.14em', textTransform: 'uppercase', color: form.accent, fontWeight: 600 }}>
            Overall performance report
          </div>
          <div style={{ fontFamily: 'Georgia, serif', fontSize: 28, fontWeight: 600, marginTop: 4 }}>Brand name</div>
          <div style={{ borderTop: '1px solid var(--border)', marginTop: 14, paddingTop: 10, fontSize: 12, color: 'var(--text-muted)' }}>
            <strong style={{ fontFamily: 'Georgia, serif', color: 'var(--text)' }}>{form.agency_name}</strong> · {form.footer_text}
          </div>
        </div>

        <div className="flex items-center gap-8 mt-24">
          <Button type="submit" size="sm" variant="primary" disabled={!dirty || updateMutation.isPending}>
            {updateMutation.isPending ? 'Saving…' : 'Save changes'}
          </Button>
          {savedAt && !dirty && (
            <span className="text-xs" style={{ color: 'var(--success)' }}>Saved {timeSince(savedAt)}</span>
          )}
        </div>
      </form>
    </div>
  );
}

/* ---- Account ---------------------------------------------------------- */

function AccountTab() {
  const { data: user, isLoading, isError, error } = useCurrentUser();
  const updateMutation = useUpdateProfile();
  const [form, setForm] = useState({ name: '', email: '', timezone: 'UTC' });

  useEffect(() => {
    if (user) {
      setForm({ name: user.name, email: user.email, timezone: user.timezone });
    }
  }, [user]);

  if (isLoading) return <div style={{ maxWidth: 640 }} className="muted">Loading…</div>;
  if (isError || !user) return <ErrorBanner error={error} />;

  const dirty = form.name !== user.name || form.email !== user.email || form.timezone !== user.timezone;

  return (
    <div style={{ maxWidth: 640 }}>
      <h3 className="section-title">Profile</h3>
      <form
        className="card"
        style={{ padding: 24 }}
        onSubmit={(e) => {
          e.preventDefault();
          updateMutation.mutate(form);
        }}
      >
        <div className="form-grid">
          <div className="form-grid form-grid-2">
            <div className="field">
              <label className="field-label">Name</label>
              <input
                className="input"
                type="text"
                value={form.name}
                onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
              />
            </div>
            <div className="field">
              <label className="field-label">Email</label>
              <input
                className="input"
                type="email"
                value={form.email}
                onChange={(e) => setForm((f) => ({ ...f, email: e.target.value }))}
              />
            </div>
          </div>
          <div className="field">
            <label className="field-label">Timezone</label>
            <select
              className="input"
              value={form.timezone}
              onChange={(e) => setForm((f) => ({ ...f, timezone: e.target.value }))}
            >
              {['UTC', 'Europe/Madrid', 'Europe/Berlin', 'America/New_York', 'America/Los_Angeles', 'Asia/Dubai', 'Asia/Riyadh'].map((tz) => (
                <option key={tz}>{tz}</option>
              ))}
            </select>
            <span className="field-hint">
              Used for displaying timestamps in the UI. Brand metric dates always use the brand&rsquo;s timezone.
            </span>
          </div>
        </div>
        <div className="flex items-center gap-8 mt-24">
          <Button
            type="submit"
            size="sm"
            variant="primary"
            disabled={!dirty || updateMutation.isPending}
          >
            {updateMutation.isPending ? 'Saving…' : 'Save changes'}
          </Button>
        </div>
      </form>

      <h3 className="section-title mt-32">Password</h3>
      <PasswordRow />
    </div>
  );
}

function PasswordRow() {
  const [open, setOpen] = useState(false);
  return (
    <>
      <div
        className="card"
        style={{
          padding: '16px 22px',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'space-between',
        }}
      >
        <div>
          <div style={{ fontWeight: 500 }}>Password</div>
          <div className="text-xs muted mt-4">
            Updating it will sign out your other devices. This session stays active.
          </div>
        </div>
        <Button size="sm" variant="secondary" onClick={() => setOpen(true)}>
          Change password
        </Button>
      </div>
      <ChangePasswordModal open={open} onClose={() => setOpen(false)} />
    </>
  );
}

function ChangePasswordModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const mutation = useChangePassword();
  const navigate = useNavigate();
  const [form, setForm] = useState({
    current_password: '',
    new_password: '',
    new_password_confirmation: '',
  });
  const [show, setShow] = useState({ current: false, next: false, confirm: false });
  const [touched, setTouched] = useState(false);

  // Reset state on close so re-opening starts fresh.
  useEffect(() => {
    if (!open) {
      setForm({ current_password: '', new_password: '', new_password_confirmation: '' });
      setShow({ current: false, next: false, confirm: false });
      setTouched(false);
    }
  }, [open]);

  const valid =
    form.current_password.length > 0 &&
    form.new_password.length >= 12 &&
    form.new_password === form.new_password_confirmation;

  const mismatch =
    touched &&
    form.new_password_confirmation.length > 0 &&
    form.new_password !== form.new_password_confirmation;

  const submit = async () => {
    if (!valid) {
      // Visible feedback when the user clicks Update without a valid form.
      if (form.current_password.length === 0) {
        toast.error('Current password is required');
      } else if (form.new_password.length < 12) {
        toast.error('New password must be at least 12 characters');
      } else if (form.new_password !== form.new_password_confirmation) {
        toast.error('New passwords don\'t match');
      }
      return;
    }
    try {
      await mutation.mutateAsync(form);
      // Password is now changed. Force a full re-login per the user's ask.
      onClose();
      toast.info('Password changed', 'Please sign in again with your new password.');
      await logout();
      navigate('/login', { replace: true });
    } catch {
      // toast already shown by useChangePassword's onError
    }
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Change password"
      footer={
        <>
          <Button size="sm" variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button
            size="sm"
            variant="primary"
            onClick={submit}
            disabled={mutation.isPending}
          >
            {mutation.isPending ? 'Updating…' : 'Update password'}
          </Button>
        </>
      }
    >
      <form onSubmit={(e) => { e.preventDefault(); submit(); }}>
        <div className="form-grid">
          <PasswordField
            label="Current password"
            value={form.current_password}
            onChange={(v) => setForm((f) => ({ ...f, current_password: v }))}
            show={show.current}
            onToggle={() => setShow((s) => ({ ...s, current: !s.current }))}
            autoComplete="current-password"
            autoFocus
          />
          <PasswordField
            label="New password"
            value={form.new_password}
            onChange={(v) => {
              setTouched(true);
              setForm((f) => ({ ...f, new_password: v }));
            }}
            show={show.next}
            onToggle={() => setShow((s) => ({ ...s, next: !s.next }))}
            autoComplete="new-password"
            hint="12 characters minimum. Mix letters, numbers, and a symbol."
          />
          <PasswordField
            label="Confirm new password"
            value={form.new_password_confirmation}
            onChange={(v) => {
              setTouched(true);
              setForm((f) => ({ ...f, new_password_confirmation: v }));
            }}
            show={show.confirm}
            onToggle={() => setShow((s) => ({ ...s, confirm: !s.confirm }))}
            autoComplete="new-password"
            error={mismatch ? "Passwords don't match." : undefined}
          />
        </div>
        <p className="text-xs muted mt-16">
          You&rsquo;ll be signed out on every device after saving — including this one.
        </p>
        <button type="submit" style={{ display: 'none' }} />
      </form>
    </Modal>
  );
}

/** Password input with show/hide eye toggle. */
function PasswordField({
  label,
  value,
  onChange,
  show,
  onToggle,
  autoComplete,
  autoFocus,
  hint,
  error,
}: {
  label: string;
  value: string;
  onChange: (v: string) => void;
  show: boolean;
  onToggle: () => void;
  autoComplete?: string;
  autoFocus?: boolean;
  hint?: string;
  error?: string;
}) {
  return (
    <div className="field">
      <label className="field-label">{label}</label>
      <div style={{ position: 'relative' }}>
        <input
          className="input"
          type={show ? 'text' : 'password'}
          autoComplete={autoComplete}
          autoFocus={autoFocus}
          value={value}
          onChange={(e) => onChange(e.target.value)}
          style={{ paddingRight: 38 }}
        />
        <button
          type="button"
          onClick={onToggle}
          aria-label={show ? 'Hide password' : 'Show password'}
          style={{
            position: 'absolute',
            right: 8,
            top: '50%',
            transform: 'translateY(-50%)',
            background: 'transparent',
            border: 0,
            cursor: 'pointer',
            color: 'var(--text-muted)',
            padding: 4,
            display: 'flex',
            alignItems: 'center',
          }}
        >
          {show ? (
            // Eye-off
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75">
              <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24" />
              <line x1="1" y1="1" x2="23" y2="23" />
            </svg>
          ) : (
            // Eye
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75">
              <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
              <circle cx="12" cy="12" r="3" />
            </svg>
          )}
        </button>
      </div>
      {error ? (
        <span className="field-hint" style={{ color: 'var(--danger)' }}>{error}</span>
      ) : hint ? (
        <span className="field-hint">{hint}</span>
      ) : null}
    </div>
  );
}

/* ---- Two-factor ------------------------------------------------------- */

function MfaTab() {
  const { data: user } = useCurrentUser();

  return (
    <div style={{ maxWidth: 640 }} id="mfa">
      <h3 className="section-title">Two-factor authentication</h3>
      <div className="card" style={{ padding: 24 }}>
        <div className="flex items-center justify-between mb-16">
          <div>
            <div style={{ fontWeight: 500 }}>Authenticator app</div>
            <div className="text-sm muted mt-4">
              Use Google Authenticator, Authy, 1Password, or Bitwarden.
            </div>
          </div>
          {user?.mfaEnabled ? (
            <span className="tag tag-success"><span className="dot dot-success" /> Enabled</span>
          ) : (
            <span className="tag tag-warning"><span className="dot dot-warning" /> Not enabled</span>
          )}
        </div>
        {!user?.mfaEnabled && user?.role === 'master_admin' && (
          <Banner
            variant="warning"
            icon={
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75">
                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
                <line x1="12" y1="9" x2="12" y2="13" />
              </svg>
            }
          >
            Master admin accounts must enable two-factor auth. You&rsquo;ll be required to do this on next sign-in.
          </Banner>
        )}
        <div className="mt-16">
          <a href="/mfa/setup" className="btn btn-primary btn-sm">
            {user?.mfaEnabled ? 'Reconfigure' : 'Set up two-factor auth'}
          </a>
        </div>
      </div>
    </div>
  );
}

/* ---- Notifications ---------------------------------------------------- */

function NotificationsTab() {
  const { data: user, isLoading, isError, error } = useCurrentUser();
  const updateMutation = useUpdateNotificationPrefs();
  const [prefs, setPrefs] = useState<Record<string, boolean>>({
    daily_sync_digest: true,
    connection_errored: true,
    ticket_assigned: false,
    weekly_summary: false,
  });

  useEffect(() => {
    if (user?.notificationPrefs) setPrefs(user.notificationPrefs);
  }, [user]);

  if (isLoading) return <div style={{ maxWidth: 640 }} className="muted">Loading…</div>;
  if (isError || !user) return <ErrorBanner error={error} />;

  const dirty = JSON.stringify(prefs) !== JSON.stringify(user.notificationPrefs);

  const toggle = (key: string) => setPrefs((p) => ({ ...p, [key]: !p[key] }));

  const options: { key: keyof typeof prefs; title: string; sub: string }[] = [
    { key: 'daily_sync_digest',  title: 'Daily sync digest',  sub: 'Summary at 14:00 UTC if >5% of brands failed yesterday.' },
    { key: 'connection_errored', title: 'Connection errored', sub: 'Real-time when any platform connection moves to errored.' },
    { key: 'ticket_assigned',    title: 'Ticket assigned to me', sub: 'Phase 3.' },
    { key: 'weekly_summary',     title: 'Weekly summary',     sub: "Monday morning recap of the prior week's totals." },
  ];

  return (
    <div style={{ maxWidth: 640 }}>
      <h3 className="section-title">Email alerts</h3>
      <form
        className="card"
        style={{ padding: 24 }}
        onSubmit={(e) => {
          e.preventDefault();
          updateMutation.mutate(prefs);
        }}
      >
        <div className="form-grid">
          {options.map((opt) => (
            <label key={opt.key} className="flex items-start gap-8" style={{ cursor: 'pointer' }}>
              <input
                type="checkbox"
                checked={!!prefs[opt.key]}
                onChange={() => toggle(opt.key as string)}
                style={{ marginTop: 3 }}
              />
              <span>
                <strong>{opt.title}.</strong> <span className="muted">{opt.sub}</span>
              </span>
            </label>
          ))}
        </div>
        <div className="flex items-center gap-8 mt-24">
          <Button
            type="submit"
            size="sm"
            variant="primary"
            disabled={!dirty || updateMutation.isPending}
          >
            {updateMutation.isPending ? 'Saving…' : 'Save preferences'}
          </Button>
        </div>
      </form>
    </div>
  );
}

/* ---- Danger ----------------------------------------------------------- */

function DangerTab() {
  return (
    <div style={{ maxWidth: 640 }}>
      <h3 className="section-title" style={{ color: 'var(--danger)' }}>Danger zone</h3>
      <div className="card" style={{ padding: 24, borderColor: '#FCA5A5' }}>
        <div className="flex items-center justify-between">
          <div>
            <div style={{ fontWeight: 500 }}>Export workspace data</div>
            <div className="text-sm muted mt-4">
              CSV of brands, daily_metrics, sync_logs, audit_logs. May take a few minutes.
            </div>
          </div>
          <Button size="sm" variant="secondary" onClick={() => toast.info('Export queued', 'You\'ll get an email when it\'s ready.')}>
            Request export
          </Button>
        </div>
        <hr className="divider" style={{ margin: '20px 0' }} />
        <div className="flex items-center justify-between">
          <div>
            <div style={{ fontWeight: 500, color: 'var(--danger)' }}>Delete workspace</div>
            <div className="text-sm muted mt-4">
              Permanently delete all brands, metrics, connections, and audit logs. Cannot be undone.
            </div>
          </div>
          <Button
            size="sm"
            variant="secondary"
            style={{ color: 'var(--danger)' }}
            onClick={() => {
              if (
                window.confirm(
                  'Delete the entire workspace? This cannot be undone. All brands, metric rows, and the audit log will be permanently removed.'
                )
              ) {
                toast.error('Workspace deletion is locked in this build.', 'Available behind a feature flag.');
              }
            }}
          >
            Delete workspace
          </Button>
        </div>
      </div>
    </div>
  );
}

/* ---- Error banner ----------------------------------------------------- */

function ErrorBanner({ error }: { error: unknown }) {
  const msg = error instanceof Error ? error.message : 'Something went wrong.';
  return (
    <div style={{ maxWidth: 640 }}>
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
        Couldn&rsquo;t reach the API: {msg}. Is <span className="mono">php artisan serve</span> running?
      </Banner>
    </div>
  );
}
