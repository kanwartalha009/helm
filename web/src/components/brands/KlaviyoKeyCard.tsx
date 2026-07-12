import { useState } from 'react';
import { Button } from '@/components/ui';
import {
  useBrandKlaviyo,
  useRemoveBrandKlaviyo,
  useSaveBrandKlaviyo,
  useTestBrandKlaviyo,
} from '@/hooks/useBrandKlaviyo';
import { toast } from '@/stores/toastStore';

/**
 * Per-brand Klaviyo private key (GO-1.1). Unlike the agency-level platform tokens,
 * every brand has its own Klaviyo account and therefore its own key — so it lives on
 * the brand's Settings tab, not the workspace Platform-keys page.
 *
 * The key is write-only (never returned by the API). Saving runs a live connection
 * test immediately so the operator gets real feedback instead of a silent save.
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
    <div className="field" style={{ marginTop: 8 }}>
      <label className="field-label">
        Klaviyo{' '}
        <span className="text-xs muted">{connected ? '· connected' : '· not connected'}</span>
      </label>

      <div className="flex items-center gap-8" style={{ flexWrap: 'wrap' }}>
        <input
          className="input"
          type="password"
          autoComplete="off"
          placeholder={connected ? 'Paste a new key to replace the current one' : 'pk_…'}
          value={key}
          onChange={(e) => setKey(e.target.value)}
          maxLength={255}
          style={{ maxWidth: 320 }}
        />
        <Button size="sm" variant="secondary" disabled={key.trim().length < 10 || save.isPending} onClick={onSave}>
          {save.isPending ? 'Saving…' : connected ? 'Replace key' : 'Save key'}
        </Button>
        {connected && (
          <>
            <Button size="sm" variant="secondary" disabled={test.isPending} onClick={onTest}>
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

      <span className="field-hint">
        A brand-specific <b>private</b> key (Klaviyo → Settings → API keys) with the read scopes
        <code> campaigns:read flows:read metrics:read</code>. Klaviyo fixes a key's scopes <b>at creation</b> — they
        cannot be changed later, so create the key with all three. Once saved, email revenue syncs daily and can be
        backfilled from the data coverage card. Email revenue is reported as its own channel and is never added to
        store or ad revenue.
      </span>
    </div>
  );
}
