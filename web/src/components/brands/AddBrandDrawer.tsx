import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Banner, Button, Drawer, Input, Stepper, Tag } from '@/components/ui';
import {
  useConnectShopifyToken,
  useCreateBrand,
  useShopifyInstallUrl,
} from '@/hooks/useBrands';
import type { Brand } from '@/types/domain';

type ConnectMethod = 'oauth' | 'token';

interface AddBrandDrawerProps {
  open: boolean;
  onClose: () => void;
}

const TIMEZONES = [
  'UTC',
  'Europe/Madrid',
  'Europe/Berlin',
  'Europe/Rome',
  'Europe/Stockholm',
  'Europe/London',
  'America/New_York',
  'America/Los_Angeles',
  'Asia/Dubai',
  'Asia/Riyadh',
];

const CURRENCIES = ['USD', 'EUR', 'GBP', 'AED', 'SAR', 'SEK'];

/**
 * Slide-in Add Brand wizard.
 *
 * Step 1: Brand details → POST /api/brands.
 * Step 2: Paste Shopify Admin API access token → POST /connections/shopify/token,
 *         server validates against Shopify before persisting.
 * Step 3: "Done" — open the brand to trigger the first sync.
 *
 * OAuth flow has been removed per docs/05-platforms/shopify-store-onboarding.md.
 * The intern creates an admin custom app on the store, copies the shpat_ token,
 * and pastes it here.
 */
export function AddBrandDrawer({ open, onClose }: AddBrandDrawerProps) {
  const navigate = useNavigate();
  const [step, setStep] = useState<1 | 2 | 3>(1);
  const [brand, setBrand] = useState<Brand | null>(null);
  const [form, setForm] = useState({
    name: '',
    slug: '',
    timezone: 'UTC',
    base_currency: 'USD',
    group_tag: '',
  });

  // Step 2 — Shopify connect.
  const [method, setMethod] = useState<ConnectMethod>('oauth');
  const [shopDomain, setShopDomain] = useState('');
  // OAuth path: this brand's Partner app credentials (per-brand, encrypted on save).
  const [oauthClientId, setOauthClientId] = useState('');
  const [oauthClientSecret, setOauthClientSecret] = useState('');
  // Paste-token path (legacy).
  const [accessToken, setAccessToken] = useState('');
  const [apiKey, setApiKey] = useState('');
  const [apiSecret, setApiSecret] = useState('');

  const createBrand = useCreateBrand();
  const connectShopify = useConnectShopifyToken();
  const installUrl = useShopifyInstallUrl();

  const reset = () => {
    setStep(1);
    setBrand(null);
    setMethod('oauth');
    setForm({ name: '', slug: '', timezone: 'UTC', base_currency: 'USD', group_tag: '' });
    setShopDomain('');
    setOauthClientId('');
    setOauthClientSecret('');
    setAccessToken('');
    setApiKey('');
    setApiSecret('');
  };

  const handleClose = () => {
    onClose();
    setTimeout(reset, 250); // wait for the slide-out before clearing state
  };

  const slug = form.slug || autoSlug(form.name);
  // No prefix check — Shopify token formats vary by pathway. Server validates.
  const step2Valid = method === 'oauth'
    ? shopDomain.trim() !== '' &&
      oauthClientId.trim() !== '' &&
      oauthClientSecret.trim() !== ''
    : shopDomain.trim() !== '' && accessToken.trim() !== '';

  return (
    <Drawer
      open={open}
      onClose={handleClose}
      size="lg"
      title="Add a brand"
      footer={
        <>
          <span className="text-xs muted">Step {step} of 3</span>
          <div className="flex items-center gap-8">
            {step > 1 && (
              <Button size="sm" variant="ghost" onClick={() => setStep((s) => (s - 1) as 1 | 2 | 3)}>
                ← Back
              </Button>
            )}
            {step === 1 && (
              <Button
                size="sm"
                variant="primary"
                disabled={!form.name.trim() || createBrand.isPending}
                onClick={async () => {
                  try {
                    const created = await createBrand.mutateAsync({
                      name: form.name.trim(),
                      slug: slug || undefined,
                      timezone: form.timezone,
                      base_currency: form.base_currency,
                      group_tag: form.group_tag.trim() || null,
                    });
                    setBrand(created);
                    setShopDomain(`${created.slug}.myshopify.com`);
                    setStep(2);
                  } catch {
                    // toast shown by hook
                  }
                }}
              >
                {createBrand.isPending ? 'Creating…' : 'Create brand →'}
              </Button>
            )}
            {step === 2 && brand && (
              <>
                <Button
                  size="sm"
                  variant="ghost"
                  onClick={() => setStep(3)}
                >
                  Skip for now
                </Button>
                {method === 'oauth' ? (
                  <Button
                    size="sm"
                    variant="primary"
                    disabled={!step2Valid || installUrl.isPending}
                    onClick={async () => {
                      try {
                        const res = await installUrl.mutateAsync({
                          brandSlug: brand.slug,
                          shopDomain: shopDomain.trim(),
                          clientId: oauthClientId.trim(),
                          clientSecret: oauthClientSecret.trim(),
                        });
                        window.open(res.url, '_blank', 'noopener,noreferrer');
                        // OAuth completes asynchronously when the user approves on
                        // Shopify; move them to Step 3 immediately so the wizard
                        // doesn't hang waiting for the callback.
                        setStep(3);
                      } catch {
                        // toast shown by hook
                      }
                    }}
                  >
                    {installUrl.isPending ? 'Building URL…' : 'Continue to Shopify →'}
                  </Button>
                ) : (
                  <Button
                    size="sm"
                    variant="primary"
                    disabled={!step2Valid || connectShopify.isPending}
                    onClick={async () => {
                      try {
                        await connectShopify.mutateAsync({
                          brandSlug: brand.slug,
                          shopDomain: shopDomain.trim(),
                          accessToken: accessToken.trim(),
                          apiKey: apiKey.trim() || undefined,
                          apiSecret: apiSecret.trim() || undefined,
                        });
                        setStep(3);
                      } catch {
                        // toast shown by hook
                      }
                    }}
                  >
                    {connectShopify.isPending ? 'Validating…' : 'Save & validate →'}
                  </Button>
                )}
              </>
            )}
            {step === 3 && brand && (
              <Button
                size="sm"
                variant="primary"
                onClick={() => {
                  navigate(`/brands/${brand.slug}`);
                  handleClose();
                }}
              >
                Open {brand.name} →
              </Button>
            )}
          </div>
        </>
      }
    >
      <div style={{ marginBottom: 24 }}>
        <Stepper
          steps={[
            { label: 'Details', state: step === 1 ? 'active' : 'done' },
            { label: 'Connect', state: step === 1 ? 'pending' : step === 2 ? 'active' : 'done' },
            { label: 'Done',    state: step === 3 ? 'active' : 'pending' },
          ]}
        />
      </div>

      {step === 1 && (
        <Step1Details
          form={form}
          slug={slug}
          onChange={(patch) => setForm((f) => ({ ...f, ...patch }))}
        />
      )}

      {step === 2 && brand && (
        <Step2Connect
          brand={brand}
          method={method}
          onMethodChange={setMethod}
          shopDomain={shopDomain}
          oauthClientId={oauthClientId}
          oauthClientSecret={oauthClientSecret}
          accessToken={accessToken}
          apiKey={apiKey}
          apiSecret={apiSecret}
          onShopDomain={setShopDomain}
          onOauthClientId={setOauthClientId}
          onOauthClientSecret={setOauthClientSecret}
          onAccessToken={setAccessToken}
          onApiKey={setApiKey}
          onApiSecret={setApiSecret}
        />
      )}

      {step === 3 && brand && <Step3Done brand={brand} />}
    </Drawer>
  );
}

function autoSlug(name: string): string {
  return name
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-|-$/g, '');
}

/* ---- Step 1 — brand details ----------------------------------------- */

function Step1Details({
  form,
  slug,
  onChange,
}: {
  form: {
    name: string;
    slug: string;
    timezone: string;
    base_currency: string;
    group_tag: string;
  };
  slug: string;
  onChange: (patch: Partial<typeof form>) => void;
}) {
  return (
    <div className="form-grid">
      <p className="text-sm muted">
        Timezone and currency are auto-detected from Shopify on the next step — feel free to leave the defaults if you’re not sure.
      </p>

      <Input
        label="Brand name"
        value={form.name}
        onChange={(e) => onChange({ name: e.target.value })}
        placeholder="e.g. Meller"
        autoFocus
        required
      />

      <Input
        label="Slug"
        value={form.slug || slug}
        onChange={(e) => onChange({ slug: e.target.value })}
        placeholder={slug || 'meller'}
        hint="URL identifier. Auto-generated from name."
      />

      <div className="form-grid form-grid-2">
        <div className="field">
          <label className="field-label">Timezone</label>
          <select
            className="input"
            value={form.timezone}
            onChange={(e) => onChange({ timezone: e.target.value })}
          >
            {TIMEZONES.map((tz) => (
              <option key={tz}>{tz}</option>
            ))}
          </select>
          <span className="field-hint">Every metric date is in this timezone.</span>
        </div>

        <div className="field">
          <label className="field-label">Base currency</label>
          <select
            className="input"
            value={form.base_currency}
            onChange={(e) => onChange({ base_currency: e.target.value })}
          >
            {CURRENCIES.map((c) => (
              <option key={c}>{c}</option>
            ))}
          </select>
        </div>
      </div>

      <Input
        label="Group tag (optional)"
        value={form.group_tag}
        onChange={(e) => onChange({ group_tag: e.target.value })}
        placeholder="EU, GCC, fashion…"
        hint="Used for grouping in the dashboard. Free text."
      />
    </div>
  );
}

/* ---- Step 2 — connect Shopify (two methods) ------------------------- */

function Step2Connect({
  brand,
  method,
  onMethodChange,
  shopDomain,
  oauthClientId,
  oauthClientSecret,
  accessToken,
  apiKey,
  apiSecret,
  onShopDomain,
  onOauthClientId,
  onOauthClientSecret,
  onAccessToken,
  onApiKey,
  onApiSecret,
}: {
  brand: Brand;
  method: ConnectMethod;
  onMethodChange: (m: ConnectMethod) => void;
  shopDomain: string;
  oauthClientId: string;
  oauthClientSecret: string;
  accessToken: string;
  apiKey: string;
  apiSecret: string;
  onShopDomain: (v: string) => void;
  onOauthClientId: (v: string) => void;
  onOauthClientSecret: (v: string) => void;
  onAccessToken: (v: string) => void;
  onApiKey: (v: string) => void;
  onApiSecret: (v: string) => void;
}) {
  return (
    <>
      <p className="text-sm muted mb-16">
        <strong>{brand.name}</strong> is created. Pick how you want to connect Shopify.
      </p>

      <Tag style={{ marginBottom: 16 }}>Required for Phase 1</Tag>

      {/* Method picker. OAuth is the only Shopify-supported path for new
          installs as of January 2026 (legacy custom apps were deprecated).
          Paste-token is kept for stores that still have a working shpat_
          token from before the cutoff. */}
      <div className="card mb-16" style={{ padding: 4, display: 'flex', gap: 4 }}>
        <MethodTab
          active={method === 'oauth'}
          onClick={() => onMethodChange('oauth')}
          title="Install Shopify app"
          subtitle="Partner Dashboard custom-distribution (recommended)"
        />
        <MethodTab
          active={method === 'token'}
          onClick={() => onMethodChange('token')}
          title="I have an access token"
          subtitle="Legacy shpat_ token (pre-Jan 2026)"
        />
      </div>

      {method === 'oauth' ? (
        <>
          <Banner variant="info" className="mb-16">
            Each brand needs its own Partner Dashboard custom-distribution app —
            Shopify scopes them to one Plus organization. Paste this brand's
            <strong> Client ID</strong> + <strong>Secret</strong> below; we'll store
            them encrypted on the brand row.
          </Banner>
          <details className="mb-16" style={{ cursor: 'pointer' }}>
            <summary style={{ fontWeight: 500 }}>One-time setup for this brand's Partner app</summary>
            <ol className="text-sm muted mt-12" style={{ paddingLeft: 18, lineHeight: 1.7 }}>
              <li>
                <a href="https://partners.shopify.com" target="_blank" rel="noopener noreferrer">Partner Dashboard</a> → <strong>Create app</strong> → custom-distribution.
                Pick a test/development store on the same Plus organization as <strong>{brand.name}</strong>.
              </li>
              <li>
                Configuration → <strong>Allowed redirection URL(s)</strong>:{' '}
                <span className="mono">{window.location.origin}/connections/shopify/callback</span>. Save.
              </li>
              <li>
                Distribution → <strong>Custom distribution</strong> → add this brand's store. Save.
              </li>
              <li>
                Copy the <strong>Client ID</strong> + <strong>Secret</strong> from the
                Overview tab and paste below.
              </li>
            </ol>
          </details>
          <div className="form-grid">
            <Input
              label="Client ID"
              value={oauthClientId}
              onChange={(e) => onOauthClientId(e.target.value)}
              placeholder="dde8255758d669…"
              hint="From Partner Dashboard → this brand's app → Overview → Client credentials."
              autoFocus
              required
            />
            <Input
              label="Client Secret"
              type="password"
              autoComplete="off"
              value={oauthClientSecret}
              onChange={(e) => onOauthClientSecret(e.target.value)}
              placeholder="shpss_…"
              hint="Stored encrypted with APP_KEY. Never logged or echoed."
              required
            />
            <Input
              label="Shop domain"
              value={shopDomain}
              onChange={(e) => onShopDomain(e.target.value)}
              placeholder="brand.myshopify.com"
              hint="Use the *.myshopify.com domain even if the store has a custom domain."
            />
          </div>
        </>
      ) : (
        <>
          <Banner variant="info" className="mb-16">
            Paste the <span className="mono">shpat_…</span> token shown by the store admin's{' '}
            <strong>Settings → Apps and sales channels → Develop apps</strong> page. Roasdriven
            validates against Shopify before saving.
          </Banner>
          <details className="mb-16" style={{ cursor: 'pointer' }}>
            <summary style={{ fontWeight: 500 }}>How to get the access token (3 min)</summary>
            <ol className="text-sm muted mt-12" style={{ paddingLeft: 18, lineHeight: 1.7 }}>
              <li>Store admin: <span className="mono">Settings → Apps and sales channels → Develop apps</span>.</li>
              <li>Click <strong>"Allow custom app development"</strong> if prompted.</li>
              <li>Click <strong>"Create an app"</strong>, name it <span className="mono">Roasdriven Analytics</span>.</li>
              <li>
                <strong>Configuration → Admin API integration → Configure</strong>. Tick:{' '}
                <span className="mono">read_orders</span>, <span className="mono">read_products</span>,{' '}
                <span className="mono">read_customers</span>, <span className="mono">read_reports</span>. Save.
              </li>
              <li><strong>Install app</strong>, confirm.</li>
              <li>API credentials tab → <strong>"Reveal token once"</strong>. Copy now.</li>
            </ol>
          </details>
          <div className="form-grid">
            <Input
              label="Shop domain"
              value={shopDomain}
              onChange={(e) => onShopDomain(e.target.value)}
              placeholder="brand.myshopify.com"
              autoFocus
            />
            <Input
              label="Admin API access token"
              type="password"
              autoComplete="off"
              value={accessToken}
              onChange={(e) => onAccessToken(e.target.value)}
              placeholder="shpat_…"
              hint="Starts with shpat_. Stored encrypted with APP_KEY."
              required
            />
            <Input
              label="API key (optional)"
              value={apiKey}
              onChange={(e) => onApiKey(e.target.value)}
            />
            <Input
              label="API secret (optional)"
              type="password"
              autoComplete="off"
              value={apiSecret}
              onChange={(e) => onApiSecret(e.target.value)}
            />
          </div>
        </>
      )}
    </>
  );
}

function MethodTab({
  active,
  onClick,
  title,
  subtitle,
}: {
  active: boolean;
  onClick: () => void;
  title: string;
  subtitle: string;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      style={{
        flex: 1,
        textAlign: 'left',
        padding: '10px 14px',
        borderRadius: 6,
        border: '1px solid transparent',
        background: active ? 'var(--surface)' : 'transparent',
        borderColor: active ? 'var(--border)' : 'transparent',
        cursor: 'pointer',
      }}
    >
      <div style={{ fontWeight: 500, fontSize: 14 }}>{title}</div>
      <div className="text-xs muted">{subtitle}</div>
    </button>
  );
}

/* ---- Step 3 — done -------------------------------------------------- */

function Step3Done({ brand }: { brand: Brand }) {
  return (
    <>
      <Banner variant="info">
        <strong>{brand.name}</strong> is connected. Open it to trigger the first sync — Roasdriven will
        pull every historical order Shopify hands back. Daily syncs then run automatically at 13:00 UTC.
      </Banner>

      <div className="card mt-24" style={{ padding: 20 }}>
        <div style={{ fontWeight: 500, marginBottom: 8 }}>What happens next</div>
        <ul style={{ paddingLeft: 18, fontSize: 14, lineHeight: 1.7, color: 'var(--text-secondary)', margin: 0 }}>
          <li>
            <strong>{brand.name}</strong> shows up in the brands list and on the dashboard.
          </li>
          <li>Click Sync now on the brand page to pull all historical orders right now.</li>
          <li>
            Meta, Google, and TikTok columns render <code className="mono">N/A</code> until those integrations ship in Phase 2.
          </li>
        </ul>
      </div>
    </>
  );
}
