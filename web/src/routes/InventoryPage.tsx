import { useEffect, useMemo, useRef, useState, type CSSProperties, type ReactNode } from 'react';
import { AppLayout } from '@/components/shell/AppLayout';
import { Banner } from '@/components/ui';
import { InventoryTable } from '@/components/inventory/InventoryTable';
import { cn } from '@/lib/cn';
import { useInventory } from '@/hooks/useInventory';
import { useDashboardData } from '@/hooks/useDashboardData';
import { useUsers } from '@/hooks/useApiData';
import { useCurrentUser } from '@/hooks/useSettings';
import { formatMoney, formatNumber, formatPercent, formatRoas, pctDelta } from '@/lib/formatters';
import type { DashboardRow, DashboardRowBrand } from '@/types/domain';
import type { CollectionGroup, InventoryPeriod, InventoryStatus } from '@/types/inventory';

type SortKey = 'spend' | 'units' | 'stock' | 'name' | 'status';
type StatusFilter = 'all' | InventoryStatus;

const PERIOD_LABEL: Record<InventoryPeriod, string> = {
  last7: 'Last 7 days',
  last30: 'Last 30 days',
  mtd: 'Month to date',
  custom: 'Custom',
};

const STATUS_ORDER: Record<InventoryStatus, number> = { pause: 0, alert: 1, ok: 2 };

export function InventoryPage() {
  const { data: user } = useCurrentUser();
  const canFilterByManager = user?.role === 'master_admin' || user?.role === 'manager';

  const [manager, setManager] = useState<string>('me');
  const [selectedSlug, setSelectedSlug] = useState<string | undefined>(undefined);
  const [period, setPeriod] = useState<InventoryPeriod>('last7');
  const [customFrom, setCustomFrom] = useState<string>('');
  const [customTo, setCustomTo] = useState<string>('');
  const [search, setSearch] = useState('');
  const [sort, setSort] = useState<SortKey>('spend');
  const [statusFilter, setStatusFilter] = useState<StatusFilter>('all');
  // By product (flat list) vs By collection (model-grouped, expandable) — Bosco.
  const [groupMode, setGroupMode] = useState<'product' | 'collection'>('product');

  // Brand list is the same manager-scoped set the dashboard uses — reused (and
  // cached) rather than adding a second brand endpoint. We only need name / slug
  // / initials for the switcher, so the heavier dashboard payload is incidental.
  const { data: rows = [], isLoading: brandsLoading } = useDashboardData(manager);
  const { data: managerUsers = [] } = useUsers(canFilterByManager);

  const brands: DashboardRowBrand[] = useMemo(() => {
    const seen = new Set<string>();
    const uniq: DashboardRow[] = [];
    for (const r of rows) {
      if (!seen.has(r.brand.slug)) {
        seen.add(r.brand.slug);
        uniq.push(r);
      }
    }
    // Best-performing first — same default as the main dashboard: rank by the
    // rolling revenue (Total sales) desc, brands with no revenue last, then A–Z.
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

  // Don't auto-open a brand — the page lands on a chooser (see below). We only
  // step IN here: if a chosen brand falls out of scope after a manager-filter
  // change, drop back to the chooser rather than silently showing a stranger.
  useEffect(() => {
    if (selectedSlug && brands.length > 0 && !brands.some((b) => b.slug === selectedSlug)) {
      setSelectedSlug(undefined);
    }
  }, [brands, selectedSlug]);

  const selectedBrand = brands.find((b) => b.slug === selectedSlug);

  const inv = useInventory(selectedSlug, period, customFrom, customTo, !!selectedSlug);
  const data = inv.data;

  const products = useMemo(() => {
    if (!data) return [];
    const q = search.trim().toLowerCase();
    const list = data.products.filter(
      (p) =>
        (statusFilter === 'all' || p.status === statusFilter) &&
        (q === '' || p.title.toLowerCase().includes(q) || p.handle.toLowerCase().includes(q)),
    );
    return [...list].sort((a, b) => {
      if (sort === 'name') return a.title.localeCompare(b.title);
      if (sort === 'units') return b.units - a.units;
      if (sort === 'stock') return b.stock - a.stock;
      if (sort === 'status') return STATUS_ORDER[a.status] - STATUS_ORDER[b.status] || b.spend - a.spend;
      return b.spend - a.spend;
    });
  }, [data, search, sort, statusFilter]);

  // "By collection" aggregate — group every product by its model (first word of
  // the title, e.g. all "Nayah …" → one Nayah row). Sum the metrics, blend ROAS,
  // derive status from the aggregate stock. Built from the full product set.
  const collections = useMemo<CollectionGroup[]>(() => {
    if (!data) return [];
    const map = new Map<string, CollectionGroup>();
    for (const p of data.products) {
      const first = p.title.trim().split(/\s+/)[0] || p.title.trim();
      const key = first.toLowerCase();
      let g = map.get(key);
      if (!g) {
        g = {
          key, name: first, productCount: 0, stock: 0, units: 0, unitsPrev: 0,
          deltaPct: null, spend: 0, revenue: 0, roas: null, ads: 0,
          status: 'ok', action: 'ok', products: [],
        };
        map.set(key, g);
      }
      g.products.push(p);
      g.productCount += 1;
      g.stock += p.stock;
      g.units += p.units;
      g.unitsPrev += p.unitsPrev;
      g.spend += p.spend;
      g.revenue += p.revenue;
      g.ads += p.ads;
    }
    const groups = [...map.values()];
    for (const g of groups) {
      g.roas = g.spend > 0 ? Math.round((g.revenue / g.spend) * 100) / 100 : null;
      g.deltaPct = g.unitsPrev > 0 ? Math.round(((g.units - g.unitsPrev) / g.unitsPrev) * 100) : null;
      g.status = g.stock <= 0 ? 'pause' : g.stock <= 20 ? 'alert' : 'ok';
      g.action = g.status === 'pause' ? 'out_of_stock' : g.status === 'alert' ? 'low_stock' : g.spend <= 0 ? 'no_spend' : 'ok';
      g.products.sort((a, b) => b.spend - a.spend);
    }
    return groups;
  }, [data]);

  const displayCollections = useMemo(() => {
    const q = search.trim().toLowerCase();
    const list = collections.filter(
      (g) =>
        (statusFilter === 'all' || g.status === statusFilter) &&
        (q === '' || g.name.toLowerCase().includes(q) || g.products.some((p) => p.title.toLowerCase().includes(q))),
    );
    return [...list].sort((a, b) => {
      if (sort === 'name') return a.name.localeCompare(b.name);
      if (sort === 'units') return b.units - a.units;
      if (sort === 'stock') return b.stock - a.stock;
      if (sort === 'status') return STATUS_ORDER[a.status] - STATUS_ORDER[b.status] || b.spend - a.spend;
      return b.spend - a.spend;
    });
  }, [collections, search, sort, statusFilter]);

  // No brands in scope at all — mirror the dashboard's "add a brand" dead-end
  // rather than showing an empty report shell.
  if (!brandsLoading && brands.length === 0) {
    return (
      <AppLayout title="Inventory intelligence">
        <div style={{ maxWidth: 520, margin: '12vh auto 0', textAlign: 'center', color: 'var(--text-secondary)' }}>
          <h2 style={{ marginBottom: 8 }}>No brands in scope</h2>
          <p className="lede">
            {manager === 'me'
              ? 'You have no brands assigned yet. Switch the manager filter or ask an admin to assign you a brand.'
              : 'No brands match this manager filter.'}
          </p>
        </div>
      </AppLayout>
    );
  }

  // Land on a brand chooser rather than auto-opening a store. Reads as "pick a
  // client" — white-label-friendly and scales from one store to a big roster.
  if (!selectedSlug) {
    return (
      <AppLayout title="Inventory intelligence">
        <BrandChooser
          brands={brands}
          loading={brandsLoading}
          canFilterByManager={canFilterByManager}
          manager={manager}
          setManager={setManager}
          managerUsers={managerUsers}
          onSelect={setSelectedSlug}
        />
      </AppLayout>
    );
  }

  const currency = data?.currency ?? selectedBrand?.baseCurrency ?? 'EUR';
  const money = (v: number) => formatMoney(v, currency, { whole: true });
  const s = data?.summary;
  const unitsDelta = s ? pctDelta(s.units, s.unitsPrev) : null;
  const attributedPct =
    s && s.metaSpend > 0 ? Math.round((s.attributedSpend / s.metaSpend) * 100) : null;

  return (
    <AppLayout title="Inventory intelligence" tag={data ? `${data.products.length}` : undefined}>
      <div className="page-scroll">
      {/* Filter bar — brand, manager, period, product search. */}
      <div className="filter-bar mb-12">
        <BrandPicker brands={brands} selected={selectedBrand} onSelect={setSelectedSlug} />

        {canFilterByManager && (
          <ManagerMenu manager={manager} setManager={setManager} managerUsers={managerUsers} />
        )}

        <PeriodPicker
          period={period}
          from={customFrom}
          to={customTo}
          onPick={(p) => setPeriod(p)}
          onApplyCustom={(f, t) => {
            setCustomFrom(f);
            setCustomTo(t);
            setPeriod('custom');
          }}
        />

        <div style={{ position: 'relative', display: 'inline-flex', alignItems: 'center' }}>
          <svg
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            width="15"
            height="15"
            style={{ position: 'absolute', left: 9, color: 'var(--text-muted)', pointerEvents: 'none' }}
          >
            <circle cx="11" cy="11" r="8" />
            <path d="m21 21-4.3-4.3" />
          </svg>
          <input
            className="input"
            style={{ height: 34, paddingLeft: 30, maxWidth: 240 }}
            placeholder="Search product…"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
        </div>

        <span style={{ flex: 1 }} />
        <span className="text-xs muted">
          {data
            ? `${rangeLabel(data.from, data.to)} · ${
                groupMode === 'collection'
                  ? `${collections.length} collection${collections.length === 1 ? '' : 's'}`
                  : `${data.products.length} products`
              }`
            : selectedBrand?.name}
        </span>
      </div>

      {/* View toggle + sort + status chips. */}
      <div className="filter-bar mb-12">
        <span className="text-xs muted" style={{ marginRight: 2 }}>View</span>
        <Chip active={groupMode === 'product'} onClick={() => setGroupMode('product')}>By product</Chip>
        <Chip active={groupMode === 'collection'} onClick={() => setGroupMode('collection')}>By collection</Chip>
        <span style={{ width: 14 }} />
        <span className="text-xs muted" style={{ marginRight: 2 }}>Sort</span>
        <Chip active={sort === 'spend'} onClick={() => setSort('spend')}>Spend</Chip>
        <Chip active={sort === 'units'} onClick={() => setSort('units')}>Units</Chip>
        <Chip active={sort === 'stock'} onClick={() => setSort('stock')}>Stock</Chip>
        <Chip active={sort === 'name'} onClick={() => setSort('name')}>A–Z</Chip>
        <Chip active={sort === 'status'} onClick={() => setSort('status')}>Status</Chip>
        <span style={{ flex: 1 }} />
        <Chip active={statusFilter === 'all'} onClick={() => setStatusFilter('all')}>All</Chip>
        <Chip active={statusFilter === 'ok'} onClick={() => setStatusFilter('ok')}>OK</Chip>
        <Chip active={statusFilter === 'alert'} onClick={() => setStatusFilter('alert')}>Alert</Chip>
        <Chip active={statusFilter === 'pause'} onClick={() => setStatusFilter('pause')}>Pause</Chip>
      </div>

      <div className="compare-context">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75">
          <rect x="3" y="4" width="18" height="18" rx="2" />
          <line x1="16" y1="2" x2="16" y2="6" />
          <line x1="8" y1="2" x2="8" y2="6" />
          <line x1="3" y1="10" x2="21" y2="10" />
        </svg>
        <span>
          {selectedBrand?.name ?? 'Brand'} — revenue is <strong>Total sales + refunds</strong> (before returns), Online
          Store only. Spend &amp; ROAS are <strong>Meta only</strong>, blended (all-orders revenue ÷ Meta spend). Window
          ends yesterday (today excluded), in the brand&rsquo;s timezone.
        </span>
      </div>

      {groupMode === 'collection' && (
        <div
          className="text-xs muted"
          style={{ marginTop: -6, marginBottom: 14, display: 'flex', alignItems: 'flex-start', gap: 6 }}
        >
          <svg
            width="13"
            height="13"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.8"
            style={{ flex: '0 0 auto', marginTop: 1 }}
          >
            <circle cx="12" cy="12" r="10" />
            <path d="M12 16v-4M12 8h.01" />
          </svg>
          <span>
            Collections group products by <strong>model name</strong> — the first word of the product title (e.g. every
            &ldquo;Nayah …&rdquo; product rolls into &ldquo;Nayah&rdquo;). These are Helm groupings, <strong>not</strong>{' '}
            Shopify collections.
          </span>
        </div>
      )}

      {inv.isError ? (
        <StateCard>Couldn&rsquo;t load inventory for this brand. Try refreshing, or check the brand is synced.</StateCard>
      ) : !data && inv.isLoading ? (
        <StateCard>Loading inventory…</StateCard>
      ) : data ? (
        <>
          {data.unattributed.total > 0 && (
            <div style={{ marginBottom: 16 }}>
              <Banner
                variant="info"
                icon={
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8">
                    <circle cx="12" cy="12" r="10" />
                    <path d="M12 16v-4M12 8h.01" />
                  </svg>
                }
              >
                <strong>{money(data.unattributed.total)}</strong> of Meta spend isn&rsquo;t attributed to a single product
                (dynamic / Advantage+ catalog, home and collection ads) — counted in the totals and shown here, not split
                across the rows below.{attributedPct !== null ? ` ~${attributedPct}% of spend is attributed.` : ''}
              </Banner>
            </div>
          )}

          <div style={cardsGrid}>
            <SummaryCard label="Products" value={formatNumber(data.summary.products)} hint="in scope" />
            <SummaryCard label="Pause" value={formatNumber(data.summary.pause)} hint="stock ≤ 0" tone="r" />
            <SummaryCard label="Alert" value={formatNumber(data.summary.alert)} hint="stock ≤ 20" tone="a" />
            <SummaryCard label="OK" value={formatNumber(data.summary.ok)} hint="stock > 20" tone="g" />
            <SummaryCard label="Net stock" value={formatNumber(data.summary.netStock)} hint="units (incl. negatives)" />
            <SummaryCard
              label="Units"
              value={formatNumber(data.summary.units)}
              hint={
                unitsDelta === null ? (
                  <span className="muted">vs {formatNumber(data.summary.unitsPrev)} prev</span>
                ) : (
                  <>
                    <span style={{ color: unitsDelta >= 0 ? 'var(--success)' : 'var(--danger)' }}>
                      {formatPercent(unitsDelta, { signed: true, decimals: 0 })}
                    </span>{' '}
                    vs {formatNumber(data.summary.unitsPrev)}
                  </>
                )
              }
            />
            <SummaryCard label="Meta spend" value={money(data.summary.metaSpend)} hint="attributed + unattributed" />
            <SummaryCard label="Revenue" value={money(data.summary.revenue)} hint="before returns · Online Store" />
            <SummaryCard
              label="Blended ROAS"
              value={data.summary.roas != null ? formatRoas(data.summary.roas) : '—'}
              hint="revenue ÷ Meta spend"
              tone={data.summary.roas != null && data.summary.roas >= 3 ? 'g' : undefined}
            />
          </div>

          <div className="table-region">
          {groupMode === 'collection' ? (
            displayCollections.length > 0 ? (
              <InventoryTable mode="collection" collections={displayCollections} currency={currency} />
            ) : (
              <StateCard>
                {collections.length === 0
                  ? 'No products in the catalog for this brand yet. Run shopify:sync-catalog for it.'
                  : 'No collections match your filters.'}
              </StateCard>
            )
          ) : products.length > 0 ? (
            <InventoryTable mode="product" products={products} currency={currency} />
          ) : (
            <StateCard>
              {data.products.length === 0
                ? 'No products in the catalog for this brand yet. Run shopify:sync-catalog for it.'
                : 'No products match your filters.'}
            </StateCard>
          )}
          </div>

          <div className="text-xs muted" style={{ marginTop: 14, display: 'flex', alignItems: 'center', gap: 6 }}>
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8">
              <rect x="3" y="4" width="18" height="18" rx="2" />
              <path d="M16 2v4M8 2v4M3 10h18" />
            </svg>
            Rows with no attributed Meta spend show ROAS as “—”. Blended ROAS in the cards uses total Meta spend
            (attributed + unattributed); the product column sums to attributed spend only.
          </div>
        </>
      ) : null}
      </div>
    </AppLayout>
  );
}

/* ---- Brand chooser (landing) ----------------------------------------- */

function BrandChooser({
  brands,
  loading,
  canFilterByManager,
  manager,
  setManager,
  managerUsers,
  onSelect,
}: {
  brands: DashboardRowBrand[];
  loading: boolean;
  canFilterByManager: boolean;
  manager: string;
  setManager: (m: string) => void;
  managerUsers: Array<{ id: number; name: string; status: string }>;
  onSelect: (slug: string) => void;
}) {
  const [q, setQ] = useState('');
  const filtered = brands.filter((b) => b.name.toLowerCase().includes(q.trim().toLowerCase()));
  return (
    <div style={{ maxWidth: 560, margin: '7vh auto 0' }}>
      <h2 style={{ textAlign: 'center', marginBottom: 6 }}>Choose a brand</h2>
      <p className="lede" style={{ textAlign: 'center', margin: '0 auto 22px', maxWidth: 440 }}>
        Open a store to see its stock, Meta spend and blended ROAS by product.
      </p>

      {canFilterByManager && (
        <div className="filter-bar" style={{ justifyContent: 'center', marginBottom: 12 }}>
          <ManagerMenu manager={manager} setManager={setManager} managerUsers={managerUsers} />
        </div>
      )}

      <div style={{ position: 'relative' }}>
        <svg
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          strokeWidth="2"
          width="16"
          height="16"
          style={{ position: 'absolute', left: 12, top: 12, color: 'var(--text-muted)', pointerEvents: 'none' }}
        >
          <circle cx="11" cy="11" r="8" />
          <path d="m21 21-4.3-4.3" />
        </svg>
        <input
          autoFocus
          className="input"
          style={{ height: 40, paddingLeft: 36, width: '100%' }}
          placeholder="Search brands…"
          value={q}
          onChange={(e) => setQ(e.target.value)}
        />
      </div>

      <div
        style={{
          marginTop: 10,
          background: 'var(--surface)',
          border: '1px solid var(--border)',
          borderRadius: 'var(--radius-lg)',
          overflow: 'hidden',
        }}
      >
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
                  display: 'flex',
                  alignItems: 'center',
                  gap: 11,
                  width: '100%',
                  textAlign: 'left',
                  padding: '11px 14px',
                  border: 0,
                  borderTop: i === 0 ? 'none' : '1px solid var(--border)',
                  background: 'transparent',
                  font: 'inherit',
                  cursor: 'pointer',
                  color: 'var(--text)',
                }}
                onMouseEnter={(e) => (e.currentTarget.style.background = 'var(--surface-subtle)')}
                onMouseLeave={(e) => (e.currentTarget.style.background = 'transparent')}
              >
                <InitialsBadge initials={b.initials} filled />
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ fontWeight: 500 }}>{b.name}</div>
                  <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>
                    {[b.region, b.baseCurrency].filter(Boolean).join(' · ')}
                  </div>
                </div>
                <svg
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth="2"
                  width="15"
                  height="15"
                  style={{ color: 'var(--text-muted)', flex: '0 0 auto' }}
                >
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

/* ---- Manager filter (shared by the bar + the chooser) ---------------- */

function ManagerMenu({
  manager,
  setManager,
  managerUsers,
}: {
  manager: string;
  setManager: (m: string) => void;
  managerUsers: Array<{ id: number; name: string; status: string }>;
}) {
  const label =
    manager === 'me'
      ? 'My brands'
      : manager === 'all'
        ? 'All brands'
        : manager === 'unassigned'
          ? 'No user assigned'
          : managerUsers.find((u) => String(u.id) === manager)?.name ?? 'Manager';
  const active = managerUsers.filter((u) => u.status === 'active');
  return (
    <Menu label={<>Manager: <strong style={{ fontWeight: 500 }}>{label}</strong></>} width={220}>
      {(close) => (
        <>
          <MenuLabel>Brand manager</MenuLabel>
          <Item selected={manager === 'me'} onClick={() => { setManager('me'); close(); }}>My brands</Item>
          <Item selected={manager === 'all'} onClick={() => { setManager('all'); close(); }}>All brands</Item>
          <Item selected={manager === 'unassigned'} onClick={() => { setManager('unassigned'); close(); }}>
            No user assigned
          </Item>
          {active.length > 0 && (
            <>
              <MenuDivider />
              <MenuLabel>By user</MenuLabel>
              {active.map((u) => (
                <Item key={u.id} selected={manager === String(u.id)} onClick={() => { setManager(String(u.id)); close(); }}>
                  {u.name}
                </Item>
              ))}
            </>
          )}
        </>
      )}
    </Menu>
  );
}

/* ---- Summary cards --------------------------------------------------- */

const cardsGrid: CSSProperties = {
  display: 'grid',
  gridTemplateColumns: 'repeat(auto-fit, minmax(122px, 1fr))',
  gap: 1,
  background: 'var(--border)',
  border: '1px solid var(--border)',
  borderRadius: 'var(--radius-lg)',
  overflow: 'hidden',
  marginBottom: 20,
};

function SummaryCard({
  label,
  value,
  hint,
  tone,
}: {
  label: string;
  value: ReactNode;
  hint: ReactNode;
  tone?: 'g' | 'a' | 'r';
}) {
  const color = tone === 'g' ? 'var(--success)' : tone === 'a' ? 'var(--warning)' : tone === 'r' ? 'var(--danger)' : undefined;
  return (
    <div style={{ background: 'var(--surface)', padding: '12px 14px' }}>
      <div style={{ fontSize: 11, textTransform: 'uppercase', letterSpacing: '.05em', color: 'var(--text-muted)', fontWeight: 500 }}>
        {label}
      </div>
      <div style={{ fontSize: 20, fontWeight: 600, marginTop: 5, fontVariantNumeric: 'tabular-nums', color }}>{value}</div>
      <div style={{ fontSize: 11, color: 'var(--text-muted)', marginTop: 2 }}>{hint}</div>
    </div>
  );
}

function StateCard({ children }: { children: ReactNode }) {
  return (
    <div
      style={{
        background: 'var(--surface)',
        border: '1px solid var(--border)',
        borderRadius: 'var(--radius-lg)',
        padding: 48,
        textAlign: 'center',
        color: 'var(--text-muted)',
        fontSize: 14,
      }}
    >
      {children}
    </div>
  );
}

/* ---- Chips ----------------------------------------------------------- */

function Chip({ active, onClick, children }: { active?: boolean; onClick?: () => void; children: ReactNode }) {
  return (
    <button className={cn('chip', active && 'active')} onClick={onClick} type="button">
      {children}
    </button>
  );
}

/* ---- Filter menus (click-outside, close-on-select) ------------------- */

function Menu({
  label,
  width,
  children,
}: {
  label: ReactNode;
  width?: number;
  children: (close: () => void) => ReactNode;
}) {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);
  useEffect(() => {
    if (!open) return;
    const onDown = (e: MouseEvent) => {
      if (!ref.current?.contains(e.target as Node)) setOpen(false);
    };
    const onEsc = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setOpen(false);
    };
    window.addEventListener('mousedown', onDown);
    window.addEventListener('keydown', onEsc);
    return () => {
      window.removeEventListener('mousedown', onDown);
      window.removeEventListener('keydown', onEsc);
    };
  }, [open]);

  return (
    <div ref={ref} style={{ position: 'relative' }}>
      <button className="filter-btn" type="button" onClick={() => setOpen((v) => !v)}>
        {label}
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14">
          <polyline points="6 9 12 15 18 9" />
        </svg>
      </button>
      {open && (
        <div
          style={{
            position: 'absolute',
            zIndex: 60,
            top: 'calc(100% + 6px)',
            left: 0,
            background: 'var(--surface)',
            border: '1px solid var(--border-strong, #D6D3D1)',
            borderRadius: 'var(--radius-lg)',
            boxShadow: '0 6px 20px rgba(12,10,9,.09)',
            minWidth: width ?? 220,
            padding: 6,
          }}
        >
          {children(() => setOpen(false))}
        </div>
      )}
    </div>
  );
}

function Item({
  selected,
  meta,
  onClick,
  children,
}: {
  selected?: boolean;
  meta?: ReactNode;
  onClick?: () => void;
  children: ReactNode;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      style={{
        display: 'flex',
        alignItems: 'center',
        gap: 9,
        width: '100%',
        textAlign: 'left',
        padding: '7px 9px',
        borderRadius: 'var(--radius)',
        border: 0,
        background: selected ? 'var(--surface-subtle)' : 'transparent',
        font: 'inherit',
        fontSize: 13,
        color: 'var(--text)',
        cursor: 'pointer',
        fontWeight: selected ? 500 : 400,
      }}
      onMouseEnter={(e) => {
        if (!selected) e.currentTarget.style.background = 'var(--surface-subtle)';
      }}
      onMouseLeave={(e) => {
        if (!selected) e.currentTarget.style.background = 'transparent';
      }}
    >
      {children}
      {meta != null && <span style={{ marginLeft: 'auto', color: 'var(--text-muted)', fontSize: 12 }}>{meta}</span>}
    </button>
  );
}

function MenuLabel({ children }: { children: ReactNode }) {
  return (
    <div style={{ fontSize: 11, textTransform: 'uppercase', letterSpacing: '.05em', color: 'var(--text-muted)', padding: '6px 9px 3px' }}>
      {children}
    </div>
  );
}

function MenuDivider() {
  return <div style={{ height: 1, background: 'var(--border)', margin: '4px 0' }} />;
}

function InitialsBadge({ initials, filled }: { initials: string; filled?: boolean }) {
  return (
    <span
      style={{
        width: 22,
        height: 22,
        borderRadius: '50%',
        background: filled ? 'var(--accent)' : 'var(--surface-subtle)',
        color: filled ? 'var(--accent-fg)' : 'var(--text-secondary)',
        border: filled ? 'none' : '1px solid var(--border)',
        display: 'inline-flex',
        alignItems: 'center',
        justifyContent: 'center',
        fontSize: 10,
        fontWeight: 500,
        flex: '0 0 auto',
      }}
    >
      {initials}
    </span>
  );
}

function BrandPicker({
  brands,
  selected,
  onSelect,
}: {
  brands: DashboardRowBrand[];
  selected: DashboardRowBrand | undefined;
  onSelect: (slug: string) => void;
}) {
  return (
    <Menu
      width={260}
      label={
        <span style={{ display: 'inline-flex', alignItems: 'center', gap: 7 }}>
          <InitialsBadge initials={selected?.initials ?? '—'} filled />
          <strong style={{ fontWeight: 500 }}>{selected?.name ?? 'Select brand'}</strong>
        </span>
      }
    >
      {(close) => <BrandMenuBody brands={brands} selectedSlug={selected?.slug} onSelect={(slug) => { onSelect(slug); close(); }} />}
    </Menu>
  );
}

function BrandMenuBody({
  brands,
  selectedSlug,
  onSelect,
}: {
  brands: DashboardRowBrand[];
  selectedSlug: string | undefined;
  onSelect: (slug: string) => void;
}) {
  const [q, setQ] = useState('');
  const filtered = brands.filter((b) => b.name.toLowerCase().includes(q.trim().toLowerCase()));
  return (
    <>
      <input
        autoFocus
        value={q}
        onChange={(e) => setQ(e.target.value)}
        placeholder="Search brand…"
        style={{
          width: '100%',
          height: 32,
          border: '1px solid var(--border)',
          borderRadius: 'var(--radius)',
          padding: '0 9px',
          font: 'inherit',
          fontSize: 13,
          marginBottom: 4,
          color: 'var(--text)',
        }}
      />
      <div style={{ maxHeight: 300, overflow: 'auto' }}>
        {filtered.length === 0 ? (
          <div style={{ padding: '8px 9px', fontSize: 13, color: 'var(--text-muted)' }}>No brands</div>
        ) : (
          filtered.map((b) => (
            <Item key={b.slug} selected={b.slug === selectedSlug} onClick={() => onSelect(b.slug)}>
              <InitialsBadge initials={b.initials} />
              {b.name}
            </Item>
          ))
        )}
      </div>
    </>
  );
}

function PeriodPicker({
  period,
  from,
  to,
  onPick,
  onApplyCustom,
}: {
  period: InventoryPeriod;
  from: string;
  to: string;
  onPick: (p: InventoryPeriod) => void;
  onApplyCustom: (from: string, to: string) => void;
}) {
  return (
    <Menu
      width={264}
      label={<>Period: <strong style={{ fontWeight: 500 }}>{PERIOD_LABEL[period]}</strong></>}
    >
      {(close) => (
        <PeriodMenuBody
          period={period}
          from={from}
          to={to}
          onPick={(p) => { onPick(p); close(); }}
          onApplyCustom={(f, t) => { onApplyCustom(f, t); close(); }}
        />
      )}
    </Menu>
  );
}

function PeriodMenuBody({
  period,
  from,
  to,
  onPick,
  onApplyCustom,
}: {
  period: InventoryPeriod;
  from: string;
  to: string;
  onPick: (p: InventoryPeriod) => void;
  onApplyCustom: (from: string, to: string) => void;
}) {
  const [showCustom, setShowCustom] = useState(period === 'custom');
  const [f, setF] = useState(from);
  const [t, setT] = useState(to);
  return (
    <>
      <MenuLabel>Period · ends yesterday</MenuLabel>
      {(['last7', 'last30', 'mtd'] as const).map((p) => (
        <Item key={p} selected={period === p} meta={rangeShort(p)} onClick={() => onPick(p)}>
          {PERIOD_LABEL[p]}
        </Item>
      ))}
      <Item selected={period === 'custom'} onClick={() => setShowCustom(true)}>Custom range…</Item>
      {showCustom && (
        <div style={{ display: 'flex', gap: 6, alignItems: 'center', padding: '8px 6px 4px', flexWrap: 'wrap' }}>
          <input type="date" value={f} onChange={(e) => setF(e.target.value)} style={dateInput} />
          <span className="muted">→</span>
          <input type="date" value={t} onChange={(e) => setT(e.target.value)} style={dateInput} />
          <button
            type="button"
            className="chip"
            style={{ height: 30 }}
            onClick={() => {
              if (f && t) onApplyCustom(f, t);
            }}
          >
            Apply
          </button>
        </div>
      )}
    </>
  );
}

const dateInput: CSSProperties = {
  height: 30,
  border: '1px solid var(--border)',
  borderRadius: 'var(--radius)',
  padding: '0 8px',
  font: 'inherit',
  fontSize: 12.5,
  color: 'var(--text)',
};

/* ---- Date helpers ---------------------------------------------------- */

// Client-side window (ends yesterday) for the period dropdown hints. Mirrors the
// backend InventoryQuery::window; each brand's real window is in its own tz, so
// this is a display approximation in the viewer's local date.
function inventoryWindow(period: Exclude<InventoryPeriod, 'custom'>): { from: Date; to: Date } {
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const to = new Date(today);
  to.setDate(today.getDate() - 1);
  let from: Date;
  if (period === 'last7') {
    from = new Date(today);
    from.setDate(today.getDate() - 7);
  } else if (period === 'last30') {
    from = new Date(today);
    from.setDate(today.getDate() - 30);
  } else {
    from = new Date(today.getFullYear(), today.getMonth(), 1);
  }
  if (from > to) from = new Date(to);
  return { from, to };
}

function rangeShort(period: Exclude<InventoryPeriod, 'custom'>): string {
  const { from, to } = inventoryWindow(period);
  return formatRange(from, to);
}

// "23–29 Jun" (same month) or "31 May – 29 Jun".
function formatRange(from: Date, to: Date): string {
  const sameMonth = from.getMonth() === to.getMonth() && from.getFullYear() === to.getFullYear();
  const d = (x: Date) => x.toLocaleDateString('en-GB', { day: 'numeric' });
  const dm = (x: Date) => x.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
  return sameMonth ? `${d(from)}–${dm(to)}` : `${dm(from)} – ${dm(to)}`;
}

// Parse the API's Y-m-d window (brand tz) into a display range.
function rangeLabel(fromStr: string, toStr: string): string {
  const parse = (s: string) => {
    const [y, m, d] = s.split('-').map(Number);
    return new Date(y, (m ?? 1) - 1, d ?? 1);
  };
  return formatRange(parse(fromStr), parse(toStr));
}
