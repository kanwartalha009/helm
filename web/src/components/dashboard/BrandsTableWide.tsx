import { Fragment } from 'react';
import { Link } from 'react-router-dom';
import { Avatar, Card, Tag, Dot } from '@/components/ui';
import { cn } from '@/lib/cn';
import { formatMoney, formatRoas, pctDelta } from '@/lib/formatters';
import { useTriggerSync } from '@/hooks/useBrands';
import type { DashboardRow, Platform } from '@/types/domain';

interface MetricGroup {
  label: string;
  yesterday: number | null;
  dayBefore: number | null;
  /** Used to render currency cells. ROAS group passes 'roas' to switch to multiplier format. */
  kind: 'money' | 'roas';
  /**
   * If set, this group corresponds to a single platform — Meta/Google/TikTok
   * or Shopify (revenue group). When the metric is null we render N/A unless
   * the brand has an active connection for this platform.
   */
  platform?: 'shopify' | 'meta' | 'google' | 'tiktok';
  /**
   * The window backing this group isn't fully synced (e.g. L7d missing days).
   * When set, the group renders a clickable "Not synced" state instead of the
   * (nulled) partial figure — we never show a partial number (Bosco, 2026-06-30).
   */
  incomplete?: boolean;
}

interface Props {
  rows: DashboardRow[];
  /** When set (e.g. 'USD'), format every row in this currency; otherwise each brand renders in its own native currency. */
  currency?: string;
  /** Revenue metric. 'total' = Total revenue (Shopify "Total sales") — the only metric shown
   *  since net sales was hidden (Bosco, 2026-06-20); 'net' is retained for easy re-enable. */
  metric?: 'net' | 'total';
  visibleAdPlatforms?: Set<Platform>;
  /** Year-over-year comparison periods to append (yesterday|last7|last30|mtd). */
  comparePeriods?: string[];
}

export function BrandsTableWide({ rows, visibleAdPlatforms, currency, metric = 'total', comparePeriods = [] }: Props) {
  const showMeta   = visibleAdPlatforms?.has('meta')   ?? false;
  const showGoogle = visibleAdPlatforms?.has('google') ?? false;
  const showTikTok = visibleAdPlatforms?.has('tiktok') ?? false;
  const adPlatformCount = (showMeta ? 1 : 0) + (showGoogle ? 1 : 0) + (showTikTok ? 1 : 0);
  const showAdRollup = adPlatformCount >= 1; // ROAS shows with any ad platform
  // "Total inv." only adds value once spend spans 2+ ad platforms — with a
  // single platform it just duplicates that platform's column, so hide it.
  const showTotalSpend = adPlatformCount >= 2;

  // Each visible group occupies 3 sub-columns (yesterday / day-before / Δ).
  // We track count so the second header row knows how many SubHeaders to emit.
  const groupCount =
    1 // Revenue
    + (showMeta   ? 1 : 0)
    + (showGoogle ? 1 : 0)
    + (showTikTok ? 1 : 0)
    + (showTotalSpend ? 1 : 0) // Total inv. (only when 2+ ad platforms)
    + (showAdRollup ? 1 : 0)   // ROAS
    + 1; // L7d

  const revenueLabel = metric === 'net' ? 'Net sales' : 'Total Revenue Before Returns';

  return (
    <Card style={{ overflowX: 'auto' }}>
      <table className="data-table wide-table">
        <thead>
          {comparePeriods.length === 0 ? (
            <>
              {/* Default: 2-row grouped header */}
              <tr>
                <th className="brand-col group-head" rowSpan={2}>Brand</th>
                <th className="group-head group-start" colSpan={3}>{revenueLabel}</th>
                {showAdRollup && <th className="group-head group-start" colSpan={3}>Blended ROAS</th>}
                {showMeta   && <th className="group-head group-start" colSpan={3}>Meta inv.</th>}
                {showGoogle && <th className="group-head group-start" colSpan={3}>Google inv.</th>}
                {showTikTok && <th className="group-head group-start" colSpan={3}>TikTok inv.</th>}
                {showTotalSpend && <th className="group-head group-start" colSpan={3}>Total inv.</th>}
                <th className="group-head group-start" colSpan={3}>{revenueLabel} 7d</th>
              </tr>
              <tr>
                {Array.from({ length: groupCount }).map((_, i) => (
                  <SubHeaders key={i} groupStart />
                ))}
              </tr>
            </>
          ) : (
            <>
              {/* Comparison on: 3-row header. The YoY block sits right AFTER the
                  Total revenue group (Bosco, 2026-06-21); the other groups span
                  down beside it. Each enabled period = Revenue / Spend / ROAS ×
                  This yr·Last yr·Δ = 9 cols. */}
              <tr>
                <th className="brand-col group-head" rowSpan={3}>Brand</th>
                <th className="group-head group-start" rowSpan={2} colSpan={3}>{revenueLabel}</th>
                {comparePeriods.map((p) => (
                  <th key={p} className="group-head group-start" colSpan={9}>
                    {PERIOD_LABEL[p] ?? p} · vs last year
                  </th>
                ))}
                {showAdRollup && <th className="group-head group-start" rowSpan={2} colSpan={3}>Blended ROAS</th>}
                {showMeta   && <th className="group-head group-start" rowSpan={2} colSpan={3}>Meta inv.</th>}
                {showGoogle && <th className="group-head group-start" rowSpan={2} colSpan={3}>Google inv.</th>}
                {showTikTok && <th className="group-head group-start" rowSpan={2} colSpan={3}>TikTok inv.</th>}
                {showTotalSpend && <th className="group-head group-start" rowSpan={2} colSpan={3}>Total inv.</th>}
                <th className="group-head group-start" rowSpan={2} colSpan={3}>{revenueLabel} 7d</th>
              </tr>
              <tr>
                {comparePeriods.map((p) => (
                  <Fragment key={p}>
                    <th className="group-head group-start" colSpan={3}>{revenueLabel}</th>
                    <th className="group-head group-start" colSpan={3}>Spend</th>
                    <th className="group-head group-start" colSpan={3}>ROAS</th>
                  </Fragment>
                ))}
              </tr>
              <tr>
                <SubHeaders groupStart />
                {comparePeriods.map((p) => (
                  <Fragment key={p}>
                    <ComparisonSubHeaders />
                    <ComparisonSubHeaders />
                    <ComparisonSubHeaders />
                  </Fragment>
                ))}
                {Array.from({ length: groupCount - 1 }).map((_, i) => (
                  <SubHeaders key={i} groupStart />
                ))}
              </tr>
            </>
          )}
        </thead>
        <tbody>
          {rows.map((row) => (
            <Row
              key={row.brand.id}
              row={row}
              displayCurrency={currency}
              metric={metric}
              showMeta={showMeta}
              showGoogle={showGoogle}
              showTikTok={showTikTok}
              showTotalSpend={showTotalSpend}
              showAdRollup={showAdRollup}
              comparePeriods={comparePeriods}
            />
          ))}
        </tbody>
      </table>
    </Card>
  );
}

function SubHeaders({ groupStart }: { groupStart?: boolean }) {
  return (
    <>
      <th className={cn('sub-head num', groupStart && 'group-start')}>Yesterday</th>
      <th className="sub-head num">Day before</th>
      <th className="sub-head delta">Δ</th>
    </>
  );
}

const PERIOD_LABEL: Record<string, string> = {
  yesterday: 'Yesterday',
  last7: 'Last 7 days',
  last30: 'Last 30 days',
  mtd: 'Month to date',
};

function ComparisonSubHeaders() {
  return (
    <>
      <th className="sub-head num group-start">This yr</th>
      <th className="sub-head num">Last yr</th>
      <th className="sub-head delta">Δ</th>
    </>
  );
}

// One This yr / Last yr / Δ triplet for a single comparison metric (revenue,
// spend, or ROAS). Money metrics show a % delta; ROAS shows an absolute
// multiplier delta (matching the live ROAS column). A null side renders "—",
// and a missing last-year baseline shows "new" rather than a fake −100%.
function ComparisonTriplet({
  data,
  kind,
  currency,
}: {
  data?: { thisYear: number | null; lastYear: number | null };
  kind: 'money' | 'roas';
  currency: string;
}) {
  const thisYear = data?.thisYear ?? null;
  const lastYear = data?.lastYear ?? null;

  const fmt = (v: number | null) =>
    v === null ? '—' : kind === 'roas' ? formatRoas(v) : formatMoney(v, currency);

  let deltaLabel: string;
  let direction: 'up' | 'down' | 'flat' | 'na';
  if (thisYear === null || lastYear === null) {
    deltaLabel = '—';
    direction = 'na';
  } else if (kind === 'roas') {
    const diff = thisYear - lastYear;
    direction = diff > 0.005 ? 'up' : diff < -0.005 ? 'down' : 'flat';
    deltaLabel = `${diff > 0 ? '+' : ''}${diff.toFixed(2)}`;
  } else if (lastYear === 0) {
    deltaLabel = '—';
    direction = 'na';
  } else {
    const pct = ((thisYear - lastYear) / lastYear) * 100;
    direction = pct > 0.05 ? 'up' : pct < -0.05 ? 'down' : 'flat';
    deltaLabel = `${pct > 0 ? '+' : ''}${pct.toFixed(0)}%`;
  }

  return (
    <>
      <td className="num group-start">{fmt(thisYear)}</td>
      <td className="num prior">
        {lastYear !== null ? fmt(lastYear) : <span className="muted">new</span>}
      </td>
      <td className={cn('delta', direction)}>{deltaLabel}</td>
    </>
  );
}

function Row({
  row,
  displayCurrency,
  metric,
  showMeta,
  showGoogle,
  showTikTok,
  showTotalSpend,
  showAdRollup,
  comparePeriods,
}: {
  row: DashboardRow;
  displayCurrency?: string;
  metric: 'net' | 'total';
  showMeta: boolean;
  showGoogle: boolean;
  showTikTok: boolean;
  showTotalSpend: boolean;
  showAdRollup: boolean;
  comparePeriods: string[];
}) {
  const { brand, yesterday, dayBefore, last7d } = row;
  const detailHref = `/brands/${brand.slug}`;
  const connected = new Set(brand.platforms ?? []);
  const health = brand.platformHealth ?? {};
  // USD mode (displayCurrency set) formats every row in USD; otherwise each
  // brand renders in its own native currency.
  const currency = displayCurrency || brand.baseCurrency || 'USD';

  // Per-brand sync trigger for the "Sync now" affordance shown when yesterday
  // isn't a finalized day. Each row owns its mutation so pending state is
  // per-brand. revNeedsSync = connected to Shopify but yesterday not complete
  // (missing, or a partial mid-day sync) — don't pass that partial off as the
  // yesterday number (Bosco, 2026-06-21).
  const triggerSync = useTriggerSync();
  const revNeedsSync = connected.has('shopify') && !yesterday.isComplete;

  // Total revenue (Shopify "Total sales", Online Store). Net sales is hidden
  // (Bosco, 2026-06-20) so `metric` is pinned to 'total'; the 'net' branch is
  // retained for easy re-enable. Both are Shopify's own ShopifyQL figures.
  const yRev   = metric === 'net' ? yesterday.netSales : yesterday.totalSales;
  const dbRev  = metric === 'net' ? dayBefore.netSales : dayBefore.totalSales;
  const l7Rev  = metric === 'net' ? last7d.netSales : last7d.totalSales;
  const l7Prev = metric === 'net' ? last7d.netSalesPrior7d : last7d.totalSalesPrior7d;
  // Blended ROAS follows the same toggle: net-sales ROAS or total-sales ROAS.
  const yRoas  = metric === 'net' ? yesterday.roas : yesterday.roasTotal;
  const dbRoas = metric === 'net' ? dayBefore.roas : dayBefore.roasTotal;

  const groups: MetricGroup[] = [
    { label: 'Revenue', yesterday: yRev, dayBefore: dbRev, kind: 'money', platform: 'shopify' },
  ];
  // Blended ROAS sits right next to the revenue it's measured against.
  if (showAdRollup) {
    groups.push({ label: 'Blended ROAS', yesterday: yRoas, dayBefore: dbRoas, kind: 'roas' });
  }
  if (showMeta)   groups.push({ label: 'Meta',   yesterday: yesterday.metaSpend,   dayBefore: dayBefore.metaSpend,   kind: 'money', platform: 'meta'   });
  if (showGoogle) groups.push({ label: 'Google', yesterday: yesterday.googleSpend, dayBefore: dayBefore.googleSpend, kind: 'money', platform: 'google' });
  if (showTikTok) groups.push({ label: 'TikTok', yesterday: yesterday.tiktokSpend, dayBefore: dayBefore.tiktokSpend, kind: 'money', platform: 'tiktok' });
  if (showTotalSpend) {
    groups.push({ label: 'Total', yesterday: yesterday.totalSpend, dayBefore: dayBefore.totalSpend, kind: 'money' });
  }
  groups.push({
    label: 'L7d',
    yesterday: l7Rev,
    dayBefore: l7Prev,
    kind: 'money',
    platform: 'shopify',
    // The 7-day sum only shows when all 7 days synced (backend already nulls it
    // otherwise); flag it so the cell renders "Not synced" rather than "—".
    incomplete: connected.has('shopify') && !last7d.isComplete,
  });

  return (
    <tr>
      <td className="brand-col">
        <Link to={detailHref} style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <Avatar initials={brand.initials} />
          <div>
            <div style={{ fontWeight: 500 }}>{brand.name}</div>
            <div className="brand-meta">
              {brand.region} · {brand.baseCurrency}
            </div>
          </div>
        </Link>
      </td>

      {/* Total revenue first, then the YoY comparison block (right after Total
          revenue per Bosco, 2026-06-21), then the remaining groups. */}
      <MetricCells
        group={groups[0]}
        currency={currency}
        index={0}
        connected={connected}
        health={health}
        needsSync={revNeedsSync}
        onSync={() => triggerSync.mutate(brand.slug)}
        syncing={triggerSync.isPending}
      />
      {comparePeriods.map((p) => {
        const c = row.comparison?.[p];
        return (
          <Fragment key={p}>
            <ComparisonTriplet data={c?.revenue} kind="money" currency={currency} />
            <ComparisonTriplet data={c?.spend} kind="money" currency={currency} />
            <ComparisonTriplet data={c?.roas} kind="roas" currency={currency} />
          </Fragment>
        );
      })}
      {groups.slice(1).map((g, i) => (
        <MetricCells
          key={g.label}
          group={g}
          currency={currency}
          index={i + 1}
          connected={connected}
          health={health}
          onSync={() => triggerSync.mutate(brand.slug)}
          syncing={triggerSync.isPending}
        />
      ))}
    </tr>
  );
}

function MetricCells({
  group,
  currency,
  index,
  connected,
  health,
  needsSync,
  onSync,
  syncing,
}: {
  group: MetricGroup;
  currency: string;
  index: number;
  connected: Set<string>;
  health: Record<string, { status: string; lastSyncAt: string | null; hasError: boolean } | undefined>;
  needsSync?: boolean;
  onSync?: () => void;
  syncing?: boolean;
}) {
  const fmt = (v: number | null) =>
    v === null || v === undefined
      ? '—'
      : group.kind === 'roas'
      ? formatRoas(v)
      : formatMoney(v, currency);

  const groupStart = 'group-start';
  const cellClass = cn('num');
  const isFirstColInGroup = true;

  // Yesterday isn't finalized for this Shopify-connected brand (missing, or a
  // partial mid-day sync). Don't render a misleading partial as the yesterday
  // figure — offer a per-brand Sync now (Bosco, 2026-06-21).
  if (group.platform === 'shopify' && index === 0 && needsSync) {
    const errored = health.shopify?.hasError;
    return (
      <td className={cn(cellClass, groupStart)} colSpan={3} style={{ textAlign: 'center' }}>
        <button
          type="button"
          onClick={onSync}
          disabled={syncing}
          title="Yesterday isn’t fully synced — sync this brand for correct numbers."
          style={{ background: 'none', border: 0, padding: 0, cursor: syncing ? 'wait' : 'pointer', fontFamily: 'inherit' }}
        >
          <Tag variant="warning">
            <Dot variant="warning" />
            {syncing ? 'Syncing…' : errored ? 'Retry sync' : 'Sync now'}
          </Tag>
        </button>
      </td>
    );
  }

  // The window backing this group isn't fully synced (e.g. L7d missing a day).
  // The backend already nulled the partial figure; render a clickable "Not
  // synced" state so nobody reads a short-window sum as a full one.
  if (group.incomplete) {
    return (
      <td className={cn(cellClass, groupStart)} colSpan={3} style={{ textAlign: 'center' }}>
        <button
          type="button"
          onClick={onSync}
          disabled={syncing || !onSync}
          title="This window isn’t fully synced yet — sync this brand for complete numbers."
          style={{
            background: 'none',
            border: 0,
            padding: 0,
            cursor: onSync && !syncing ? 'pointer' : 'default',
            fontFamily: 'inherit',
          }}
        >
          <Tag variant="warning">
            <Dot variant="warning" />
            {syncing ? 'Syncing…' : 'Not synced'}
          </Tag>
        </button>
      </td>
    );
  }

  if (group.yesterday === null) {
    if (group.platform && !connected.has(group.platform)) {
      return (
        <td className={cn(cellClass, groupStart)} colSpan={3} style={{ textAlign: 'center' }}>
          <span className="muted">N/A</span>
        </td>
      );
    }

    if (group.platform === 'shopify' && index === 0) {
      const shopifyHealth = health.shopify;
      if (shopifyHealth?.hasError) {
        return (
          <td className={cn(cellClass, groupStart)} colSpan={3} style={{ textAlign: 'center' }}>
            <Tag variant="warning" title={shopifyHealth.lastSyncAt ?? undefined}>
              <Dot variant="warning" />
              Shopify failed
            </Tag>
          </td>
        );
      }
      if (!shopifyHealth?.lastSyncAt) {
        return (
          <td className={cn(cellClass, groupStart)} colSpan={3} style={{ textAlign: 'center' }}>
            <Tag variant="warning">
              <Dot variant="warning" />
              Sync pending
            </Tag>
          </td>
        );
      }
      return (
        <>
          <td className={cn(cellClass, groupStart)}>{fmt(0)}</td>
          <td className={cellClass}>{fmt(group.dayBefore)}</td>
          <td className="delta muted">—</td>
        </>
      );
    }

    return (
      <td className={cn(cellClass, groupStart)} colSpan={3} style={{ textAlign: 'center' }}>
        <span className="muted">—</span>
      </td>
    );
  }

  const pct = pctDelta(group.yesterday, group.dayBefore);

  let deltaLabel: string;
  let direction: 'up' | 'down' | 'flat' | 'na';

  if (group.kind === 'roas') {
    if (group.dayBefore === null) {
      deltaLabel = '—';
      direction = 'na';
    } else {
      const diff = group.yesterday - group.dayBefore;
      direction = diff > 0.005 ? 'up' : diff < -0.005 ? 'down' : 'flat';
      const sign = diff > 0 ? '+' : '';
      deltaLabel = `${sign}${diff.toFixed(2)}`;
    }
  } else {
    if (pct === null) {
      deltaLabel = '—';
      direction = 'na';
    } else {
      direction = pct > 0.05 ? 'up' : pct < -0.05 ? 'down' : 'flat';
      const sign = pct > 0 ? '+' : '';
      deltaLabel = `${sign}${pct.toFixed(1)}%`;
    }
  }

  return (
    <>
      <td className={cn(cellClass, isFirstColInGroup && groupStart)}>{fmt(group.yesterday)}</td>
      <td className={cn(cellClass, 'prior')}>{fmt(group.dayBefore)}</td>
      <td className={cn('delta', direction)}>{deltaLabel}</td>
    </>
  );
}
