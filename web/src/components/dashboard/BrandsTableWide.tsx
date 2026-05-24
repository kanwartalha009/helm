import { Link } from 'react-router-dom';
import { Avatar, Card, Tag, Dot } from '@/components/ui';
import { cn } from '@/lib/cn';
import { formatMoney, formatRoas, pctDelta } from '@/lib/formatters';
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
}

interface Props {
  rows: DashboardRow[];
  /** Phase 1: ignored — each row formats in its own native currency. */
  currency?: string;
  returns?: 'gross' | 'net';
  visibleAdPlatforms?: Set<Platform>;
}

export function BrandsTableWide({ rows, returns = 'net', visibleAdPlatforms }: Props) {
  const showMeta   = visibleAdPlatforms?.has('meta')   ?? false;
  const showGoogle = visibleAdPlatforms?.has('google') ?? false;
  const showTikTok = visibleAdPlatforms?.has('tiktok') ?? false;
  const showAdRollup = showMeta || showGoogle || showTikTok;

  // Each visible group occupies 3 sub-columns (yesterday / day-before / Δ).
  // We track count so the second header row knows how many SubHeaders to emit.
  const groupCount =
    1 // Revenue
    + (showMeta   ? 1 : 0)
    + (showGoogle ? 1 : 0)
    + (showTikTok ? 1 : 0)
    + (showAdRollup ? 2 : 0) // Total spend + ROAS
    + 1; // L7d

  const revenueLabel = returns === 'gross' ? 'Revenue (gross)' : 'Revenue (net)';

  return (
    <Card style={{ overflowX: 'auto' }}>
      <table className="data-table wide-table">
        <thead>
          {/* Top-level grouped header */}
          <tr>
            <th className="brand-col group-head" rowSpan={2}>
              Brand
            </th>
            <th className="group-head group-start" colSpan={3}>{revenueLabel}</th>
            {showMeta   && <th className="group-head group-start" colSpan={3}>Meta inv.</th>}
            {showGoogle && <th className="group-head group-start" colSpan={3}>Google inv.</th>}
            {showTikTok && <th className="group-head group-start" colSpan={3}>TikTok inv.</th>}
            {showAdRollup && <th className="group-head group-start" colSpan={3}>Total inv.</th>}
            {showAdRollup && <th className="group-head group-start" colSpan={3}>ROAS</th>}
            <th className="group-head group-start" colSpan={3}>Revenue last 7d</th>
          </tr>
          {/* Sub-header — Y / Y-1 / Δ for each group */}
          <tr>
            {Array.from({ length: groupCount }).map((_, i) => (
              <SubHeaders key={i} groupStart />
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <Row
              key={row.brand.id}
              row={row}
              returns={returns}
              showMeta={showMeta}
              showGoogle={showGoogle}
              showTikTok={showTikTok}
              showAdRollup={showAdRollup}
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

function Row({
  row,
  returns,
  showMeta,
  showGoogle,
  showTikTok,
  showAdRollup,
}: {
  row: DashboardRow;
  returns: 'gross' | 'net';
  showMeta: boolean;
  showGoogle: boolean;
  showTikTok: boolean;
  showAdRollup: boolean;
}) {
  const { brand, yesterday, dayBefore, last7d } = row;
  const detailHref = `/brands/${brand.slug}`;
  const connected = new Set(brand.platforms ?? []);
  const health = brand.platformHealth ?? {};
  const currency = brand.baseCurrency || 'USD';

  const yRev   = returns === 'gross' ? yesterday.revenue          : yesterday.revenueNet;
  const dbRev  = returns === 'gross' ? dayBefore.revenue          : dayBefore.revenueNet;
  const l7Rev  = returns === 'gross' ? last7d.revenueGross        : last7d.revenue;
  const l7Prev = returns === 'gross' ? last7d.revenueGrossPrior7d : last7d.revenuePrior7d;

  const groups: MetricGroup[] = [
    { label: 'Revenue', yesterday: yRev, dayBefore: dbRev, kind: 'money', platform: 'shopify' },
  ];
  if (showMeta)   groups.push({ label: 'Meta',   yesterday: yesterday.metaSpend,   dayBefore: dayBefore.metaSpend,   kind: 'money', platform: 'meta'   });
  if (showGoogle) groups.push({ label: 'Google', yesterday: yesterday.googleSpend, dayBefore: dayBefore.googleSpend, kind: 'money', platform: 'google' });
  if (showTikTok) groups.push({ label: 'TikTok', yesterday: yesterday.tiktokSpend, dayBefore: dayBefore.tiktokSpend, kind: 'money', platform: 'tiktok' });
  if (showAdRollup) {
    groups.push({ label: 'Total', yesterday: yesterday.totalSpend, dayBefore: dayBefore.totalSpend, kind: 'money' });
    groups.push({ label: 'ROAS',  yesterday: yesterday.roas,       dayBefore: dayBefore.roas,       kind: 'roas'  });
  }
  groups.push({ label: 'L7d', yesterday: l7Rev, dayBefore: l7Prev, kind: 'money', platform: 'shopify' });

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

      {groups.map((g, i) => (
        <MetricCells
          key={g.label}
          group={g}
          currency={currency}
          index={i}
          connected={connected}
          health={health}
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
}: {
  group: MetricGroup;
  currency: string;
  index: number;
  connected: Set<string>;
  health: Record<string, { status: string; lastSyncAt: string | null; hasError: boolean } | undefined>;
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
