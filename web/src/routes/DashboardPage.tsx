import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { AppLayout } from '@/components/shell/AppLayout';
import { BrandsTableWide } from '@/components/dashboard/BrandsTableWide';
import { AudienceTable } from '@/components/dashboard/AudienceTable';
import {
  Banner,
  Button,
  Card,
  Popover,
  PopoverDivider,
  PopoverItem,
  PopoverLabel,
  Segmented,
} from '@/components/ui';
import { useAudienceData, useDashboardData, useMasterSync } from '@/hooks/useDashboardData';
import { useUsers } from '@/hooks/useApiData';
import { useCurrentUser } from '@/hooks/useSettings';
import { useFiltersStore } from '@/stores/filtersStore';
import { useUiStore } from '@/stores/uiStore';
import type {
  AudienceBreakdown,
  AudiencePeriod,
  DashboardRow,
  Platform,
} from '@/types/domain';

const BREAKDOWN_OPTIONS: { key: AudienceBreakdown; label: string }[] = [
  { key: 'audience', label: 'Audience segments' },
  // Placement defaults to platform-level (Facebook / Instagram / Audience
  // Network / Messenger) — ~4 buckets that sum to ~100%. "Placement detail" is
  // the granular position split, which is long-tailed (most spend in "Other").
  { key: 'placement_platform', label: 'Placement' },
  { key: 'placement', label: 'Placement detail' },
  { key: 'age_gender', label: 'Age & gender' },
  // Region rolls countries up into ~6 buckets (reconciles, small Other); Country
  // is the precise per-country split (long-tailed). Region first, then detail.
  { key: 'region', label: 'Region' },
  { key: 'country', label: 'Country' },
  { key: 'device', label: 'Device' },
];

const AUDIENCE_PERIODS: { key: AudiencePeriod; label: string }[] = [
  { key: 'last7', label: 'Last 7 days' },
  { key: 'last30', label: 'Last 30 days' },
  { key: 'mtd', label: 'Month to date' },
];

// Rolling comparison intervals for the Performance table's far-right block.
// Default 30 (last month). Each is "last N days vs the prior N days".
const ROLLING_WINDOWS: { days: number; label: string }[] = [
  { days: 7, label: 'Last 7 days' },
  { days: 30, label: 'Last 30 days' },
  { days: 90, label: 'Last 90 days' },
];

// Resolve a period to its actual [from, to] window. Mirrors the backend
// (AudienceQuery::periodWindow): the window ENDS YESTERDAY — today is partial and
// always excluded. Computed in the viewer's local date as a display hint; each
// brand's real window is in its own timezone (noted in the context line).
function audienceWindow(period: AudiencePeriod): { from: Date; to: Date } {
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const to = new Date(today);
  to.setDate(today.getDate() - 1); // yesterday

  let from: Date;
  if (period === 'last7') {
    from = new Date(today);
    from.setDate(today.getDate() - 7);
  } else if (period === 'last30') {
    from = new Date(today);
    from.setDate(today.getDate() - 30);
  } else {
    from = new Date(today.getFullYear(), today.getMonth(), 1); // month to date
  }
  if (from > to) from = new Date(to); // 1st-of-month edge: window collapses to yesterday
  return { from, to };
}

// "23–29 Jun" (same month) or "31 May – 29 Jun" — compact, for the dropdown.
function audienceRangeShort(period: AudiencePeriod): string {
  const { from, to } = audienceWindow(period);
  const sameMonth = from.getMonth() === to.getMonth() && from.getFullYear() === to.getFullYear();
  const d = (x: Date) => x.toLocaleDateString('en-GB', { day: 'numeric' });
  const dm = (x: Date) => x.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
  return sameMonth ? `${d(from)}–${dm(to)}` : `${dm(from)} – ${dm(to)}`;
}

// "23 Jun – 29 Jun 2026" — full, for the context line.
function audienceRangeFull(period: AudiencePeriod): string {
  const { from, to } = audienceWindow(period);
  const dmy = (x: Date) => x.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
  return `${dmy(from)} – ${dmy(to)}`;
}

const COMPARE_PERIODS = [
  { key: 'yesterday', label: 'Yesterday' },
  { key: 'last7', label: 'Last 7 days' },
  { key: 'last30', label: 'Last 30 days' },
  // Last full calendar month vs the same month last year (e.g. June 2026 vs
  // June 2025) — Bosco, 2026-07-03.
  { key: 'lastmonth', label: 'Last month' },
  { key: 'mtd', label: 'Month to date' },
] as const;

export function DashboardPage() {
  const { data: user } = useCurrentUser();
  const canFilterByManager = user?.role === 'master_admin' || user?.role === 'manager';
  // Brand-manager filter: 'me' (default — the signed-in user's assigned brands)
  // | 'all' | a user id. Admin/manager only; limited roles are hard-scoped
  // server-side via the Brand global access scope.
  const [manager, setManager] = useState<string>('me');
  // Revenue metric. Net sales is hidden per the client (Bosco, 2026-06-19): only
  // Total revenue is shown, so the metric toggle below is commented out and the
  // metric is pinned to 'total'. Re-enable: restore `setMetric` here and
  // uncomment the <Segmented> in the filter bar.
  const [metric] = useState<'net' | 'total'>('total');
  // Year-over-year comparison: a toggle + multi-select periods. The comparison
  // columns follow the metric toggle above; periods are only sent to the API
  // (and computed) while the toggle is on.
  const [comparisonOn, setComparisonOn] = useState(false);
  const [comparePeriods, setComparePeriods] = useState<string[]>(['mtd']);
  const activeCompare = comparisonOn ? comparePeriods : [];
  const togglePeriod = (key: string) =>
    setComparePeriods((prev) => (prev.includes(key) ? prev.filter((k) => k !== key) : [...prev, key]));
  // Rolling comparison interval for the far-right block (7/30/90 days; default
  // last month). Part of the dashboard query key, so switching it refetches.
  const [rollingDays, setRollingDays] = useState<number>(7);
  const { data: rows = [], isLoading } = useDashboardData(manager, metric, activeCompare, rollingDays);
  // Dashboard view: Performance (revenue/ROAS table) or Audience (Meta spend
  // split by a breakdown axis). The two share the Brands + Manager + currency
  // filters; everything else in the bar is view-specific. The audience query is
  // gated on the view so it doesn't fire until opened.
  const [view, setView] = useState<'performance' | 'audience'>('performance');
  const [breakdown, setBreakdown] = useState<AudienceBreakdown>('audience');
  const [audiencePeriod, setAudiencePeriod] = useState<AudiencePeriod>('last30');
  const { data: audience, isLoading: audienceLoading } = useAudienceData(
    manager,
    breakdown,
    audiencePeriod,
    view === 'audience',
  );
  const { data: managerUsers = [] } = useUsers(canFilterByManager);
  const masterSync = useMasterSync();
  // Sort control: best/worst performing (by the chosen metric, last 7 days) or A–Z.
  const [sortBy, setSortBy] = useState<'best' | 'worst' | 'name'>('best');
  const brandGroup = useFiltersStore((s) => s.brandGroup);
  const setBrandGroup = useFiltersStore((s) => s.setBrandGroup);

  // Mirrors the backend `role:master_admin,manager` middleware. Hiding the
  // button for team_member / brand_user prevents a 403 round-trip from a
  // button they were never supposed to see.
  const canMasterSync = user?.role === 'master_admin' || user?.role === 'manager';

  // Distinct group tags across the (unfiltered) rows. Drives the Brands
  // popover so the dropdown only shows groups that actually exist.
  const availableGroups = useMemo(() => {
    const seen = new Set<string>();
    for (const r of rows) {
      const g = r.brand.groupTag;
      if (g && g.trim() !== '') seen.add(g);
    }
    return Array.from(seen).sort();
  }, [rows]);

  // Ad platforms that any brand has actively connected. Empty in Phase 1
  // (Shopify-only). When empty, the tables hide every ad column.
  const visibleAdPlatforms = useMemo(() => {
    const set = new Set<Platform>();
    for (const r of rows) {
      for (const p of r.brand.platforms ?? []) {
        if (p === 'meta' || p === 'google' || p === 'tiktok') set.add(p);
      }
    }
    return set;
  }, [rows]);

  // Apply the brand-group filter client-side. Other filters (period, compare)
  // aren't wired yet — see the inline note where their controls used to live.
  const filteredRows: DashboardRow[] = useMemo(() => {
    if (!brandGroup) return rows;
    return rows.filter((r) => r.brand.groupTag === brandGroup);
  }, [rows, brandGroup]);

  // Client-side sort. Best/worst performing rank by the chosen metric over the
  // last 7 days (high→low / low→high); Name is A–Z.
  //
  // Inactive brands ALWAYS sink to the bottom, in every sort mode. A brand is
  // "inactive" when EITHER its net-sales figure (yesterday) OR its 7-day figure
  // is missing or zero — if either headline column is empty there isn't enough
  // live data to rank it, so it never competes for the top of any ordering
  // (including "worst performing", where we want the worst *active* brands, not
  // empty ones). Ties fall back to name.
  const sortedRows: DashboardRow[] = useMemo(() => {
    const yVal = (r: DashboardRow) =>
      metric === 'net' ? r.yesterday.netSales : r.yesterday.totalSales;
    const wVal = (r: DashboardRow) =>
      metric === 'net' ? r.rolling.netSales : r.rolling.totalSales;
    const isDead = (v: number | null) => v == null || v === 0;
    const isInactive = (r: DashboardRow) => isDead(yVal(r)) || isDead(wVal(r));

    const list = [...filteredRows];
    list.sort((a, b) => {
      const ai = isInactive(a);
      const bi = isInactive(b);
      if (ai !== bi) return ai ? 1 : -1; // inactive always last

      if (sortBy === 'name') {
        return a.brand.name.localeCompare(b.brand.name);
      }

      const dir = sortBy === 'worst' ? 1 : -1;
      const av = wVal(a);
      const bv = wVal(b);
      if (av == null && bv == null) return a.brand.name.localeCompare(b.brand.name);
      if (av == null) return 1;
      if (bv == null) return -1;
      if (av === bv) return a.brand.name.localeCompare(b.brand.name);
      return dir * (av - bv);
    });
    return list;
  }, [filteredRows, sortBy, metric]);

  const sortLabel =
    sortBy === 'best' ? 'Best performing' : sortBy === 'worst' ? 'Worst performing' : 'Name';
  const rollingLabel = ROLLING_WINDOWS.find((w) => w.days === rollingDays)?.label ?? 'Last 30 days';

  // Audience rows honor the same client-side brand-group filter as Performance.
  const filteredAudienceRows = useMemo(() => {
    if (!audience) return [];
    if (!brandGroup) return audience.rows;
    return audience.rows.filter((r) => r.brand.groupTag === brandGroup);
  }, [audience, brandGroup]);

  const breakdownLabel =
    BREAKDOWN_OPTIONS.find((o) => o.key === breakdown)?.label ?? 'Audience segments';
  const periodLabel =
    AUDIENCE_PERIODS.find((o) => o.key === audiencePeriod)?.label ?? 'Last 30 days';

  const managerLabel =
    manager === 'me'
      ? 'My brands'
      : manager === 'all'
      ? 'All brands'
      : manager === 'unassigned'
      ? 'No user assigned'
      : managerUsers.find((u) => String(u.id) === manager)?.name ?? 'Manager';

  // Only show the full "add your first brand" CTA for the default view. When a
  // brand-manager filter is active and returns nothing, keep the page (and its
  // filter bar) so the user can switch back instead of being trapped.
  if (!isLoading && rows.length === 0 && manager === 'me') {
    return (
      <AppLayout title="Dashboard">
        <DashboardEmptyState />
      </AppLayout>
    );
  }

  const tag =
    filteredRows.length !== rows.length
      ? `${filteredRows.length} of ${rows.length}`
      : rows.length > 0
      ? `${rows.length} active`
      : undefined;

  const brandFilterLabel = brandGroup ?? `All ${rows.length}`;

  return (
    <AppLayout title="All brands" tag={tag}>
      <div className="page-scroll">
      {/*
        Filter bar — only the filters that actually drive the data live here.
        Period chips, comparison baseline, and the columns picker were removed
        because `DashboardQuery` is currently fixed to yesterday + day-before
        + L7d. Shipping decorative chips that don't change the data would
        misrepresent what's loaded. They come back with the Phase 2 query
        rewrite that accepts arbitrary date ranges.
      */}
      <div className="filter-bar mb-12">
        <Segmented
          options={[
            { value: 'performance', label: 'Performance' },
            { value: 'audience', label: 'Audience' },
          ]}
          value={view}
          onChange={setView}
        />
        <Popover
          wide
          trigger={
            <button className="filter-btn">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75">
                <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3" />
              </svg>
              Brands: <strong style={{ fontWeight: 500 }}>{brandFilterLabel}</strong>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <polyline points="6 9 12 15 18 9" />
              </svg>
            </button>
          }
        >
          <PopoverLabel>Group</PopoverLabel>
          <PopoverItem
            active={brandGroup === null}
            meta={String(rows.length)}
            onClick={() => setBrandGroup(null)}
          >
            All groups
          </PopoverItem>
          {availableGroups.map((g) => {
            const count = rows.filter((r) => r.brand.groupTag === g).length;
            return (
              <PopoverItem
                key={g}
                active={brandGroup === g}
                meta={String(count)}
                onClick={() => setBrandGroup(g)}
              >
                {g}
              </PopoverItem>
            );
          })}
          <PopoverDivider />
          <PopoverLabel>Status</PopoverLabel>
          <PopoverItem active meta={String(rows.length)}>Active only</PopoverItem>
        </Popover>

        {canFilterByManager && (
          <Popover
            trigger={
              <button className="filter-btn">
                Manager: <strong style={{ fontWeight: 500 }}>{managerLabel}</strong>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                  <polyline points="6 9 12 15 18 9" />
                </svg>
              </button>
            }
          >
            <PopoverLabel>Brand manager</PopoverLabel>
            <PopoverItem active={manager === 'me'} onClick={() => setManager('me')}>
              My brands
            </PopoverItem>
            <PopoverItem active={manager === 'all'} onClick={() => setManager('all')}>
              All brands
            </PopoverItem>
            <PopoverItem active={manager === 'unassigned'} onClick={() => setManager('unassigned')}>
              No user assigned
            </PopoverItem>
            {managerUsers.filter((u) => u.status === 'active').length > 0 && (
              <>
                <PopoverDivider />
                <PopoverLabel>By user</PopoverLabel>
                {managerUsers
                  .filter((u) => u.status === 'active')
                  .map((u) => (
                    <PopoverItem
                      key={u.id}
                      active={manager === String(u.id)}
                      onClick={() => setManager(String(u.id))}
                    >
                      {u.name}
                    </PopoverItem>
                  ))}
              </>
            )}
          </Popover>
        )}

        <span style={{ flex: 1 }} />

        {/* Net sales hidden per client (Bosco, 2026-06-19) — only Total revenue
            is shown. To re-enable, restore `setMetric` (state above) and
            uncomment this toggle:
        <Segmented
          options={[
            { value: 'net', label: 'Net sales' },
            { value: 'total', label: 'Total revenue' },
          ]}
          value={metric}
          onChange={setMetric}
        />
        */}
        {view === 'audience' && (
          <>
            <Popover
              trigger={
                <button className="filter-btn">
                  Breakdown: <strong style={{ fontWeight: 500 }}>{breakdownLabel}</strong>
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                    <polyline points="6 9 12 15 18 9" />
                  </svg>
                </button>
              }
            >
              <PopoverLabel>Split Meta spend by</PopoverLabel>
              {BREAKDOWN_OPTIONS.map((o) => (
                <PopoverItem key={o.key} active={breakdown === o.key} onClick={() => setBreakdown(o.key)}>
                  {o.label}
                </PopoverItem>
              ))}
            </Popover>
            <Popover
              trigger={
                <button className="filter-btn">
                  Period: <strong style={{ fontWeight: 500 }}>{periodLabel}</strong>
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                    <polyline points="6 9 12 15 18 9" />
                  </svg>
                </button>
              }
            >
              <PopoverLabel>Period (ends yesterday)</PopoverLabel>
              {AUDIENCE_PERIODS.map((o) => (
                <PopoverItem
                  key={o.key}
                  active={audiencePeriod === o.key}
                  meta={audienceRangeShort(o.key)}
                  onClick={() => setAudiencePeriod(o.key)}
                >
                  {o.label}
                </PopoverItem>
              ))}
            </Popover>
          </>
        )}

        {view === 'performance' && (
          <>
            <button
              className="filter-btn"
              style={
                comparisonOn
                  ? { background: 'var(--accent)', color: 'var(--accent-fg)', borderColor: 'var(--accent)' }
                  : undefined
              }
              onClick={() => setComparisonOn((v) => !v)}
            >
              Comparison
            </button>
            <Popover
              trigger={
                <button className="filter-btn">
                  Interval: <strong style={{ fontWeight: 500 }}>{rollingLabel}</strong>
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                    <polyline points="6 9 12 15 18 9" />
                  </svg>
                </button>
              }
            >
              <PopoverLabel>Comparison interval</PopoverLabel>
              {ROLLING_WINDOWS.map((w) => (
                <PopoverItem
                  key={w.days}
                  active={rollingDays === w.days}
                  onClick={() => setRollingDays(w.days)}
                >
                  {w.label}
                </PopoverItem>
              ))}
            </Popover>
            <Popover
              trigger={
                <button className="filter-btn">
                  Sort: <strong style={{ fontWeight: 500 }}>{sortLabel}</strong>
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                    <polyline points="6 9 12 15 18 9" />
                  </svg>
                </button>
              }
            >
              <PopoverLabel>Sort by</PopoverLabel>
              <PopoverItem active={sortBy === 'best'} onClick={() => setSortBy('best')}>
                Best performing
              </PopoverItem>
              <PopoverItem active={sortBy === 'worst'} onClick={() => setSortBy('worst')}>
                Worst performing
              </PopoverItem>
              <PopoverItem active={sortBy === 'name'} onClick={() => setSortBy('name')}>
                Name (A–Z)
              </PopoverItem>
            </Popover>
          </>
        )}

        {/*
          Master Sync now — fires the same fan-out as the per-brand Sync now
          on every active brand. Restricted to master_admin / manager (matches
          the backend route's role middleware). Auto-sync runs on a daily
          cron, this button exists for the "I want fresh data right now" case.
        */}
        {canMasterSync && (
          <Button
            size="sm"
            variant="primary"
            onClick={() => masterSync.mutate()}
            disabled={masterSync.isPending || rows.length === 0}
            title="Queue a sync for every active brand. Auto-sync runs daily; this is for ad-hoc refreshes."
          >
            {masterSync.isPending ? 'Queueing…' : 'Sync now'}
          </Button>
        )}
      </div>

      {view === 'performance' && comparisonOn && (
        <div className="filter-bar mb-12" style={{ gap: 8, flexWrap: 'wrap', justifyContent: 'flex-end' }}>
          <span className="text-xs muted">Compare vs last year:</span>
          {COMPARE_PERIODS.map((p) => (
            <button
              key={p.key}
              className="filter-btn"
              style={
                comparePeriods.includes(p.key)
                  ? { background: 'var(--accent)', color: 'var(--accent-fg)', borderColor: 'var(--accent)' }
                  : undefined
              }
              onClick={() => togglePeriod(p.key)}
            >
              {p.label}
            </button>
          ))}
        </div>
      )}

      {view === 'performance' ? (
        <>
          <div className="compare-context">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75">
              <rect x="3" y="4" width="18" height="18" rx="2" />
              <line x1="16" y1="2" x2="16" y2="6" />
              <line x1="8" y1="2" x2="8" y2="6" />
              <line x1="3" y1="10" x2="21" y2="10" />
            </svg>
            Showing yesterday vs day before, with the last {rollingDays} days rolling block to the right. Dates are in each brand’s own timezone.
          </div>

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
            Each cell stacks <strong>yesterday</strong> on top and <strong>day before</strong> with the delta below. Figures are <strong>total revenue</strong> (Shopify’s Total sales). Blended ROAS is revenue ÷ ad spend. Online Store channel only.
          </Banner>

          <div className="table-region" style={{ marginTop: 16 }}>
            <BrandsTableWide
              rows={sortedRows}
              metric={metric}
              visibleAdPlatforms={visibleAdPlatforms}
              comparePeriods={activeCompare}
              rollingDays={rollingDays}
            />
          </div>

          <div className="flex items-center justify-between mt-24">
            <div className="text-xs muted">
              Showing {filteredRows.length} brand{filteredRows.length === 1 ? '' : 's'}
              {brandGroup ? ` in “${brandGroup}”` : ''}.
            </div>
          </div>
        </>
      ) : (
        <>
          <div className="compare-context">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75">
              <circle cx="12" cy="12" r="10" />
              <line x1="12" y1="16" x2="12" y2="12" />
              <line x1="12" y1="8" x2="12.01" y2="8" />
            </svg>
            <span>
              <strong>Meta spend only</strong> — Google and TikTok aren’t included in the breakdown. Showing{' '}
              <strong>{periodLabel.toLowerCase()}</strong>: {audienceRangeFull(audiencePeriod)} — today is excluded
              (each brand in its own timezone).
            </span>
          </div>

          <div className="table-region" style={{ marginTop: 16 }}>
            {audienceLoading && !audience ? (
              <Card>
                <div className="text-sm muted" style={{ padding: 48, textAlign: 'center' }}>
                  Loading audience breakdown…
                </div>
              </Card>
            ) : audience && filteredAudienceRows.length > 0 ? (
            <>
              <AudienceTable data={{ ...audience, rows: filteredAudienceRows }} />
              <div className="flex items-center justify-between mt-24">
                <div className="text-xs muted">
                  Showing {filteredAudienceRows.length} Meta brand
                  {filteredAudienceRows.length === 1 ? '' : 's'}
                  {brandGroup ? ` in “${brandGroup}”` : ''}.
                </div>
              </div>
            </>
          ) : (
            <Card>
              <div className="text-sm muted" style={{ padding: 48, textAlign: 'center' }}>
                No brands with a Meta connection in this view yet. Connect Meta on a brand to see its
                audience split here.
              </div>
            </Card>
          )}
          </div>
        </>
      )}
      </div>
    </AppLayout>
  );
}

/* ---- Empty state ----------------------------------------------------- */

function DashboardEmptyState() {
  const openAddBrand = useUiStore((s) => s.setAddBrandDrawerOpen);
  return (
    <div
      style={{
        maxWidth: 720,
        margin: '8vh auto 0',
        textAlign: 'center',
      }}
    >
      <div
        style={{
          display: 'inline-flex',
          alignItems: 'center',
          justifyContent: 'center',
          width: 72,
          height: 72,
          borderRadius: '50%',
          background: 'var(--surface-subtle)',
          border: '1px solid var(--border)',
          marginBottom: 24,
          color: 'var(--text-secondary)',
        }}
      >
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75">
          <circle cx="12" cy="12" r="9" />
          <path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18" />
        </svg>
      </div>

      <h2 style={{ marginBottom: 8 }}>Add your first brand</h2>
      <p
        className="lede"
        style={{
          margin: '0 auto 32px',
          maxWidth: 480,
        }}
      >
        Roasdriven shows blended revenue and ROAS across every store you manage. Connect a brand&rsquo;s
        Shopify and ad accounts to see real numbers here.
      </p>

      <div className="flex items-center gap-12" style={{ justifyContent: 'center', marginBottom: 48 }}>
        <button onClick={() => openAddBrand(true)} className="btn btn-primary btn-lg">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
            <path d="M12 5v14M5 12h14" />
          </svg>
          Add brand
        </button>
        <Link to="/settings" className="btn btn-secondary btn-lg">
          Configure platform keys
        </Link>
      </div>

      <div
        style={{
          display: 'grid',
          gridTemplateColumns: 'repeat(3, 1fr)',
          gap: 1,
          background: 'var(--border)',
          border: '1px solid var(--border)',
          borderRadius: 'var(--radius-lg)',
          overflow: 'hidden',
          textAlign: 'left',
        }}
      >
        <StepCard
          n={1}
          title="Add the agency keys"
          body="One Meta System User, one Google MCC, one TikTok BC. Set these once at Settings → Platform keys."
          to="/settings"
          cta="Open Platform keys"
        />
        <StepCard
          n={2}
          title="Create a brand"
          body="Name, timezone, base currency. Roasdriven uses the brand timezone for every metric date."
          onClick={() => openAddBrand(true)}
          cta="Add brand"
        />
        <StepCard
          n={3}
          title="Connect the platforms"
          body="Pick the Meta ad account, Google customer, TikTok advertiser. Shopify installs from the brand page."
          onClick={() => openAddBrand(true)}
          cta="Start"
        />
      </div>

      <p
        className="text-xs muted"
        style={{ marginTop: 24 }}
      >
        Once a brand is syncing, this page replaces itself with the live dashboard automatically.
      </p>
    </div>
  );
}

function StepCard({
  n,
  title,
  body,
  to,
  onClick,
  cta,
}: {
  n: number;
  title: string;
  body: string;
  to?: string;
  onClick?: () => void;
  cta: string;
}) {
  const ctaStyle = { color: 'var(--text)', fontWeight: 500 } as const;
  return (
    <div style={{ background: 'var(--surface)', padding: 22 }}>
      <div
        style={{
          display: 'inline-flex',
          alignItems: 'center',
          justifyContent: 'center',
          width: 22,
          height: 22,
          borderRadius: '50%',
          background: 'var(--accent)',
          color: 'var(--accent-fg)',
          fontSize: 12,
          fontWeight: 500,
          marginBottom: 10,
        }}
      >
        {n}
      </div>
      <div style={{ fontWeight: 500, marginBottom: 6, color: 'var(--text)' }}>{title}</div>
      <div style={{ fontSize: 13, color: 'var(--text-secondary)', marginBottom: 14, lineHeight: 1.55 }}>
        {body}
      </div>
      {to ? (
        <Link to={to} className="text-sm" style={ctaStyle}>
          {cta} →
        </Link>
      ) : (
        <button
          onClick={onClick}
          className="text-sm"
          style={{ ...ctaStyle, background: 'transparent', border: 0, padding: 0, cursor: 'pointer', fontFamily: 'inherit' }}
        >
          {cta} →
        </button>
      )}
    </div>
  );
}
