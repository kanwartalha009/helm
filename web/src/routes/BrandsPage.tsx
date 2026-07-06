import { useState } from 'react';
import { Link } from 'react-router-dom';
import { AppLayout } from '@/components/shell/AppLayout';
import {
  Avatar,
  Banner,
  Card,
  PageEmptyState,
  PageHeader,
} from '@/components/ui';
import { useBrandsLive } from '@/hooks/useApiData';
import { useUiStore } from '@/stores/uiStore';
import { timeAgo } from '@/lib/formatters';

const PLATFORM_LABEL: Record<string, string> = {
  shopify: 'Shopify',
  meta: 'Meta',
  google: 'Google',
  tiktok: 'TikTok',
};

export function BrandsPage() {
  const { data: brands = [], isLoading, isError, error } = useBrandsLive();
  const openAddBrand = useUiStore((s) => s.setAddBrandDrawerOpen);
  const [q, setQ] = useState('');

  if (isLoading) {
    return (
      <AppLayout title="Brands">
        <PageHeader title="Brands" subtitle="Every store and ad account under your management." />
        <div className="muted" style={{ padding: 24 }}>
          Loading brands…
        </div>
      </AppLayout>
    );
  }

  if (isError) {
    return (
      <AppLayout title="Brands">
        <PageHeader title="Brands" subtitle="Every store and ad account under your management." />
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
          Couldn&rsquo;t load brands: {(error as Error)?.message ?? 'unknown error'}.
        </Banner>
      </AppLayout>
    );
  }

  if (brands.length === 0) {
    return (
      <AppLayout title="Brands">
        <PageEmptyState
          icon={
            <svg
              width="28"
              height="28"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="1.75"
            >
              <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
              <polyline points="9 22 9 12 15 12 15 22" />
            </svg>
          }
          title="No brands yet"
          body="Add your first brand to start syncing revenue and ad spend. Roasdriven rolls up Shopify, Meta, Google, and TikTok into one daily view."
          primary={
            <button onClick={() => openAddBrand(true)} className="btn btn-primary btn-lg">
              <svg
                width="14"
                height="14"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                strokeWidth="2"
              >
                <path d="M12 5v14M5 12h14" />
              </svg>
              Add brand
            </button>
          }
          secondary={
            <Link to="/settings" className="btn btn-secondary btn-lg">
              Configure platform keys
            </Link>
          }
          steps={[
            {
              n: 1,
              title: 'Add platform keys',
              body: 'One Meta System User, one Google MCC, one TikTok BC at Settings → Platform keys.',
              to: '/settings',
              cta: 'Open Platform keys',
            },
            {
              n: 2,
              title: 'Create a brand',
              body: 'Name, timezone, base currency. Roasdriven uses the brand timezone for every metric.',
              onClick: () => openAddBrand(true),
              cta: 'Add brand',
            },
            {
              n: 3,
              title: 'Connect accounts',
              body: 'Pick the Meta ad account, Google customer, TikTok advertiser. Shopify installs from the brand page.',
              onClick: () => openAddBrand(true),
              cta: 'Start',
            },
          ]}
        />
      </AppLayout>
    );
  }

  const query = q.trim().toLowerCase();
  const filtered = query
    ? brands.filter(
        (b) =>
          b.name.toLowerCase().includes(query) ||
          (b.shopDomain ?? '').toLowerCase().includes(query),
      )
    : brands;

  return (
    <AppLayout title="Brands" tag={`${brands.length} total`}>
      <PageHeader title="Brands" subtitle="Every store and ad account under your management." />

      <div className="filter-bar mb-16">
        <input
          className="input"
          type="search"
          placeholder="Search brands…"
          value={q}
          onChange={(e) => setQ(e.target.value)}
          style={{ maxWidth: 280 }}
        />
      </div>

      <Card style={{ overflow: 'hidden' }}>
        <table className="data-table">
          <thead>
            <tr>
              <th style={{ width: '28%' }}>Brand</th>
              <th>Region</th>
              <th>Connections</th>
              <th>Assigned</th>
              <th>Last sync</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {filtered.map((brand) => (
              <tr key={brand.id}>
                <td>
                  <Link
                    to={`/brands/${brand.slug}`}
                    style={{ display: 'flex', alignItems: 'center', gap: 10 }}
                  >
                    <Avatar initials={brand.initials} />
                    <div>
                      <div style={{ fontWeight: 500 }}>{brand.name}</div>
                      {brand.shopDomain && (
                        <div className="brand-meta">{brand.shopDomain}</div>
                      )}
                    </div>
                  </Link>
                </td>
                <td>
                  {brand.region} · {brand.baseCurrency}
                </td>
                <td>
                  {brand.connectionCount ? (
                    <div style={{ display: 'flex', alignItems: 'baseline', gap: 8 }}>
                      <span style={{ fontWeight: 600 }}>{brand.connectionCount}</span>
                      <span className="muted text-sm">
                        {(brand.platforms ?? []).map((p) => PLATFORM_LABEL[p] ?? p).join(' · ')}
                      </span>
                    </div>
                  ) : (
                    <span className="muted text-sm">Not connected</span>
                  )}
                </td>
                <td>
                  {brand.assignedUsers && brand.assignedUsers.length > 0 ? (
                    <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                      {brand.assignedUsers.slice(0, 3).map((u) => (
                        <span
                          key={u.id}
                          title={u.name}
                          style={{
                            width: 24,
                            height: 24,
                            borderRadius: '50%',
                            background: 'var(--surface-subtle)',
                            border: '1px solid var(--border)',
                            display: 'inline-flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            fontSize: 10,
                            fontWeight: 500,
                            color: 'var(--text-secondary)',
                          }}
                        >
                          {u.initials}
                        </span>
                      ))}
                      {brand.assignedUsers.length > 3 && (
                        <span className="muted text-sm">+{brand.assignedUsers.length - 3}</span>
                      )}
                    </div>
                  ) : (
                    <span className="muted text-sm">—</span>
                  )}
                </td>
                <td className="muted text-sm">{timeAgo(brand.lastSyncAt)}</td>
                <td className="text-right">
                  <Link to={`/brands/${brand.slug}`} className="btn btn-ghost btn-sm">
                    Open →
                  </Link>
                </td>
              </tr>
            ))}
            {filtered.length === 0 && (
              <tr>
                <td colSpan={6} className="muted" style={{ padding: 16, textAlign: 'center' }}>
                  No brands match “{q}”.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </Card>

      <div className="text-xs muted mt-24">
        Showing {filtered.length} of {brands.length} {brands.length === 1 ? 'brand' : 'brands'}
      </div>
    </AppLayout>
  );
}
