import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import {
  Avatar,
  Banner,
  Button,
  Card,
  Input,
  Stepper,
  Wordmark,
} from '@/components/ui';
import {
  useCompleteOnboarding,
  useCurrentUser,
  useDeleteAvatar,
  useUploadAvatar,
} from '@/hooks/useSettings';
import { toast } from '@/stores/toastStore';

const TIMEZONES = [
  'UTC',
  'Europe/Madrid',
  'Europe/Berlin',
  'Europe/London',
  'America/New_York',
  'America/Los_Angeles',
  'Asia/Dubai',
  'Asia/Riyadh',
];

/**
 * 3-step first-run wizard. Triggered for any authenticated user whose
 * `onboardingCompletedAt` is null. Captures the basics so no Helm screen
 * ever renders with an empty workspace name or a half-empty profile.
 */
export function OnboardingPage() {
  const navigate = useNavigate();
  const { data: user, isLoading } = useCurrentUser();
  const onboardMutation = useCompleteOnboarding();

  const [step, setStep] = useState<1 | 2 | 3>(1);
  const [form, setForm] = useState({
    name: '',
    display_initials: '',
    timezone: 'UTC',
    workspace_name: '',
  });

  // Pre-fill from whatever is already in the DB. Defensive — assume any
  // string field can be null/undefined.
  useEffect(() => {
    if (!user) return;
    const safeName = user.name ?? '';
    const safeInitial = safeName ? safeName.slice(0, 1).toUpperCase() : '';
    setForm((f) => ({
      ...f,
      name: f.name || safeName,
      display_initials:
        f.display_initials ||
        (user.displayInitials ?? '') ||
        safeInitial ||
        '',
      timezone:
        f.timezone === 'UTC' && user.timezone ? user.timezone : f.timezone,
    }));
  }, [user]);

  if (isLoading) {
    return (
      <Shell>
        <Card style={{ padding: 40, textAlign: 'center' }}>
          <span className="muted">Loading…</span>
        </Card>
      </Shell>
    );
  }

  const initials = (
    form.display_initials ||
    (form.name || '').slice(0, 1) ||
    '?'
  ).toUpperCase();

  return (
    <Shell>
      <div style={{ marginBottom: 32 }}>
        <Stepper
          steps={[
            { label: 'Your profile',  state: step === 1 ? 'active' : 'done' },
            { label: 'Workspace',     state: step === 1 ? 'pending' : step === 2 ? 'active' : 'done' },
            { label: 'You’re in', state: step === 3 ? 'active' : 'pending' },
          ]}
        />
      </div>

      {step === 1 && (
        <Step1
          form={form}
          setForm={setForm}
          initials={initials}
          avatarUrl={user?.avatarUrl}
          onContinue={() => {
            if (!form.name.trim()) {
              toast.error('Name is required');
              return;
            }
            setStep(2);
          }}
        />
      )}

      {step === 2 && (
        <Step2
          form={form}
          setForm={setForm}
          onBack={() => setStep(1)}
          onContinue={() => {
            if (!form.workspace_name.trim()) {
              toast.error('Workspace name is required');
              return;
            }
            setStep(3);
          }}
        />
      )}

      {step === 3 && (
        <Step3
          form={form}
          submitting={onboardMutation.isPending}
          onBack={() => setStep(2)}
          onFinish={async () => {
            try {
              await onboardMutation.mutateAsync(form);
              navigate('/dashboard');
            } catch {
              // toast shown
            }
          }}
        />
      )}
    </Shell>
  );
}

/* --- shell ----------------------------------------------------------- */

function Shell({ children }: { children: React.ReactNode }) {
  return (
    <div
      style={{
        minHeight: '100vh',
        background: 'var(--bg)',
        display: 'flex',
        flexDirection: 'column',
      }}
    >
      <header style={{ padding: '24px 32px' }}>
        <Wordmark />
      </header>
      <main
        style={{
          flex: 1,
          display: 'flex',
          justifyContent: 'center',
          alignItems: 'flex-start',
          paddingTop: '6vh',
          paddingBottom: 64,
        }}
      >
        <div style={{ width: '100%', maxWidth: 580, padding: '0 24px' }}>{children}</div>
      </main>
    </div>
  );
}

/* --- step 1: profile ------------------------------------------------- */

function Step1({
  form,
  setForm,
  initials,
  avatarUrl,
  onContinue,
}: {
  form: any;
  setForm: any;
  initials: string;
  avatarUrl: string | null | undefined;
  onContinue: () => void;
}) {
  return (
    <>
      <div style={{ marginBottom: 24 }}>
        <h2 style={{ marginBottom: 6 }}>Tell us about you</h2>
        <p className="muted text-sm">
          We&rsquo;ll use this on every screen — your sidebar, your audit log entries, your invitations.
        </p>
      </div>

      <Card style={{ padding: 24 }}>
        <div className="flex items-center gap-16 mb-24">
          <AvatarUploader currentUrl={avatarUrl} fallback={initials} />
          <div className="muted text-sm" style={{ flex: 1 }}>
            Optional. PNG, JPG, or WebP up to 2 MB. Square images look best.
          </div>
        </div>

        <div className="form-grid">
          <Input
            label="Full name"
            value={form.name}
            onChange={(e) => setForm({ ...form, name: e.target.value })}
            placeholder="e.g. Kanwar Singh"
            autoFocus
            required
          />
          <div className="form-grid form-grid-2">
            <Input
              label="Display initials"
              value={form.display_initials}
              onChange={(e) =>
                setForm({ ...form, display_initials: e.target.value.toUpperCase().slice(0, 4) })
              }
              maxLength={4}
              placeholder={initials}
              hint="Shown in your avatar circle."
            />
            <div className="field">
              <label className="field-label">Timezone</label>
              <select
                className="input"
                value={form.timezone}
                onChange={(e) => setForm({ ...form, timezone: e.target.value })}
              >
                {TIMEZONES.map((tz) => (
                  <option key={tz}>{tz}</option>
                ))}
              </select>
              <span className="field-hint">For UI timestamps. Brand metrics use the brand&rsquo;s timezone.</span>
            </div>
          </div>
        </div>

        <div className="flex items-center justify-end mt-24">
          <Button size="md" variant="primary" onClick={onContinue}>
            Continue →
          </Button>
        </div>
      </Card>
    </>
  );
}

/* --- step 2: workspace ----------------------------------------------- */

function Step2({
  form,
  setForm,
  onBack,
  onContinue,
}: {
  form: any;
  setForm: any;
  onBack: () => void;
  onContinue: () => void;
}) {
  return (
    <>
      <div style={{ marginBottom: 24 }}>
        <h2 style={{ marginBottom: 6 }}>Set up your workspace</h2>
        <p className="muted text-sm">
          This is your agency. You can rename it later from Settings → General.
        </p>
      </div>

      <Card style={{ padding: 24 }}>
        <div className="form-grid">
          <Input
            label="Workspace name"
            value={form.workspace_name}
            onChange={(e) => setForm({ ...form, workspace_name: e.target.value })}
            placeholder="e.g. Nova Solution"
            autoFocus
            required
          />
          {/* Primary blended currency removed in Phase 1 — every brand
              renders in its own native currency on the dashboard. */}
        </div>

        <div className="flex items-center justify-between mt-24">
          <Button size="md" variant="ghost" onClick={onBack}>
            ← Back
          </Button>
          <Button size="md" variant="primary" onClick={onContinue}>
            Continue →
          </Button>
        </div>
      </Card>
    </>
  );
}

/* --- step 3: confirm ------------------------------------------------- */

function Step3({
  form,
  submitting,
  onBack,
  onFinish,
}: {
  form: any;
  submitting: boolean;
  onBack: () => void;
  onFinish: () => void;
}) {
  return (
    <>
      <div style={{ marginBottom: 24 }}>
        <h2 style={{ marginBottom: 6 }}>You&rsquo;re ready</h2>
        <p className="muted text-sm">
          Quick recap before we drop you on the dashboard.
        </p>
      </div>

      <Card style={{ padding: 24 }}>
        <Row label="Your name"      value={form.name} />
        <Row label="Initials"       value={form.display_initials || form.name.slice(0, 1)} />
        <Row label="Timezone"       value={form.timezone} />
        <hr className="divider" style={{ margin: '16px 0' }} />
        <Row label="Workspace"      value={form.workspace_name} />

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
          Next step after this is connecting your first brand. You can do that from the dashboard&rsquo;s <strong>Add brand</strong> button.
        </Banner>

        <div className="flex items-center justify-between mt-24">
          <Button size="md" variant="ghost" onClick={onBack} disabled={submitting}>
            ← Back
          </Button>
          <Button size="md" variant="primary" onClick={onFinish} disabled={submitting}>
            {submitting ? 'Setting up…' : 'Go to dashboard →'}
          </Button>
        </div>
      </Card>
    </>
  );
}

/* --- avatar uploader ------------------------------------------------- */

function AvatarUploader({ currentUrl, fallback }: { currentUrl: string | null | undefined; fallback: string }) {
  const upload = useUploadAvatar();
  const remove = useDeleteAvatar();
  const inputRef = useRef<HTMLInputElement>(null);

  return (
    <div className="flex items-center gap-12">
      {currentUrl ? (
        <img
          src={currentUrl}
          alt="Avatar"
          style={{
            width: 64,
            height: 64,
            borderRadius: '50%',
            objectFit: 'cover',
            border: '1px solid var(--border)',
          }}
        />
      ) : (
        <Avatar initials={fallback} size={64} round inverted style={{ fontSize: 22 }} />
      )}
      <div className="flex flex-col gap-8">
        <input
          ref={inputRef}
          type="file"
          accept="image/png,image/jpeg,image/webp"
          style={{ display: 'none' }}
          onChange={(e) => {
            const file = e.target.files?.[0];
            if (file) upload.mutate(file);
            e.target.value = ''; // allow re-uploading same file
          }}
        />
        <Button
          size="sm"
          variant="secondary"
          onClick={() => inputRef.current?.click()}
          disabled={upload.isPending}
        >
          {upload.isPending ? 'Uploading…' : currentUrl ? 'Replace' : 'Upload photo'}
        </Button>
        {currentUrl && (
          <Button
            size="sm"
            variant="ghost"
            style={{ color: 'var(--danger)' }}
            disabled={remove.isPending}
            onClick={() => remove.mutate()}
          >
            Remove
          </Button>
        )}
      </div>
    </div>
  );
}

function Row({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="flex items-center" style={{ padding: '8px 0' }}>
      <div style={{ width: 140, fontSize: 13, color: 'var(--text-muted)' }}>{label}</div>
      <div style={{ flex: 1, fontSize: 14, fontWeight: 500, color: 'var(--text)' }}>
        {value || <span className="muted">—</span>}
      </div>
    </div>
  );
}
