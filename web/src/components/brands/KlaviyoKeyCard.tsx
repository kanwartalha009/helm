import { useState } from 'react';
import { Button, Dot, Tag } from '@/components/ui';
import {
  useBrandKlaviyo,
  useRemoveBrandKlaviyo,
  useSaveBrandKlaviyo,
  useTestBrandKlaviyo,
} from '@/hooks/useBrandKlaviyo';
import { toast } from '@/stores/toastStore';

/**
 * Per-brand Klaviyo private key (GO-1.1).
 *
 * Lives on the brand's **Connections** tab, beside Shopify/Meta/Google/TikTok — because that
 * is what it is: a platform connection. It sat under Settings only because it's the one
 * credential that is per-BRAND rather than agency-wide (each brand has its own Klaviyo
 * account), and that's a fact about where the key comes from, not about where the operator
 * looks for it.
 *
 * The key is write-only (never returned by the API). Saving runs a live connection test
 * immediately, so the operator gets real feedback instead of a silent save.
 */
export function KlaviyoKeyCard({ slug }: { slug: string }) {
  const { data } = useBrandKlaviyo(slug);
  const save = useSaveBrandKlaviyo(slug);
  const test = useTestBrandKlaviyo(slug);
  const remove = useRemoveBrandKlaviyo(slug);
  const [key, setKey] = useState('');

  const connected = data?.connected ?? false;

  const onSave = () => {
    if (key.trim().length < 10) return;
    save.mutate(key.trim(), {
      onSuccess: (r) => {
        setKey('');
        r.test.ok
          ? toast.success('Klaviyo connected', r.test.message)
          : toast.error('Key saved, but the test failed', r.test.message);
      },
      onError: () => toast.error('Could not save the key', 'Try again.'),
    });
  };

  const onTest = () => {
    test.mutate(undefined, {
      onSuccess: (r) => (r.ok ? toast.success('Klaviyo OK', r.message) : toast.error('Klaviyo test failed', r.message)),
    });
  };

  return (
    <div className="platform-card">
      <div className="platform-card-head">
        <span className="platform-logo">K</span>
        <div>
          <div style={{ fontWeight: 500 }}>Klaviyo</div>
          <div className="text-xs muted">{connected ? 'Private key saved' : 'Not connected'}</div>
        </div>
        <Tag variant={connected ? 'success' : 'warning'} style={{ marginLeft: 'auto' }}>
          <Dot variant={connected ? 'success' : 'warning'} />
          {connected ? 'Connected' : 'Not connected'}
        </Tag>
      </div>

      <div className="platform-card-status">
        Email revenue syncs daily once a key is saved, and is reported as its own channel — never added to
        store or ad revenue.
      </div>

      <div className="flex items-center gap-8" style={{ flexWrap: 'wrap' }}>
        <input
          className="input"
          type="password"
          autoComplete="off"
          placeholder={connected ? 'Paste a new key to replace the current one' : 'pk_…'}
          value={key}
          onChange={(e) => setKey(e.target.value)}
          maxLength={255}
          style={{ maxWidth: 260 }}
        />
        {/* type="button" is load-bearing: the ui Button has no default type, and this card
            used to sit inside the brand Settings <form>, where every one of these silently
            submitted that form. Moving it out fixes that, but the guard stays. */}
        <Button
          size="sm"
          variant="secondary"
          type="button"
          disabled={key.trim().length < 10 || save.isPending}
          onClick={onSave}
        >
          {save.isPending ? 'Saving…' : connected ? 'Replace key' : 'Save key'}
        </Button>
        {connected && (
          <>
            <Button size="sm" variant="secondary" type="button" disabled={test.isPending} onClick={onTest}>
              {test.isPending ? 'Testing…' : 'Test'}
            </Button>
            <button
              type="button"
              className="muted text-xs"
              style={{ background: 'none', border: 0, cursor: 'pointer' }}
              disabled={remove.isPending}
              onClick={() => remove.mutate(undefined, { onSuccess: () => toast.info('Klaviyo key removed') })}
            >
              remove
            </button>
          </>
        )}
      </div>

      <span className="field-hint" style={{ marginTop: 8 }}>
        A brand-specific <b>private</b> key (Klaviyo → Settings → API keys) with the read scopes
        <code> campaigns:read flows:read metrics:read</code>. Klaviyo fixes a key’s scopes <b>at creation</b> — they
        cannot be changed later, so create the key with all three. Once saved, history can be backfilled from the
        data coverage card.
      </span>
    </div>
  );
}
