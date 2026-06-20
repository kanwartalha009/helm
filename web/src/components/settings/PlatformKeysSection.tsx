import { useState } from 'react';
import { Banner, Button, Card, Dot, Input, Modal, Tag } from '@/components/ui';
import {
  useSaveCredential,
  useRevokeCredential,
  useRevealCredential,
  useTestConnection,
  usePlatformCredentialSchemaLive,
  usePlatformCredentialsLive,
  type ConnectionTestResult,
} from '@/hooks/useSettings';
import type {
  PlatformCredential,
  PlatformCredentialSchemaItem,
} from '@/types/domain';

type PlatformKey = 'shopify' | 'meta' | 'google' | 'tiktok';

const PLATFORMS: { key: PlatformKey; label: string; sub: string }[] = [
  { key: 'shopify', label: 'Shopify',    sub: 'Partner app credentials — used for the OAuth install on each store.' },
  { key: 'meta',    label: 'Meta Ads',   sub: 'System User token covers every ad account in your Business Manager.' },
  { key: 'google',  label: 'Google Ads', sub: 'MCC OAuth refresh token + developer token + client credentials.' },
  { key: 'tiktok',  label: 'TikTok Ads', sub: 'Business Center owner token covers every advertiser under the BC.' },
];

interface EditState {
  platform: PlatformKey;
  schemaItem: PlatformCredentialSchemaItem;
  existing: PlatformCredential | null;
}

export function PlatformKeysSection() {
  const { data: credentials = [], isError, error } = usePlatformCredentialsLive();
  const { data: schema } = usePlatformCredentialSchemaLive();
  const testMutation = useTestConnection();
  const [editing, setEditing] = useState<EditState | null>(null);
  const [testResults, setTestResults] = useState<Record<string, ConnectionTestResult>>({});

  const handleTest = (platform: PlatformKey) => {
    testMutation.mutate(platform, {
      onSuccess: (data) => setTestResults((r) => ({ ...r, [platform]: data })),
    });
  };

  if (isError) {
    return (
      <Banner
        variant="warning"
        icon={
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75">
            <circle cx="12" cy="12" r="10" />
            <line x1="12" y1="8" x2="12" y2="12" />
          </svg>
        }
      >
        Couldn&rsquo;t load credentials: {(error as Error)?.message ?? 'unknown'}. Is the API server running?
      </Banner>
    );
  }

  if (!schema) {
    return <div className="muted" style={{ maxWidth: 760 }}>Loading…</div>;
  }

  return (
    <div style={{ maxWidth: 760 }}>
      <h3 className="section-title">Platform keys</h3>
      <p className="text-sm muted mb-16">
        Credentials Roasdriven uses to talk to Shopify, Meta, Google, and TikTok on the agency&rsquo;s behalf. Stored encrypted in the database (AES-256 via APP_KEY). Per-store Shopify access tokens live on each brand&rsquo;s connection card, not here.
      </p>

      <Banner
        variant="warning"
        icon={
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75">
            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
            <line x1="12" y1="9" x2="12" y2="13" />
            <line x1="12" y1="17" x2="12.01" y2="17" />
          </svg>
        }
      >
        Rotating a key keeps the old value with status <em>rotated</em> for audit. Test the new value before relying on it.
      </Banner>

      <div className="mt-24" style={{ display: 'flex', flexDirection: 'column', gap: 20 }}>
        {PLATFORMS.map((platform) => {
          const items = schema[platform.key] ?? [];
          const rowsForPlatform = credentials.filter(
            (c) => c.platform === platform.key && c.status === 'active'
          );
          const testResult = testResults[platform.key];

          return (
            <Card key={platform.key} style={{ padding: 0, overflow: 'hidden' }}>
              <div style={{ padding: '16px 20px', borderBottom: '1px solid var(--border)' }}>
                <div className="flex items-center gap-12">
                  <span className="platform-logo">{platform.label.charAt(0)}</span>
                  <div style={{ flex: 1 }}>
                    <div style={{ fontWeight: 500 }}>{platform.label}</div>
                    <div className="text-xs muted mt-4">{platform.sub}</div>
                  </div>
                  <ConnectionStatus
                    rows={rowsForPlatform}
                    expected={items.length}
                    testResult={testResult}
                  />
                  <Button
                    size="sm"
                    variant="secondary"
                    onClick={() => handleTest(platform.key)}
                    disabled={testMutation.isPending || rowsForPlatform.length < items.length}
                  >
                    {testMutation.isPending && testMutation.variables === platform.key
                      ? 'Testing…'
                      : 'Test connection'}
                  </Button>
                </div>
              </div>

              <table className="data-table" style={{ fontSize: 13.5 }}>
                <thead>
                  <tr>
                    <th style={{ width: '28%' }}>Key</th>
                    <th>Value</th>
                    <th>Last used</th>
                    <th />
                  </tr>
                </thead>
                <tbody>
                  {items.map((item) => {
                    const existing = rowsForPlatform.find((r) => r.key === item.key) ?? null;
                    return (
                      <tr key={item.key}>
                        <td>
                          <div style={{ fontWeight: 500 }}>{item.label}</div>
                          <div className="brand-meta mono">
                            {item.key}
                            {item.sensitive ? ' · sensitive' : ''}
                          </div>
                        </td>
                        <td>
                          {existing ? (
                            <RevealableValue
                              id={existing.id}
                              masked={existing.maskedValue}
                              label={`${platform.label} ${item.label}`}
                            />
                          ) : (
                            <Tag variant="warning">
                              <Dot variant="warning" />
                              Not set
                            </Tag>
                          )}
                        </td>
                        <td className="muted text-sm">
                          {existing?.lastUsedAt
                            ? new Date(existing.lastUsedAt).toLocaleString()
                            : '—'}
                        </td>
                        <td className="text-right">
                          <div
                            className="flex items-center gap-8"
                            style={{ justifyContent: 'flex-end' }}
                          >
                            <Button
                              size="sm"
                              variant="secondary"
                              onClick={() =>
                                setEditing({
                                  platform: platform.key,
                                  schemaItem: item,
                                  existing,
                                })
                              }
                            >
                              {existing ? 'Rotate' : 'Add'}
                            </Button>
                            {existing && (
                              <RevokeButton id={existing.id} platformLabel={platform.label} keyLabel={item.label} />
                            )}
                          </div>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </Card>
          );
        })}
      </div>

      {editing && (
        <EditCredentialModal state={editing} onClose={() => setEditing(null)} />
      )}
    </div>
  );
}

function ConnectionStatus({
  rows,
  expected,
  testResult,
}: {
  rows: PlatformCredential[];
  expected: number;
  testResult?: ConnectionTestResult;
}) {
  // If we have a recent live test, that takes precedence over the "configured" state.
  if (testResult) {
    return testResult.ok ? (
      <Tag variant="success">
        <Dot variant="success" />
        Connected
      </Tag>
    ) : (
      <Tag variant="warning" title={testResult.message}>
        <Dot variant="warning" />
        Failed
      </Tag>
    );
  }

  if (rows.length === expected) {
    return (
      <Tag>
        <Dot variant="muted" />
        Configured
      </Tag>
    );
  }
  if (rows.length === 0) {
    return (
      <Tag variant="warning">
        <Dot variant="warning" />
        Not configured
      </Tag>
    );
  }
  return (
    <Tag variant="warning">
      <Dot variant="warning" />
      {rows.length} of {expected} set
    </Tag>
  );
}

/**
 * Cell that defaults to the masked preview but lets master_admin click "Show"
 * to reveal the full stored value. The reveal endpoint requires a password,
 * writes an audit log entry, and returns the plaintext once. We don't cache
 * the revealed value — clicking Hide drops it from local component state.
 */
function RevealableValue({
  id,
  masked,
  label,
}: {
  id: number;
  masked: string;
  label: string;
}) {
  const reveal = useRevealCredential();
  const [open, setOpen] = useState(false);
  const [password, setPassword] = useState('');
  const [revealed, setRevealed] = useState<string | null>(null);
  const [copied, setCopied] = useState(false);

  const onReveal = async () => {
    try {
      const res = await reveal.mutateAsync({ id, password });
      setRevealed(res.value);
      setOpen(false);
      setPassword('');
    } catch {
      // toast already fired
    }
  };

  const onCopy = async () => {
    if (!revealed) return;
    await navigator.clipboard.writeText(revealed);
    setCopied(true);
    setTimeout(() => setCopied(false), 1500);
  };

  if (revealed) {
    return (
      <div className="flex items-center gap-8">
        <span
          className="mono text-sm"
          style={{
            wordBreak: 'break-all',
            background: 'var(--surface-subtle)',
            padding: '2px 6px',
            borderRadius: 4,
          }}
        >
          {revealed}
        </span>
        <button
          className="btn btn-ghost btn-sm"
          onClick={onCopy}
          title="Copy to clipboard"
          style={{ padding: '2px 6px' }}
        >
          {copied ? 'Copied' : 'Copy'}
        </button>
        <button
          className="btn btn-ghost btn-sm"
          onClick={() => setRevealed(null)}
          title="Hide"
          style={{ padding: '2px 6px' }}
        >
          Hide
        </button>
      </div>
    );
  }

  return (
    <>
      <div className="flex items-center gap-8">
        <span className="mono text-sm">{masked}</span>
        <button
          className="btn btn-ghost btn-sm"
          onClick={() => setOpen(true)}
          style={{ padding: '2px 6px' }}
        >
          Show
        </button>
      </div>
      {open && (
        <Modal
          open
          onClose={() => { setOpen(false); setPassword(''); }}
          title={`Reveal ${label}`}
          footer={
            <>
              <Button size="sm" variant="ghost" onClick={() => { setOpen(false); setPassword(''); }}>
                Cancel
              </Button>
              <Button
                size="sm"
                variant="primary"
                disabled={!password || reveal.isPending}
                onClick={onReveal}
              >
                {reveal.isPending ? 'Revealing…' : 'Reveal'}
              </Button>
            </>
          }
        >
          <Banner variant="warning" className="mb-16">
            Revealing the value writes an entry to the audit log so other admins know it was viewed.
          </Banner>
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

function RevokeButton({ id, platformLabel, keyLabel }: { id: number; platformLabel: string; keyLabel: string }) {
  const revokeMutation = useRevokeCredential();
  return (
    <Button
      size="sm"
      variant="ghost"
      style={{ color: 'var(--danger)' }}
      disabled={revokeMutation.isPending}
      onClick={() => {
        if (
          window.confirm(
            `Revoke ${platformLabel} ${keyLabel}? Sync will fail until a replacement is set.`
          )
        ) {
          revokeMutation.mutate(id);
        }
      }}
    >
      Revoke
    </Button>
  );
}

function EditCredentialModal({ state, onClose }: { state: EditState; onClose: () => void }) {
  const { platform, schemaItem, existing } = state;
  const platformLabel = PLATFORMS.find((p) => p.key === platform)?.label ?? platform;
  const isRotation = !!existing;
  const saveMutation = useSaveCredential();

  const [value, setValue] = useState('');

  return (
    <Modal
      open
      onClose={onClose}
      title={
        isRotation
          ? `Rotate ${platformLabel} · ${schemaItem.label}`
          : `Add ${platformLabel} · ${schemaItem.label}`
      }
      footer={
        <>
          <Button size="sm" variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button
            size="sm"
            variant="primary"
            disabled={!value || saveMutation.isPending}
            onClick={() => {
              saveMutation.mutate(
                {
                  platform,
                  key: schemaItem.key,
                  value,
                },
                { onSuccess: () => onClose() }
              );
            }}
          >
            {saveMutation.isPending ? 'Saving…' : isRotation ? 'Rotate key' : 'Save key'}
          </Button>
        </>
      }
    >
      {isRotation && (
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
          <div>Current value:</div>
          <div className="mt-8">
            <RevealableValue
              id={existing!.id}
              masked={existing!.maskedValue}
              label={`${platformLabel} ${schemaItem.label}`}
            />
          </div>
          <div className="mt-8 text-xs muted">Saving replaces it. Old value stays in audit log.</div>
        </Banner>
      )}

      <form
        onSubmit={(e) => e.preventDefault()}
        className="mt-16"
      >
        <div className="form-grid">
          <Input
            label={schemaItem.label}
            type={schemaItem.sensitive ? 'password' : 'text'}
            autoComplete="off"
            placeholder={schemaItem.sensitive ? 'Paste the value' : ''}
            value={value}
            onChange={(e) => setValue(e.target.value)}
            hint={
              schemaItem.sensitive
                ? 'Encrypted with APP_KEY on save. Never logged or echoed.'
                : 'Plain text. Not encrypted because it is not sensitive.'
            }
            required
            autoFocus
          />
        </div>
      </form>
    </Modal>
  );
}
