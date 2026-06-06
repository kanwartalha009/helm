import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { AppLayout } from '@/components/shell/AppLayout';
import {
  Avatar,
  Banner,
  Breadcrumb,
  Button,
  Card,
  Dot,
  Drawer,
  Dropdown,
  DropdownDivider,
  DropdownItem,
  EmptyState,
  Input,
  Modal,
  PageHeader,
  Tabs,
  Tag,
} from '@/components/ui';
import { useBrandDetail, useBrandMetrics, type BrandMetricTile } from '@/hooks/useApiData';
import { formatMoney } from '@/lib/formatters';
import { useQueryClient } from '@tanstack/react-query';
import {
  getShopifyInstallStatus,
  useAttachMetaAccounts,
  useConnectShopifyToken,
  useDeleteBrand,
  useDisconnectConnection,
  useMetaAvailableAccounts,
  useShopifyInstallUrl,
  useShopifyPreview,
  useTriggerSync,
  useUpdateBrand,
  type ShopifyPreviewResponse,
} from '@/hooks/useBrands';
import { toast } from '@/stores/toastStore';
import type { Brand, PlatformConnection } from '@/types/domain';

export function BrandDetailPage() {
  const { slug } = useParams();
  const navigate = useNavigate();
  const { data, isLoading, isError, error } = useBrandDetail(slug);
  const triggerSync = useTriggerSync();
  const deleteBrand = useDeleteBrand();
  const previewShopify = useShopifyPreview();
  const [searchParams, setSearchParams] = useSearchParams();
  const [installOpen, setInstallOpen] = useState(false);
  const [previewResult, setPreviewResult] = useState<ShopifyPreviewResponse | null>(null);

  // Shopify callback bounces us back here with ?connected=shopify&ok=1
  // or ?connected=shopify&ok=0&reason=... — show a toast then clean the URL.
  useEffect(() => {
    if (searchParams.get('connected') !== 'shopify') return;
    if (searchParams.get('ok') === '0') {
      const reason = searchParams.get('reason') ?? 'unknown error';
      toast.error('Shopify install failed', reason);
    } else {
      toast.success('Shopify connected', 'Run a sync to pull yesterday’s revenue.');
    }
    const next = new URLSearchParams(searchParams);
    next.delete('connected');
    next.delete('ok');
    next.delete('reason');
    setSearchParams(next, { replace: true });
  }, [searchParams, setSearchParams]);

  if (isLoading) {
    return (
      <AppLayout title="Loading brand">
        <div className="muted" style={{ padding: 24 }}>Loading brand…</div>
      </AppLayout>
    );
  }

  if (isError || !data) {
    const msg = (error as any)?.response?.data?.message ?? (error as Error)?.message ?? 'Brand not found';
    return (
      <AppLayout title="Brand not found">
        <Breadcrumb crumbs={[{ label: 'Brands', to: '/brands' }, { label: 'Not found' }]} />
        <EmptyState
          title="We couldn’t load this brand"
          description={msg}
          action={
            <Button variant="primary" onClick={() => navigate('/brands')}>
              Back to brands
            </Button>
          }
        />
      </AppLayout>
    );
  }

  const { brand, connections } = data;
  const shopify = connections.find((c) => c.platform === 'shopify');
  const shopifyConnected = shopify?.status === 'active';

  return (
    <AppLayout title={brand.name}>
      <Breadcrumb crumbs={[{ label: 'Brands', to: '/brands' }, { label: brand.name }]} />

      <PageHeader
        leading={<Avatar initials={brand.initials} size={44} style={{ borderRadius: 8 }} />}
        title={brand.name}
        subtitle={
          [
            brand.shopDomain ?? 'No store connected',
            brand.region,
            brand.baseCurrency,
            `${brand.timezone} timezone`,
          ]
            .filter(Boolean)
            .join(' · ')
        }
        actions={
          <>
            <Button
              size="sm"
              variant="ghost"
              disabled={!shopifyConnected || previewShopify.isPending}
              onClick={() => {
                previewShopify.mutate(brand.slug, {
                  onSuccess: (res) => setPreviewResult(res),
                });
              }}
              title="Pull the 5 most recent orders directly from Shopify to confirm the connection works."
            >
              {previewShopify.isPending ? 'Testing…' : 'Test connection'}
            </Button>
            <Button
              size="sm"
              variant="secondary"
              disabled={!shopifyConnected || triggerSync.isPending}
              onClick={() => triggerSync.mutate(brand.slug)}
              leftIcon={
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75">
                  <path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8" />
                  <polyline points="21 3 21 8 16 8" />
                </svg>
              }
            >
              {triggerSync.isPending ? 'Queuing…' : 'Sync now'}
            </Button>
            <Dropdown align="right" trigger={<button className="btn btn-secondary btn-sm">More ↓</button>}>
              <DropdownItem href="#connections">Connections</DropdownItem>
              <DropdownItem href="#settings">Brand settings</DropdownItem>
              <DropdownDivider />
              <DropdownItem
                danger
                onClick={() => {
                  const msg =
                    `Delete ${brand.name} permanently?\n\n` +
                    `This removes the brand, every Shopify/Meta/Google/TikTok connection on it, ` +
                    `every daily_metrics row, and every sync log entry. There is no undo.\n\n` +
                    `Type the brand name to confirm? No — just click OK if you're sure.`;
                  if (!window.confirm(msg)) return;
                  deleteBrand.mutate(brand.slug, {
                    onSuccess: () => navigate('/brands'),
                  });
                }}
              >
                {deleteBrand.isPending ? 'Deleting…' : 'Delete brand'}
              </DropdownItem>
            </Dropdown>
          </>
        }
      />

      <Tabs
        tabs={[
          {
            id: 'overview',
            label: 'Overview',
            content: (
              <OverviewTab
                brand={brand}
                shopifyConnected={shopifyConnected}
                onInstall={() => setInstallOpen(true)}
                onSync={() => triggerSync.mutate(brand.slug)}
                syncing={triggerSync.isPending}
              />
            ),
          },
          {
            id: 'connections',
            label: `Connections${shopifyConnected ? '' : ' · 1'}`,
            content: (
              <ConnectionsTab
                brand={brand}
                connections={connections}
                onInstall={() => setInstallOpen(true)}
              />
            ),
          },
          { id: 'syncs', label: 'Sync log', content: <SyncLogTab brand={brand} /> },
          { id: 'settings', label: 'Settings', content: <SettingsTab brand={brand} /> },
        ]}
      />

      <ShopifyInstallDrawer
        open={installOpen}
        onClose={() => setInstallOpen(false)}
        brand={brand}
      />

      {previewResult && (
        <ShopifyPreviewModal
          result={previewResult}
          onClose={() => setPreviewResult(null)}
        />
      )}
    </AppLayout>
  );
}

/* ------------------------- Shopify test modal ------------------------- */

function ShopifyPreviewModal({
  result,
  onClose,
}: {
  result: ShopifyPreviewResponse;
  onClose: () => void;
}) {
  const { shop, orders, count } = result;
  const json = JSON.stringify(result, null, 2);

  const copyJson = async () => {
    try {
      await navigator.clipboard.writeText(json);
    } catch {
      // ignore
    }
  };

  return (
    <Modal
      open
      onClose={onClose}
      title="Shopify connection test"
      footer={
        <>
          <Button size="sm" variant="ghost" onClick={copyJson}>
            Copy raw JSON
          </Button>
          <Button size="sm" variant="primary" onClick={onClose}>
            Close
          </Button>
        </>
      }
    >
      <Banner variant="info" className="mb-16">
        <strong>{count}</strong> most-recent order{count === 1 ? '' : 's'} from{' '}
        <span className="mono">{shop?.myshopifyDomain ?? 'unknown shop'}</span>
        {shop && ` · ${shop.currencyCode} · ${shop.ianaTimezone}`}.
        Pulled live from Shopify — proves the connection works end-to-end.
      </Banner>

      {orders.length === 0 ? (
        <p className="muted text-sm">
          Connection works but the store has no orders yet. That's a valid result — once orders
          come in, they'll show up here.
        </p>
      ) : (
        <>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
            {orders.map((o) => (
              <div
                key={o.id}
                style={{
                  padding: '10px 12px',
                  background: 'var(--surface-subtle)',
                  border: '1px solid var(--border)',
                  borderRadius: 6,
                }}
              >
                <div className="flex items-center justify-between">
                  <div>
                    <strong>{o.name}</strong>{' '}
                    <span className="muted text-xs mono">{o.id.split('/').pop()}</span>
                  </div>
                  <div className="mono text-sm">
                    {o.currentTotalPriceSet.shopMoney.amount}{' '}
                    {o.currentTotalPriceSet.shopMoney.currencyCode}
                  </div>
                </div>
                <div className="text-xs muted mt-4">
                  {new Date(o.createdAt).toLocaleString()}
                  {o.customer?.email ? ` · ${o.customer.email}` : ''}
                </div>
                {o.lineItems.edges.length > 0 && (
                  <div className="text-xs muted mt-4">
                    {o.lineItems.edges
                      .map((e) => `${e.node.quantity}× ${e.node.title}`)
                      .join(' · ')}
                  </div>
                )}
              </div>
            ))}
          </div>

          <details className="mt-16" style={{ cursor: 'pointer' }}>
            <summary className="text-sm" style={{ fontWeight: 500 }}>
              Raw JSON response
            </summary>
            <pre
              className="mono"
              style={{
                marginTop: 8,
                padding: 12,
                background: 'var(--surface-subtle)',
                border: '1px solid var(--border)',
                borderRadius: 6,
                fontSize: 11,
                maxHeight: 320,
                overflow: 'auto',
                whiteSpace: 'pre-wrap',
                wordBreak: 'break-all',
              }}
            >
              {json}
            </pre>
          </details>
        </>
      )}
    </Modal>
  );
}

/* ----------------------------- Overview ------------------------------- */

function OverviewTab({
  brand,
  shopifyConnected,
  onInstall,
  onSync,
  syncing,
}: {
  brand: Brand;
  shopifyConnected: boolean;
  onInstall: () => void;
  onSync: () => void;
  syncing: boolean;
}) {
  const metricsQ = useBrandMetrics(shopifyConnected ? brand.slug : undefined);

  if (!shopifyConnected) {
    return (
      <EmptyState
        title="Connect Shopify to start syncing"
        description={
          <>
            <strong>{brand.name}</strong> is created, but Helm needs a Shopify connection to pull orders, refunds, and revenue. Install the Helm Shopify app on this brand’s store and the dashboard will populate the moment the first sync finishes.
          </>
        }
        action={
          <div className="flex items-center gap-8">
            <Button variant="primary" onClick={onInstall}>
              Install on Shopify
            </Button>
            <Link to="/sync-health" className="btn btn-secondary">
              View sync health
            </Link>
          </div>
        }
      />
    );
  }

  if (metricsQ.isLoading) {
    return <div className="muted" style={{ padding: 24 }}>Loading metrics…</div>;
  }

  const metrics = metricsQ.data;
  const tiles = metrics?.tiles;
  const daily = metrics?.daily ?? [];
  const currency = metrics?.currency ?? brand.baseCurrency;
  const noData = !tiles || tiles.allTime.days === 0;

  if (noData) {
    return (
      <Banner variant="info">
        Shopify is connected but no data has been synced yet. Run a sync now to pull every historical order from this store, or wait for the 13:00 UTC daily job.
        <div className="mt-8">
          <Button size="sm" variant="primary" onClick={onSync} disabled={syncing}>
            {syncing ? 'Syncing…' : 'Run sync now'}
          </Button>
        </div>
      </Banner>
    );
  }

  return (
    <>
      <div className="stat-grid stat-grid-4 mb-24">
        <StatTile tile={tiles!.today} currency={currency} />
        <StatTile tile={tiles!.yesterday} currency={currency} />
        <StatTile tile={tiles!.last7} currency={currency} />
        <StatTile tile={tiles!.last30} currency={currency} />
      </div>

      <div className="flex items-center justify-between mb-12">
        <h3 className="section-title" style={{ margin: 0 }}>
          Daily breakdown
        </h3>
        <span className="text-xs muted">
          {tiles!.allTime.days} day{tiles!.allTime.days === 1 ? '' : 's'} on file ·
          all-time net sales {formatMoney(tiles!.allTime.netSales, currency)}
        </span>
      </div>

      <Card style={{ overflow: 'hidden' }}>
        <table className="data-table">
          <thead>
            <tr>
              <th style={{ width: 130 }}>Date</th>
              <th className="num">Net sales</th>
              <th className="num">Total revenue</th>
              <th className="num">Orders</th>
              <th className="num">Refunds</th>
              <th>Status</th>
              <th className="text-right" style={{ width: 160 }}>Pulled at</th>
            </tr>
          </thead>
          <tbody>
            {daily.map((row) => (
              <tr key={`${row.platform}-${row.date}`}>
                <td className="mono">{row.date}</td>
                <td className="num">{row.netSales !== null ? formatMoney(row.netSales, currency) : '—'}</td>
                <td className="num muted">{formatMoney(row.revenue ?? 0, currency)}</td>
                <td className="num">{row.orders ?? 0}</td>
                <td className="num muted">{formatMoney(row.refunds ?? 0, currency)}</td>
                <td>
                  {row.isComplete ? (
                    <span className="status-pill success">Complete</span>
                  ) : (
                    <span className="status-pill warning">Partial (today)</span>
                  )}
                </td>
                <td className="text-right muted text-sm">
                  {row.pulledAt ? new Date(row.pulledAt).toLocaleString() : '—'}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </Card>

      <div className="mt-24">
        <h3 className="section-title">Quick links</h3>
        <Card style={{ padding: 4, maxWidth: 360 }}>
          <Link to={`/brands/${brand.slug}/ads`} className="dropdown-item">
            Ad performance (Phase 2)
          </Link>
          <Link to={`/brands/${brand.slug}/products`} className="dropdown-item">
            Product performance (Phase 2)
          </Link>
          <Link to={`/brands/${brand.slug}/audit`} className="dropdown-item">
            Store audit (Phase 2)
          </Link>
          <div className="dropdown-divider" />
          <Link to="/tickets" className="dropdown-item">
            Tickets for this brand
          </Link>
        </Card>
      </div>
    </>
  );
}

function StatTile({ tile, currency }: { tile: BrandMetricTile; currency: string }) {
  const subParts: string[] = [];
  if (tile.orders > 0) subParts.push(`${tile.orders} order${tile.orders === 1 ? '' : 's'}`);
  if (tile.refunds > 0) subParts.push(`-${formatMoney(tile.refunds, currency)} refunds`);
  if (tile.days > 1) subParts.push(`${tile.days} days`);
  const sub = subParts.join(' · ') || (tile.label === 'Today' ? 'In progress' : '—');

  return (
    <div className="stat">
      <div className="stat-label">{tile.label}</div>
      <div className="stat-value num">{formatMoney(tile.netSales, currency)}</div>
      <div className="stat-sub muted">{sub}{!tile.isComplete ? ' · partial' : ''}</div>
    </div>
  );
}

/* --------------------------- Connections ------------------------------ */

function ConnectionsTab({
  brand,
  connections,
  onInstall,
}: {
  brand: Brand;
  connections: PlatformConnection[];
  onInstall: () => void;
}) {
  const shopify = connections.find((c) => c.platform === 'shopify');
  const meta = connections.find((c) => c.platform === 'meta');
  const google = connections.find((c) => c.platform === 'google');
  const tiktok = connections.find((c) => c.platform === 'tiktok');

  return (
    <>
      <p className="text-sm muted mb-24">
        Connect or manage the platforms feeding this brand’s metrics. Shopify is per-store; Meta, Google,
        and TikTok use the agency-wide credentials configured in Settings.
      </p>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: 16 }}>
        <ShopifyCard brand={brand} conn={shopify} onInstall={onInstall} />
        <MetaConnectCard brand={brand} conn={meta} />
        <AdPlatformCard label="Google Ads" logo="G" conn={google} />
        <AdPlatformCard label="TikTok Ads" logo="T" conn={tiktok} />
      </div>
    </>
  );
}

function ShopifyCard({
  brand,
  conn,
  onInstall,
}: {
  brand: Brand;
  conn: PlatformConnection | undefined;
  onInstall: () => void;
}) {
  // Status is authoritative: only `errored` means the OAuth credentials
  // themselves are dead and the user must re-install. A transient sync
  // failure leaves status='active' with a non-null lastError — we surface
  // the message but don't push the user toward re-OAuth.
  const connected   = conn?.status === 'active';
  const authErrored = conn?.status === 'errored';
  const syncWarning = !!conn && conn.status === 'active' && !!conn.lastError;
  const disconnect = useDisconnectConnection();

  const onDisconnect = () => {
    if (!conn) return;
    const msg = `Disconnect Shopify from ${brand.name}? Helm will stop syncing this store. You can re-install at any time.`;
    if (!window.confirm(msg)) return;
    disconnect.mutate({ connectionId: conn.id, brandSlug: brand.slug });
  };

  return (
    <div className="platform-card">
      <div className="platform-card-head">
        <span className="platform-logo">S</span>
        <div>
          <div style={{ fontWeight: 500 }}>Shopify</div>
          <div className="text-xs muted">
            {conn?.externalId ?? 'No store connected yet'}
          </div>
        </div>
        {authErrored ? (
          <Tag variant="warning" style={{ marginLeft: 'auto' }}>
            <Dot variant="warning" />
            Errored
          </Tag>
        ) : syncWarning ? (
          <Tag variant="warning" style={{ marginLeft: 'auto' }}>
            <Dot variant="warning" />
            Sync issue
          </Tag>
        ) : connected ? (
          <Tag variant="success" style={{ marginLeft: 'auto' }}>
            <Dot variant="success" />
            Connected
          </Tag>
        ) : (
          <Tag variant="warning" style={{ marginLeft: 'auto' }}>
            <Dot variant="warning" />
            Not connected
          </Tag>
        )}
      </div>
      <div className="platform-card-status">
        {authErrored ? (
          <>Last error: <span className="mono">{conn?.lastError ?? 'unknown'}</span>. Disconnect and re-add with a fresh token.</>
        ) : syncWarning ? (
          <>Last sync attempt failed: <span className="mono">{conn?.lastError ?? 'unknown'}</span>. The connection is healthy — Helm will retry automatically on the next sync.</>
        ) : connected
          ? `Last sync ${conn?.lastSyncAt ? new Date(conn.lastSyncAt).toLocaleString() : '—'}.`
          : `Create an admin custom app in the store, copy its Admin API access token, and paste it here. Takes 3 minutes per store.`}
      </div>
      <div className="flex items-center gap-8" style={{ flexWrap: 'wrap' }}>
        {conn ? (
          <>
            {authErrored && (
              <>
                <Button size="sm" variant="primary" onClick={onInstall}>
                  Replace token
                </Button>
                <ShopifyOAuthButton brand={brand} variant="secondary" label="Re-install via OAuth" />
              </>
            )}
            <Button
              size="sm"
              variant="ghost"
              style={{ color: 'var(--danger)' }}
              onClick={onDisconnect}
              disabled={disconnect.isPending}
            >
              {disconnect.isPending ? 'Disconnecting…' : 'Disconnect'}
            </Button>
          </>
        ) : (
          <>
            <ShopifyOAuthButton brand={brand} variant="primary" label="Install Shopify app" />
            <Button size="sm" variant="ghost" onClick={onInstall}>
              I have an access token
            </Button>
          </>
        )}
      </div>
      {!connected && (
        <div className="mt-12 text-xs muted">
          Click <strong>Install Shopify app</strong> to send the store through the OAuth install
          link from your Partner Dashboard custom-distribution app. The token gets created
          automatically. Use <strong>I have an access token</strong> only if you still have a
          legacy <span className="mono">shpat_…</span> token from before January 2026.
        </div>
      )}
    </div>
  );
}

function AdPlatformCard({
  label,
  logo,
  conn,
}: {
  label: string;
  logo: string;
  conn: PlatformConnection | undefined;
}) {
  const connected = conn?.status === 'active';
  return (
    <div className="platform-card">
      <div className="platform-card-head">
        <span className="platform-logo">{logo}</span>
        <div>
          <div style={{ fontWeight: 500 }}>{label}</div>
          <div className="text-xs muted">{conn?.externalId ?? 'Not connected'}</div>
        </div>
        <Tag variant="warning" style={{ marginLeft: 'auto' }}>
          <Dot variant="warning" />
          {connected ? 'Connected' : 'Phase 2'}
        </Tag>
      </div>
      <div className="platform-card-status">
        {connected
          ? `Last sync ${conn?.lastSyncAt ? new Date(conn.lastSyncAt).toLocaleString() : '—'}.`
          : 'Ad-platform sync isn’t wired up in Phase 1.'}
      </div>
      <div className="flex items-center gap-8">
        <Button size="sm" variant="secondary" disabled>
          {connected ? 'Resync' : 'Coming soon'}
        </Button>
      </div>
    </div>
  );
}

/* ----------------------- Meta account picker -------------------------- */

/**
 * Meta connects at the org level (System User token in Settings); here the
 * brand selects which ad accounts under that Business Manager belong to it.
 * Multiple accounts are blended into one brand-level Meta row at sync time.
 */
function MetaConnectCard({ brand, conn }: { brand: Brand; conn: PlatformConnection | undefined }) {
  const [open, setOpen] = useState(false);
  const [search, setSearch] = useState('');
  const [selected, setSelected] = useState<string[]>([]);

  const attachedIds = Array.isArray(conn?.metadata?.ad_account_ids)
    ? (conn!.metadata!.ad_account_ids as string[])
    : [];
  const accountNames = (conn?.metadata?.account_names as Record<string, string> | undefined) ?? {};
  const connected = !!conn && conn.status === 'active' && attachedIds.length > 0;

  const available = useMetaAvailableAccounts(brand.slug, open);
  const attach = useAttachMetaAccounts();

  const openPicker = () => {
    setSelected(attachedIds); // seed with whatever's already attached
    setSearch('');
    setOpen(true);
  };

  const toggle = (id: string) =>
    setSelected((cur) => (cur.includes(id) ? cur.filter((x) => x !== id) : [...cur, id]));

  const save = () =>
    attach.mutate(
      { brandSlug: brand.slug, accountIds: selected },
      { onSuccess: () => setOpen(false) }
    );

  const accounts = available.data ?? [];
  const q = search.trim().toLowerCase();
  const filtered = q
    ? accounts.filter(
        (a) => a.name.toLowerCase().includes(q) || a.external_id.toLowerCase().includes(q)
      )
    : accounts;

  return (
    <div className="platform-card">
      <div className="platform-card-head">
        <span className="platform-logo">M</span>
        <div>
          <div style={{ fontWeight: 500 }}>Meta Ads</div>
          <div className="text-xs muted">
            {connected
              ? `${attachedIds.length} ad account${attachedIds.length === 1 ? '' : 's'}`
              : 'Not connected'}
          </div>
        </div>
        <Tag variant={connected ? 'success' : 'default'} style={{ marginLeft: 'auto' }}>
          <Dot variant={connected ? 'success' : 'muted'} />
          {connected ? 'Connected' : 'Not connected'}
        </Tag>
      </div>

      <div className="platform-card-status">
        {connected
          ? `Blending spend from ${attachedIds.map((id) => accountNames[id] ?? id).join(', ')}.`
          : 'Pick the ad accounts under your agency Business Manager that belong to this brand.'}
      </div>

      <div className="flex items-center gap-8">
        <Button size="sm" variant={connected ? 'secondary' : 'primary'} onClick={openPicker}>
          {connected ? 'Manage accounts' : 'Connect'}
        </Button>
      </div>

      <Modal
        open={open}
        onClose={() => setOpen(false)}
        title="Select Meta ad accounts"
        size="lg"
        footer={
          <div className="flex items-center justify-between" style={{ width: '100%' }}>
            <span className="text-xs muted">{selected.length} selected</span>
            <div className="flex items-center gap-8">
              <Button size="sm" variant="secondary" onClick={() => setOpen(false)}>
                Cancel
              </Button>
              <Button size="sm" variant="primary" onClick={save} disabled={attach.isPending}>
                {attach.isPending ? 'Saving…' : 'Save'}
              </Button>
            </div>
          </div>
        }
      >
        <p className="text-sm muted mb-12">
          Every ad account your agency&rsquo;s Meta System User token can see. Tick the ones that
          belong to {brand.name}; their daily spend is blended into this brand.
        </p>

        <Input
          placeholder="Search by name or act_id…"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />

        <div style={{ marginTop: 12, maxHeight: 320, overflowY: 'auto' }}>
          {available.isLoading && <div className="text-sm muted">Loading accounts…</div>}
          {available.isError && (
            <div className="text-sm muted">
              {(available.error as any)?.response?.data?.message ??
                'Could not load accounts. Is the Meta System User token set in Settings → Platform keys?'}
            </div>
          )}
          {!available.isLoading && !available.isError && filtered.length === 0 && (
            <div className="text-sm muted">No accounts found.</div>
          )}
          {filtered.map((a) => (
            <label
              key={a.external_id}
              style={{
                display: 'flex',
                alignItems: 'center',
                gap: 10,
                padding: '8px 4px',
                borderBottom: '1px solid var(--border)',
                cursor: 'pointer',
              }}
            >
              <input
                type="checkbox"
                checked={selected.includes(a.external_id)}
                onChange={() => toggle(a.external_id)}
              />
              <span style={{ flex: 1 }}>
                <span style={{ fontWeight: 500 }}>{a.name}</span>
                <span className="text-xs muted" style={{ marginLeft: 8 }}>
                  {a.external_id}
                  {a.currency ? ` · ${a.currency}` : ''}
                </span>
              </span>
            </label>
          ))}
        </div>
      </Modal>
    </div>
  );
}

/* ---------------------- Shopify OAuth button -------------------------- */

/**
 * Tiny inline shop-domain prompt that POSTs to /connections/shopify/auth-url
 * and opens the resulting Shopify install URL in a new tab. Used for Partner
 * Dashboard apps (with custom-distribution) where the per-store access token
 * is delivered via the OAuth callback rather than pasted manually.
 */
function ShopifyOAuthButton({
  brand,
  variant,
  label,
}: {
  brand: Brand;
  variant: 'primary' | 'secondary';
  label: string;
}) {
  const qc = useQueryClient();
  const [open, setOpen] = useState(false);
  const [shop, setShop] = useState(brand.shopDomain || `${brand.slug}.myshopify.com`);
  const [clientId, setClientId] = useState('');
  const [clientSecret, setClientSecret] = useState('');
  const [waiting, setWaiting] = useState(false);
  const installUrl = useShopifyInstallUrl();
  const needsAppCreds = !brand.hasShopifyApp;

  // After we hand off to Shopify, poll the install status every 2 seconds
  // for up to 2 minutes. When status flips to active, refresh the brand
  // detail cache so the Connections tab updates itself.
  const pollInstallStatus = async () => {
    setWaiting(true);
    const deadline = Date.now() + 120_000; // 2 minutes
    while (Date.now() < deadline) {
      try {
        const status = await getShopifyInstallStatus(brand.slug);
        if (status.installed && status.status === 'active') {
          toast.success('Shopify connected', `${status.shop} is now linked. Run a sync to pull historical orders.`);
          qc.invalidateQueries({ queryKey: ['brand', brand.slug] });
          qc.invalidateQueries({ queryKey: ['brand', brand.slug, 'metrics'] });
          qc.invalidateQueries({ queryKey: ['brands'] });
          qc.invalidateQueries({ queryKey: ['dashboard'] });
          setWaiting(false);
          return;
        }
        if (status.installed && status.status === 'errored') {
          toast.error('Shopify install errored', status.lastError ?? 'Unknown error from Shopify.');
          qc.invalidateQueries({ queryKey: ['brand', brand.slug] });
          setWaiting(false);
          return;
        }
      } catch {
        // Network blip — keep polling.
      }
      await new Promise((r) => setTimeout(r, 2000));
    }
    setWaiting(false);
    toast.info(
      'Still waiting on Shopify',
      "We didn't see the install land yet. Refresh the brand page once you've approved on Shopify."
    );
  };

  const onSubmit = async () => {
    const trimmed = shop.trim();
    if (!trimmed) return;
    if (needsAppCreds && (!clientId.trim() || !clientSecret.trim())) return;
    try {
      const res = await installUrl.mutateAsync({
        brandSlug: brand.slug,
        shopDomain: trimmed,
        clientId: needsAppCreds ? clientId.trim() : undefined,
        clientSecret: needsAppCreds ? clientSecret.trim() : undefined,
      });
      window.open(res.url, '_blank', 'noopener,noreferrer');
      setOpen(false);
      // Don't await — let the poll loop run in the background while the
      // operator approves on Shopify.
      pollInstallStatus();
    } catch {
      // toast already fired
    }
  };

  return (
    <>
      <Button size="sm" variant={variant} onClick={() => setOpen(true)}>
        {label}
      </Button>
      {open && (
        <Drawer
          open={open}
          onClose={() => setOpen(false)}
          title="Install via OAuth"
          size="lg"
          footer={
            <div className="flex items-center justify-end gap-8" style={{ width: '100%' }}>
              <Button variant="ghost" onClick={() => setOpen(false)}>Cancel</Button>
              <Button
                variant="primary"
                onClick={onSubmit}
                disabled={
                  installUrl.isPending ||
                  !shop.trim() ||
                  (needsAppCreds && (!clientId.trim() || !clientSecret.trim()))
                }
              >
                {installUrl.isPending ? 'Building install URL…' : 'Continue to Shopify'}
              </Button>
            </div>
          }
        >
          <Banner variant="info" className="mb-16">
            {needsAppCreds ? (
              <>
                Each brand needs its own Partner Dashboard custom-distribution app
                (Shopify scopes them to one Plus organization). Paste this brand's
                <strong> Client ID</strong> + <strong>Secret</strong> below — we'll
                store them encrypted on the brand. Future installs for this brand
                won't need them again.
              </>
            ) : (
              <>This brand's Partner app credentials are stored. Helm opens Shopify in a new tab; you approve and Shopify redirects back with the access token.</>
            )}
          </Banner>

          <details className="mb-16" style={{ cursor: 'pointer' }}>
            <summary style={{ fontWeight: 500 }}>One-time setup for this brand's Partner app</summary>
            <ol className="text-sm muted mt-12" style={{ paddingLeft: 18, lineHeight: 1.7 }}>
              <li>
                Partner Dashboard → <strong>Create app</strong> → custom-distribution. Pick a
                test store on the same Plus organization as <strong>{brand.name}</strong>.
              </li>
              <li>
                Configuration → <strong>Allowed redirection URL(s)</strong>:{' '}
                <span className="mono">{window.location.origin}/connections/shopify/callback</span>. Save.
              </li>
              <li>
                Distribution → <strong>Custom distribution</strong> → add this brand's store. Save.
              </li>
              <li>
                Copy the <strong>Client ID</strong> + <strong>Secret</strong> from
                Overview / API credentials. Paste below.
              </li>
            </ol>
          </details>

          <div className="form-grid">
            {needsAppCreds && (
              <>
                <Input
                  label="Client ID"
                  value={clientId}
                  onChange={(e) => setClientId(e.target.value)}
                  placeholder="dde8255758d669…"
                  hint="From your Partner Dashboard app → Overview → Client credentials."
                  autoFocus
                  required
                />
                <Input
                  label="Client Secret"
                  type="password"
                  autoComplete="off"
                  value={clientSecret}
                  onChange={(e) => setClientSecret(e.target.value)}
                  placeholder="shpss_…"
                  hint="Stored encrypted with APP_KEY. Never logged or echoed."
                  required
                />
              </>
            )}
            <Input
              label="Shop domain"
              value={shop}
              onChange={(e) => setShop(e.target.value)}
              placeholder="brand.myshopify.com"
              hint="Use the *.myshopify.com domain even if the store has a custom domain."
              autoFocus={!needsAppCreds}
            />
          </div>
        </Drawer>
      )}
    </>
  );
}

/* ---------------------- Shopify token drawer -------------------------- */

function ShopifyInstallDrawer({
  open,
  onClose,
  brand,
}: {
  open: boolean;
  onClose: () => void;
  brand: Brand;
}) {
  const [shop, setShop] = useState(`${brand.slug}.myshopify.com`);
  const [token, setToken] = useState('');
  const [apiKey, setApiKey] = useState('');
  const [apiSecret, setApiSecret] = useState('');

  const connect = useConnectShopifyToken();

  useEffect(() => {
    if (open) {
      setShop(brand.shopDomain || `${brand.slug}.myshopify.com`);
      setToken('');
      setApiKey('');
      setApiSecret('');
    }
  }, [open, brand.slug, brand.shopDomain]);

  // Just require non-empty. Tokens from different Shopify pathways have
  // different prefixes (shpat_, shpua_, shpss_, etc.). The server validates
  // against the live API — that's where bad tokens get rejected with a
  // useful error, not via a client-side prefix check.
  const valid = shop.trim() !== '' && token.trim() !== '';

  const onSubmit = async () => {
    if (!valid) return;
    try {
      await connect.mutateAsync({
        brandSlug: brand.slug,
        shopDomain: shop.trim(),
        accessToken: token.trim(),
        apiKey: apiKey.trim() || undefined,
        apiSecret: apiSecret.trim() || undefined,
      });
      onClose();
    } catch {
      // toast already fired from the hook's onError
    }
  };

  return (
    <Drawer
      open={open}
      onClose={onClose}
      title="Connect Shopify"
      size="lg"
      footer={
        <div className="flex items-center justify-end gap-8" style={{ width: '100%' }}>
          <Button variant="ghost" onClick={onClose}>
            Cancel
          </Button>
          <Button
            variant="primary"
            onClick={onSubmit}
            disabled={!valid || connect.isPending}
          >
            {connect.isPending ? 'Validating…' : 'Save & validate'}
          </Button>
        </div>
      }
    >
      <Banner variant="info" className="mb-16">
        Create an admin custom app in this store, copy its Admin API access token, and paste it
        below. We validate against Shopify before saving so a bad paste fails immediately.
      </Banner>

      <details className="mb-16" style={{ cursor: 'pointer' }}>
        <summary style={{ fontWeight: 500 }}>How to get the access token (3 min)</summary>
        <ol className="text-sm muted mt-12" style={{ paddingLeft: 18, lineHeight: 1.7 }}>
          <li>In the store admin: <span className="mono">Settings → Apps and sales channels → Develop apps</span>.</li>
          <li>Click <strong>"Allow custom app development"</strong> if prompted (one-time).</li>
          <li>Click <strong>"Create an app"</strong>, name it <span className="mono">Helm Analytics</span>.</li>
          <li>
            Open <strong>Configuration → Admin API integration → Configure</strong>. Tick exactly:{' '}
            <span className="mono">read_orders</span>, <span className="mono">read_products</span>,{' '}
            <span className="mono">read_customers</span>, <span className="mono">read_reports</span>. Save.
          </li>
          <li>Click <strong>Install app</strong> in the top-right, confirm.</li>
          <li>On the API credentials tab, click <strong>"Reveal token once"</strong> next to the Admin API access token. It starts with <span className="mono">shpat_</span>. Copy it now — Shopify only shows it once.</li>
          <li>(Optional) Copy the API key + secret from the bottom of the same page.</li>
        </ol>
      </details>

      <div className="form-grid">
        <Input
          label="Shop domain"
          value={shop}
          onChange={(e) => setShop(e.target.value)}
          placeholder="brand.myshopify.com"
          hint="Use the *.myshopify.com domain even if the store uses a custom domain."
        />
        <Input
          label="Admin API access token"
          type="password"
          autoComplete="off"
          value={token}
          onChange={(e) => setToken(e.target.value)}
          placeholder="shpat_…"
          hint="Starts with shpat_. Encrypted with APP_KEY before saving."
          required
        />
        <Input
          label="API key (optional)"
          value={apiKey}
          onChange={(e) => setApiKey(e.target.value)}
          hint="Useful audit context but not used for sync."
        />
        <Input
          label="API secret (optional)"
          type="password"
          autoComplete="off"
          value={apiSecret}
          onChange={(e) => setApiSecret(e.target.value)}
        />
      </div>
    </Drawer>
  );
}

/* ---------------------------- Sync log -------------------------------- */

function SyncLogTab({ brand }: { brand: Brand }) {
  // Real per-brand sync logs are a Phase 2 endpoint (`/api/brands/{slug}/syncs`).
  // For now, link to /sync-health which renders the workspace-wide list.
  return (
    <EmptyState
      title="Sync log lives on the workspace page"
      description={`Per-brand sync history will land in Phase 2. For now the workspace-wide log shows every sync attempt for ${brand.name} along with the others.`}
      action={
        <Link to="/sync-health" className="btn btn-primary">
          Open sync health
        </Link>
      }
    />
  );
}

/* ---------------------------- Settings -------------------------------- */

// Minimal IANA timezone list — covers what the agency's brand footprint
// actually uses. Keep small until we add a real timezone picker.
const TIMEZONES = [
  'UTC',
  'America/New_York',
  'America/Chicago',
  'America/Denver',
  'America/Los_Angeles',
  'America/Toronto',
  'America/Sao_Paulo',
  'Europe/London',
  'Europe/Berlin',
  'Europe/Paris',
  'Europe/Madrid',
  'Europe/Amsterdam',
  'Europe/Stockholm',
  'Europe/Warsaw',
  'Africa/Cairo',
  'Asia/Dubai',
  'Asia/Karachi',
  'Asia/Kolkata',
  'Asia/Singapore',
  'Asia/Hong_Kong',
  'Asia/Tokyo',
  'Australia/Sydney',
];

const CURRENCIES = [
  'USD', 'EUR', 'GBP', 'CAD', 'AUD', 'AED', 'SAR', 'SEK', 'NOK', 'DKK',
  'CHF', 'JPY', 'SGD', 'HKD', 'INR', 'BRL', 'MXN', 'ZAR',
];

function SettingsTab({ brand }: { brand: Brand }) {
  // Controlled form state — diff against `brand` on submit so we only send
  // the keys the user actually touched.
  const [name, setName] = useState(brand.name);
  const [timezone, setTimezone] = useState(brand.timezone);
  const [baseCurrency, setBaseCurrency] = useState(brand.baseCurrency);
  const [groupTag, setGroupTag] = useState(brand.groupTag ?? '');
  const [status, setStatus] = useState<Brand['status']>(brand.status);

  // Re-seed when the underlying brand changes (e.g. after an upstream refetch).
  useEffect(() => {
    setName(brand.name);
    setTimezone(brand.timezone);
    setBaseCurrency(brand.baseCurrency);
    setGroupTag(brand.groupTag ?? '');
    setStatus(brand.status);
  }, [brand.id, brand.name, brand.timezone, brand.baseCurrency, brand.groupTag, brand.status]);

  const updateBrand = useUpdateBrand();

  const dirty =
    name !== brand.name ||
    timezone !== brand.timezone ||
    baseCurrency !== brand.baseCurrency ||
    (groupTag || '') !== (brand.groupTag || '') ||
    status !== brand.status;

  const onSave = () => {
    const patch: Record<string, unknown> = {};
    if (name !== brand.name) patch.name = name;
    if (timezone !== brand.timezone) patch.timezone = timezone;
    if (baseCurrency !== brand.baseCurrency) patch.base_currency = baseCurrency;
    if ((groupTag || null) !== (brand.groupTag || null)) patch.group_tag = groupTag || null;
    if (status !== brand.status) patch.status = status;

    if (Object.keys(patch).length === 0) return;
    updateBrand.mutate({ slug: brand.slug, patch });
  };

  const onCancel = () => {
    setName(brand.name);
    setTimezone(brand.timezone);
    setBaseCurrency(brand.baseCurrency);
    setGroupTag(brand.groupTag ?? '');
    setStatus(brand.status);
  };

  return (
    <form
      className="form-grid"
      style={{ maxWidth: 520 }}
      onSubmit={(e) => {
        e.preventDefault();
        onSave();
      }}
    >
      <Input
        label="Brand name"
        value={name}
        onChange={(e) => setName(e.target.value)}
        required
      />
      <Input
        label="Slug"
        value={brand.slug}
        hint="URL identifier. Locked once the brand is created."
        disabled
        onChange={() => {}}
      />
      <div className="form-grid form-grid-2">
        <div className="field">
          <label className="field-label">Timezone</label>
          <select
            className="input"
            value={timezone}
            onChange={(e) => setTimezone(e.target.value)}
          >
            {!TIMEZONES.includes(timezone) && <option value={timezone}>{timezone}</option>}
            {TIMEZONES.map((tz) => (
              <option key={tz} value={tz}>{tz}</option>
            ))}
          </select>
          <span className="field-hint">daily_metrics dates are recorded in this tz.</span>
        </div>
        <div className="field">
          <label className="field-label">Base currency</label>
          <select
            className="input"
            value={baseCurrency}
            onChange={(e) => setBaseCurrency(e.target.value.toUpperCase())}
          >
            {!CURRENCIES.includes(baseCurrency) && (
              <option value={baseCurrency}>{baseCurrency}</option>
            )}
            {CURRENCIES.map((c) => (
              <option key={c} value={c}>{c}</option>
            ))}
          </select>
        </div>
      </div>
      <Input
        label="Group"
        value={groupTag}
        onChange={(e) => setGroupTag(e.target.value)}
        hint="Used for grouping in the dashboard. Free text."
      />
      <div className="form-grid form-grid-2">
        <div className="field">
          <label className="field-label">Status</label>
          <select
            className="input"
            value={status}
            onChange={(e) => setStatus(e.target.value as Brand['status'])}
          >
            <option value="active">Active</option>
            <option value="paused">Paused — keep visible, stop syncing</option>
            <option value="archived">Archived — hide from dashboard</option>
          </select>
        </div>
      </div>
      <div className="flex items-center gap-8 mt-16">
        <Button
          size="sm"
          variant="primary"
          type="submit"
          disabled={!dirty || updateBrand.isPending}
        >
          {updateBrand.isPending ? 'Saving…' : 'Save changes'}
        </Button>
        <Button
          size="sm"
          variant="ghost"
          type="button"
          onClick={onCancel}
          disabled={!dirty || updateBrand.isPending}
        >
          Cancel
        </Button>
      </div>
    </form>
  );
}
