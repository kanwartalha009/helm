import { Link } from 'react-router-dom';
import { Avatar, Card, Tag, Dot } from '@/components/ui';
import { MetricCell } from './MetricCell';
import { formatMoney, formatRoas } from '@/lib/formatters';
import type { DashboardRow, Platform } from '@/types/domain';

interface Props {
  rows: DashboardRow[];
  /** When set (e.g. 'USD'), format every row in this currency; otherwise each brand renders in its own native currency. */
  currency?: string;
  /**
   * Ad platforms that should appear as columns. Computed in DashboardPage as
   * "platforms that at least one brand has actively connected". When empty
   * (Shopify-only workspace), Meta/Google/TikTok + Total spend + ROAS are
   * all hidden — those columns are noise when no ad data exists.
   */
  visibleAdPlatforms?: Set<Platform>;
}

export function BrandsTable({ rows, visibleAdPlatforms, currency }: Props) {
  const showMeta   = visibleAdPlatforms?.has('meta')   ?? false;
  const showGoogle = visibleAdPlatforms?.has('google') ?? false;
  const showTikTok = visibleAdPlatforms?.has('tiktok') ?? false;
  // Total spend + ROAS are only meaningful when at least one ad platform is
  // present. Otherwise we'd be stacking placeholder columns onto a
  // Shopify-only workspace.
  const showAdRollup = showMeta || showGoogle || showTikTok;

  const revenueHeader = 'Total sales';

  return (
    <Card style={{ overflowX: 'auto' }}>
      <table className="data-table dashboard-table">
        <thead>
          <tr>
            <th style={{ width: 220 }}>Brand</th>
            <th className="num">{revenueHeader}</th>
            {showMeta   && <th className="num">Meta</th>}
            {showGoogle && <th className="num">Google</th>}
            {showTikTok && <th className="num">TikTok</th>}
            {showAdRollup && <th className="num">Total spend</th>}
            {showAdRollup && <th className="num">ROAS</th>}
            <th className="num col-group-start">Total sales (7d)</th>
            <th />
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <Row
              key={row.brand.id}
              row={row}
              displayCurrency={currency}
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

function Row({
  row,
  displayCurrency,
  showMeta,
  showGoogle,
  showTikTok,
  showAdRollup,
}: {
  row: DashboardRow;
  displayCurrency?: string;
  showMeta: boolean;
  showGoogle: boolean;
  showTikTok: boolean;
  showAdRollup: boolean;
}) {
  const { brand, yesterday, dayBefore, last7d } = row;
  const detailHref = `/brands/${brand.slug}`;
  // USD mode (displayCurrency set) formats every row in USD; otherwise each
  // brand renders in its own native currency.
  const currency    = displayCurrency || brand.baseCurrency || 'USD';
  const renderMoney = (v: number | null) => formatMoney(v, currency);
  const connected = new Set(brand.platforms ?? []);
  const hasShopify = connected.has('shopify');
  const shopifyHealth = brand.platformHealth?.shopify;
  const shopifyErrored = !!shopifyHealth?.hasError;
  const shopifySynced = !!shopifyHealth?.lastSyncAt;

  // Total sales before returns — the single revenue metric the client wants
  // (product − discounts + shipping + taxes, returns not subtracted).
  const yRev   = yesterday.revenue;
  const dbRev  = dayBefore.revenue;
  const l7Rev  = last7d.revenueGross;
  const l7Prev = last7d.revenueGrossPrior7d;

  const adCell = (platform: 'meta' | 'google' | 'tiktok', value: number | null, prior: number | null) => {
    if (value === null) {
      return connected.has(platform)
        ? <span className="muted">—</span>
        : <span className="muted">N/A</span>;
    }
    return (
      <MetricCell
        current={renderMoney(value)}
        prior={renderMoney(prior)}
        currentValue={value}
        priorValue={prior}
      />
    );
  };

  return (
    <tr>
      <td className="sticky-col">
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

      {/* Revenue */}
      <td className="num">
        {yRev === null ? (
          !hasShopify ? (
            <span className="muted">N/A</span>
          ) : shopifyErrored ? (
            <Tag variant="warning" title={shopifyHealth?.lastSyncAt ? `Last sync ${shopifyHealth.lastSyncAt}` : 'Sync errored'}>
              <Dot variant="warning" />
              Shopify failed
            </Tag>
          ) : !shopifySynced ? (
            <Tag variant="warning">
              <Dot variant="warning" />
              Sync pending
            </Tag>
          ) : (
            // Connection healthy, sync ran, no row for yesterday → zero orders.
            <MetricCell
              current={renderMoney(0)}
              prior={renderMoney(dbRev)}
              currentValue={0}
              priorValue={dbRev}
            />
          )
        ) : (
          <MetricCell
            current={renderMoney(yRev)}
            prior={renderMoney(dbRev)}
            currentValue={yRev}
            priorValue={dbRev}
          />
        )}
      </td>

      {showMeta   && <td className="num">{adCell('meta',   yesterday.metaSpend,   dayBefore.metaSpend)}</td>}
      {showGoogle && <td className="num">{adCell('google', yesterday.googleSpend, dayBefore.googleSpend)}</td>}
      {showTikTok && <td className="num">{adCell('tiktok', yesterday.tiktokSpend, dayBefore.tiktokSpend)}</td>}

      {showAdRollup && (
        <td className="num">
          {yesterday.totalSpend === null ? (
            <span className="muted">—</span>
          ) : (
            <MetricCell
              current={renderMoney(yesterday.totalSpend)}
              prior={renderMoney(dayBefore.totalSpend)}
              currentValue={yesterday.totalSpend}
              priorValue={dayBefore.totalSpend}
            />
          )}
        </td>
      )}

      {showAdRollup && (
        <td className="num">
          {yesterday.roas === null ? (
            <span className="muted">—</span>
          ) : (
            <MetricCell
              current={formatRoas(yesterday.roas)}
              prior={formatRoas(dayBefore.roas)}
              currentValue={yesterday.roas}
              priorValue={dayBefore.roas}
              absoluteDelta
            />
          )}
        </td>
      )}

      {/* Last 7 days */}
      <td className="num col-group-start">
        {!last7d.isComplete ? (
          <Tag variant="warning">
            <Dot variant="warning" />
            Partial
          </Tag>
        ) : (
          <MetricCell
            current={renderMoney(l7Rev)}
            prior={renderMoney(l7Prev)}
            currentValue={l7Rev}
            priorValue={l7Prev}
          />
        )}
      </td>

      <td>
        <Link to={detailHref} className="btn btn-ghost btn-sm">
          Open →
        </Link>
      </td>
    </tr>
  );
}
