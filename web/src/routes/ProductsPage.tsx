import { type ReactNode, useEffect, useMemo, useRef, useState } from 'react';
import { AppLayout } from '@/components/shell/AppLayout';
import { cn } from '@/lib/cn';
import { BrandProductsView } from '@/components/products/BrandProductsView';
import { useDashboardData } from '@/hooks/useDashboardData';
import { useCurrentUser } from '@/hooks/useSettings';
import { useUsers } from '@/hooks/useApiData';
import type { DashboardRow, DashboardRowBrand } from '@/types/domain';

/**
 * Products hub — a top-level page (like Inventory and Ads) that lands on a brand
 * chooser and, once a brand is picked, shows its product performance (revenue,
 * ABC, cover, mapped ad spend + ROAS) inline. Master admin / manager get the
 * brand-manager (team) filter to scope the list; everyone else sees their brands.
 * White-label friendly: reads as "pick a client".
 */
export function ProductsPage() {
  const { data: user } = useCurrentUser();
  const canFilterByManager = user?.role === 'master_admin' || user?.role === 'manager';

  const [manager, setManager] = useState<string>('me');
  const [selectedSlug, setSelectedSlug] = useState<string | undefined>(undefined);

  const { data: rows = [], isLoading } = useDashboardData(manager);
  const { data: managerUsers = [] } = useUsers(canFilterByManager);

  const brands = useMemo<DashboardRowBrand[]>(() => {
    const seen = new Set<string>();
    const uniq: DashboardRow[] = [];
    for (const r of rows) {
      if (!seen.has(r.brand.slug)) {
        seen.add(r.brand.slug);
        uniq.push(r);
      }
    }
    // Best-performing first — same default as the dashboard / inventory / ads.
    uniq.sort((a, b) => {
      const av = a.rolling.totalSales;
      const bv = b.rolling.totalSales;
      const aDead = av == null || av === 0;
      const bDead = bv == null || bv === 0;
      if (aDead !== bDead) return aDead ? 1 : -1;
      if (!aDead && !bDead && av !== bv) return (bv as number) - (av as number);
      return a.brand.name.localeCompare(b.brand.name);
    });
    return uniq.map((r) => r.brand);
  }, [rows]);

  // Drop back to the chooser if a selected brand leaves scope (manager filter change).
  useEffect(() => {
    if (selectedSlug && brands.length > 0 && !brands.some((b) => b.slug === selectedSlug)) {
      setSelectedSlug(undefined);
    }
  }, [brands, selectedSlug]);

  const selected = brands.find((b) => b.slug === selectedSlug);

  if (!selectedSlug) {
    return (
      <AppLayout title="Products">
        <Chooser
          brands={brands}
          loading={isLoading}
          onSelect={setSelectedSlug}
          managerFilter={canFilterByManager ? <ManagerMenu manager={manager} setManager={setManager} managerUsers={managerUsers} /> : null}
        />
      </AppLayout>
    );
  }

  return (
    <AppLayout title="Products">
      <div className="filter-bar mb-16">
        <BrandSwitcher brands={brands} selected={selected} onSelect={setSelectedSlug} />
        {canFilterByManager && <ManagerMenu manager={manager} setManager={setManager} managerUsers={managerUsers} />}
      </div>

      <BrandProductsView slug={selectedSlug} />
    </AppLayout>
  );
}

/** Brand-manager filter (master admin / manager only) — scopes the brand list. */
function ManagerMenu({
  manager,
  setManager,
  managerUsers,
}: {
  manager: string;
  setManager: (m: string) => void;
  managerUsers: Array<{ id: number; name: string; status: string }>;
}) {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;
    const h = (e: MouseEvent) => { if (!ref.current?.contains(e.target as Node)) setOpen(false); };
    window.addEventListener('mousedown', h);
    return () => window.removeEventListener('mousedown', h);
  }, [open]);

  const active = managerUsers.filter((u) => u.status === 'active');
  const label =
    manager === 'me' ? 'My brands'
    : manager === 'all' ? 'All brands'
    : manager === 'unassigned' ? 'No user assigned'
    : active.find((u) => String(u.id) === manager)?.name ?? 'Manager';
  const pick = (m: string) => { setManager(m); setOpen(false); };

  return (
    <div className={cn('dropdown', open && 'open')} ref={ref} style={{ display: 'inline-block' }}>
      <button type="button" className="filter-btn" onClick={() => setOpen((v) => !v)}>
        Manager: <strong style={{ fontWeight: 500 }}>{label}</strong>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="13" height="13" style={{ color: 'var(--text-muted)', marginLeft: 4 }}>
          <polyline points="6 9 12 15 18 9" />
        </svg>
      </button>
      <div className="dropdown-menu down" style={{ minWidth: 216, padding: 6 }}>
        <div className="dropdown-label">Brand manager</div>
        <MItem on={manager === 'me'} onClick={() => pick('me')}>My brands</MItem>
        <MItem on={manager === 'all'} onClick={() => pick('all')}>All brands</MItem>
        <MItem on={manager === 'unassigned'} onClick={() => pick('unassigned')}>No user assigned</MItem>
        {active.length > 0 && (
          <>
            <div className="dropdown-divider" />
            <div className="dropdown-label">By user</div>
            {active.map((u) => (
              <MItem key={u.id} on={manager === String(u.id)} onClick={() => pick(String(u.id))}>{u.name}</MItem>
            ))}
          </>
        )}
      </div>
    </div>
  );
}

function MItem({ on, onClick, children }: { on: boolean; onClick: () => void; children: ReactNode }) {
  return (
    <button type="button" className="dropdown-item" onClick={onClick}>
      <span style={{ flex: 1 }}>{children}</span>
      {on && (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" width="14" height="14" style={{ color: 'var(--text)' }}>
          <polyline points="20 6 9 17 4 12" />
        </svg>
      )}
    </button>
  );
}

/**
 * Inline searchable brand switcher for the filter bar — swaps the brand in place
 * instead of dropping back to the chooser (mirrors Inventory / Ads).
 */
function BrandSwitcher({
  brands,
  selected,
  onSelect,
}: {
  brands: DashboardRowBrand[];
  selected: DashboardRowBrand | undefined;
  onSelect: (slug: string) => void;
}) {
  const [open, setOpen] = useState(false);
  const [q, setQ] = useState('');
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;
    const h = (e: MouseEvent) => { if (!ref.current?.contains(e.target as Node)) setOpen(false); };
    window.addEventListener('mousedown', h);
    return () => window.removeEventListener('mousedown', h);
  }, [open]);

  const filtered = brands.filter((b) => b.name.toLowerCase().includes(q.trim().toLowerCase()));

  return (
    <div className={cn('dropdown', open && 'open')} ref={ref} style={{ display: 'inline-block' }}>
      <button type="button" className="filter-btn" onClick={() => setOpen((v) => !v)}>
        <span className="brand-avatar" style={{ width: 20, height: 20, fontSize: 9 }}>{selected?.initials ?? '—'}</span>
        <strong style={{ fontWeight: 500 }}>{selected?.name ?? 'Brand'}</strong>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="13" height="13" style={{ color: 'var(--text-muted)', marginLeft: 4 }}>
          <polyline points="6 9 12 15 18 9" />
        </svg>
      </button>
      <div className="dropdown-menu down" style={{ minWidth: 264, padding: 6 }}>
        <input
          autoFocus
          className="input"
          style={{ height: 32, width: '100%', marginBottom: 4 }}
          placeholder="Search brand…"
          value={q}
          onChange={(e) => setQ(e.target.value)}
        />
        <div style={{ maxHeight: 300, overflow: 'auto' }}>
          {filtered.length === 0 ? (
            <div className="dropdown-item" style={{ color: 'var(--text-muted)', cursor: 'default' }}>No brands</div>
          ) : (
            filtered.map((b) => (
              <button
                key={b.slug}
                type="button"
                className={cn('dropdown-item', b.slug === selected?.slug && 'is-selected')}
                onClick={() => { onSelect(b.slug); setOpen(false); setQ(''); }}
              >
                <span className="brand-avatar" style={{ width: 20, height: 20, fontSize: 9 }}>{b.initials}</span>
                <span style={{ flex: 1, minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis' }}>{b.name}</span>
                {b.slug === selected?.slug && (
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" width="14" height="14" style={{ color: 'var(--text)' }}>
                    <polyline points="20 6 9 17 4 12" />
                  </svg>
                )}
              </button>
            ))
          )}
        </div>
      </div>
    </div>
  );
}

function Chooser({
  brands,
  loading,
  onSelect,
  managerFilter,
}: {
  brands: DashboardRowBrand[];
  loading: boolean;
  onSelect: (slug: string) => void;
  managerFilter?: ReactNode;
}) {
  const [q, setQ] = useState('');
  const filtered = brands.filter((b) => b.name.toLowerCase().includes(q.trim().toLowerCase()));

  return (
    <div style={{ maxWidth: 560, margin: '7vh auto 0' }}>
      <h2 style={{ textAlign: 'center', marginBottom: 6 }}>Choose a brand</h2>
      <p className="lede" style={{ textAlign: 'center', margin: '0 auto 18px', maxWidth: 440 }}>
        Open a store to see its product performance — revenue, ABC grade, stock cover, mapped ad spend and ROAS.
      </p>

      {managerFilter && <div style={{ display: 'flex', justifyContent: 'center', marginBottom: 14 }}>{managerFilter}</div>}

      <div style={{ position: 'relative' }}>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="16" height="16" style={{ position: 'absolute', left: 12, top: 12, color: 'var(--text-muted)', pointerEvents: 'none' }}>
          <circle cx="11" cy="11" r="8" />
          <path d="m21 21-4.3-4.3" />
        </svg>
        <input autoFocus className="input" style={{ height: 40, paddingLeft: 36, width: '100%' }} placeholder="Search brands…" value={q} onChange={(e) => setQ(e.target.value)} />
      </div>

      <div style={{ marginTop: 10, background: 'var(--surface)', border: '1px solid var(--border)', borderRadius: 'var(--radius-lg)', overflow: 'hidden' }}>
        {loading ? (
          <div style={{ padding: 24, textAlign: 'center', color: 'var(--text-muted)', fontSize: 14 }}>Loading brands…</div>
        ) : filtered.length === 0 ? (
          <div style={{ padding: 24, textAlign: 'center', color: 'var(--text-muted)', fontSize: 14 }}>No brands match.</div>
        ) : (
          <div style={{ maxHeight: 420, overflow: 'auto' }}>
            {filtered.map((b, i) => (
              <button
                key={b.slug}
                type="button"
                onClick={() => onSelect(b.slug)}
                style={{
                  display: 'flex', alignItems: 'center', gap: 11, width: '100%', textAlign: 'left',
                  padding: '11px 14px', border: 0, borderTop: i === 0 ? 'none' : '1px solid var(--border)',
                  background: 'transparent', font: 'inherit', cursor: 'pointer', color: 'var(--text)',
                }}
                onMouseEnter={(e) => (e.currentTarget.style.background = 'var(--surface-subtle)')}
                onMouseLeave={(e) => (e.currentTarget.style.background = 'transparent')}
              >
                <span className="brand-avatar" style={{ width: 22, height: 22, fontSize: 10 }}>{b.initials}</span>
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ fontWeight: 500 }}>{b.name}</div>
                  <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>{[b.region, b.baseCurrency].filter(Boolean).join(' · ')}</div>
                </div>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15" style={{ color: 'var(--text-muted)', flex: '0 0 auto' }}>
                  <path d="m9 18 6-6-6-6" />
                </svg>
              </button>
            ))}
          </div>
        )}
      </div>

      {!loading && (
        <div className="text-xs muted" style={{ textAlign: 'center', marginTop: 12 }}>
          {filtered.length} of {brands.length} {brands.length === 1 ? 'brand' : 'brands'}
        </div>
      )}
    </div>
  );
}
