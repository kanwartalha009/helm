import { useState, type CSSProperties, type ReactNode } from 'react';
import { formatMoney, formatNumber, formatRoas } from '@/lib/formatters';
import { SessionCell } from '@/components/inventory/SessionTraffic';
import type {
  CollectionGroup,
  InventoryAction,
  InventoryProduct,
  InventoryStatus,
  SessionSplit,
} from '@/types/inventory';

// Design tokens come from CSS variables so this stays in the Helm system (warm
// neutrals, single accent, no shadows). Header is near-black; rows are 1px
// borders on --surface. The table renders two shapes: a flat product list
// ("By product") or model-grouped collections that expand to their members
// ("By collection"). Both share the same metric columns.
const STATUS_LABEL: Record<InventoryStatus, string> = { ok: 'OK', alert: 'Alert', pause: 'Pause' };

const STATUS_STYLE: Record<InventoryStatus, { bg: string; fg: string; dot: string }> = {
  ok:    { bg: 'var(--success-bg, #F0FDF4)', fg: '#166534', dot: 'var(--success)' },
  alert: { bg: 'var(--warning-bg, #FEF3C7)', fg: 'var(--warning)', dot: 'var(--warning)' },
  pause: { bg: 'var(--danger-bg, #FEF2F2)', fg: 'var(--danger)', dot: 'var(--danger)' },
};

const ACTION_LABEL: Record<InventoryAction, string> = {
  out_of_stock: 'Out of stock — pause ads',
  low_stock: 'Low stock — reorder',
  no_spend: 'No ad spend',
  ok: 'Stock OK',
};
const ACTION_COLOR: Record<InventoryAction, string> = {
  out_of_stock: 'var(--danger)',
  low_stock: 'var(--warning)',
  no_spend: 'var(--text-muted)',
  ok: 'var(--text-secondary)',
};

function stockColor(status: InventoryStatus): string {
  return status === 'pause' ? 'var(--danger)' : status === 'alert' ? 'var(--warning)' : 'var(--success)';
}

const thBase: CSSProperties = {
  position: 'sticky',
  top: 0,
  zIndex: 2,
  background: '#12100F',
  color: '#E7E5E4',
  textAlign: 'right',
  fontWeight: 500,
  fontSize: 11.5,
  padding: '11px 12px',
  whiteSpace: 'nowrap',
};
const thL: CSSProperties = { ...thBase, textAlign: 'left' };
const tdBase: CSSProperties = {
  padding: '11px 12px',
  borderTop: '1px solid var(--border)',
  textAlign: 'right',
  whiteSpace: 'nowrap',
  verticalAlign: 'middle',
  fontVariantNumeric: 'tabular-nums',
};
const tdL: CSSProperties = { ...tdBase, textAlign: 'left' };
const SUBTLE = 'var(--surface-subtle)';

// The metric fields shared by a product and a collection — lets one cell
// renderer serve both a product row and an aggregated collection row.
// Metric fields are null when the dataset (commerce / ad spend) has no synced
// rows for the window — rendered '—', never 0 / €0.
type MetricItem = {
  stock: number;
  status: InventoryStatus;
  units: number | null;
  unitsPrev: number | null;
  deltaPct: number | null;
  spend: number | null;
  revenue: number | null;
  roas: number | null;
  ads: number | null;
  // Sessions that LANDED here (Bosco item B). Optional because the backend rolls out
  // additively; null because an unreconciled window must render '—', never a short sum.
  sessions?: number | null;
  sessionsByType?: SessionSplit | null;
  action: InventoryAction;
};

type Props =
  | { mode: 'product'; products: InventoryProduct[]; currency: string }
  | { mode: 'collection'; collections: CollectionGroup[]; currency: string };

export function InventoryTable(props: Props) {
  const [open, setOpen] = useState<Set<string>>(new Set());
  const toggle = (k: string) =>
    setOpen((prev) => {
      const next = new Set(prev);
      if (next.has(k)) next.delete(k);
      else next.add(k);
      return next;
    });

  const money = (v: number | null) => formatMoney(v, props.currency, { whole: true });
  const collection = props.mode === 'collection';

  return (
    <div
      style={{
        background: 'var(--surface)',
        border: '1px solid var(--border)',
        borderRadius: 'var(--radius-lg)',
      }}
    >
      {/* Scrolls horizontally instead of painting outside its own border. `table-layout: fixed`
          plus explicit widths on the two flexible columns (#, Product) means the browser sizes
          the table from the LAYOUT, not from whichever cell happens to hold the longest string —
          which is what let one long product title push everything sideways. */}
      <div className="table-scroll" style={{ borderRadius: 'var(--radius-lg)' }}>
        <table style={{ width: '100%', minWidth: 1180, borderCollapse: 'collapse', fontSize: 13 }}>
          <thead>
            <tr>
              <th style={{ ...thL, width: 34 }}>#</th>
              <th style={{ ...thL, minWidth: 190 }}>{collection ? 'Collection' : 'Product'}</th>
              <th style={thBase}>Stock total</th>
              {collection && <th style={thBase}>Products</th>}
              <th style={thBase}>Units</th>
              <th style={thBase}>Units prev</th>
              <th style={thBase} title="Ad spend attributed to this product, summed across every connected ad platform (Meta, Google, TikTok) — not Meta alone.">Ad spend</th>
              <th style={thBase}>Revenue</th>
              <th style={thBase}>ROAS blended</th>
              <th style={thBase}>Active ads</th>
              <th style={thL}>Status</th>
              <th style={thL}>Action</th>
              {/* Sessions LAST (Bosco, 2026-07-13): it's the widest cell (bar + two labels) and
                  reads as context for the row, not as a metric you sort the table by. */}
              <th style={thL} title="Sessions that LANDED on this product's page, split paid vs organic. Someone who arrives on the homepage and then browses to this product is counted under Store-wide, not here.">
                Sessions · paid vs organic
              </th>
            </tr>
          </thead>
          <tbody>
            {collection
              ? props.collections.map((g, i) => (
                  <CollectionRows
                    key={g.key}
                    g={g}
                    rank={i + 1}
                    isOpen={open.has(g.key)}
                    onToggle={() => toggle(g.key)}
                    money={money}
                  />
                ))
              : props.products.map((p, i) => (
                  <tr
                    key={p.handle}
                    onMouseEnter={(e) => (e.currentTarget.style.background = SUBTLE)}
                    onMouseLeave={(e) => (e.currentTarget.style.background = '')}
                  >
                    <td style={{ ...tdL, color: 'var(--text-muted)' }}>{i + 1}</td>
                    <td style={tdL}>
                      {/* Truncate rather than let one long title stretch the column and push the
                          table past its container. Full name stays available on hover. */}
                      <div
                        style={{ fontWeight: 500, maxWidth: 260, overflow: 'hidden', textOverflow: 'ellipsis' }}
                        title={p.title}
                      >
                        {p.title}
                      </div>
                    </td>
                    {metricCells(p, money, {})}
                  </tr>
                ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function CollectionRows({
  g,
  rank,
  isOpen,
  onToggle,
  money,
}: {
  g: CollectionGroup;
  rank: number;
  isOpen: boolean;
  onToggle: () => void;
  money: (v: number | null) => string;
}) {
  return (
    <>
      <tr
        onClick={onToggle}
        style={{ cursor: 'pointer' }}
        onMouseEnter={(e) => (e.currentTarget.style.background = SUBTLE)}
        onMouseLeave={(e) => (e.currentTarget.style.background = '')}
      >
        <td style={{ ...tdL, color: 'var(--text-muted)' }}>{rank}</td>
        <td style={tdL}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 9 }}>
            <svg
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="2"
              style={{
                width: 14,
                height: 14,
                color: 'var(--text-muted)',
                flex: '0 0 auto',
                transform: isOpen ? 'rotate(90deg)' : 'none',
                transition: 'transform .12s',
              }}
            >
              <path d="m9 18 6-6-6-6" />
            </svg>
            <div>
              <div style={{ fontWeight: 600, maxWidth: 240, overflow: 'hidden', textOverflow: 'ellipsis' }} title={g.name}>{g.name}</div>
              <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>
                {g.productCount} {g.productCount === 1 ? 'product' : 'products'}
              </div>
            </div>
          </div>
        </td>
        {metricCells(g, money, { colores: formatNumber(g.productCount) })}
      </tr>

      {isOpen &&
        g.products.map((p) => (
          <tr key={p.handle}>
            <td style={{ ...tdL, background: SUBTLE }} />
            <td style={{ ...tdL, background: SUBTLE, paddingLeft: 44 }}>
              <div style={{ fontWeight: 500, color: 'var(--text-secondary)', maxWidth: 220, overflow: 'hidden', textOverflow: 'ellipsis' }} title={p.title}>{p.title}</div>
              <div style={{ fontSize: 11, color: 'var(--text-muted)', fontFamily: 'ui-monospace, SFMono-Regular, monospace' }}>
                {p.handle}
              </div>
            </td>
            {metricCells(p, money, { colores: <span style={{ color: 'var(--text-muted)' }}>—</span>, child: true })}
          </tr>
        ))}
    </>
  );
}

// Renders every cell after the name: Stock, [Products], Units+Δ, Units prev,
// Ad spend, Revenue, ROAS, Active ads, Sessions, Status, Action. `colores` omitted →
// no Products column (product view). `child` → subtle background for expanded
// collection members.
function metricCells(
  item: MetricItem,
  money: (v: number | null) => string,
  opts: { colores?: ReactNode; child?: boolean },
): ReactNode {
  const bg = opts.child ? SUBTLE : undefined;
  const num: CSSProperties = { ...tdBase, background: bg };
  const left: CSSProperties = { ...tdL, background: bg };
  return (
    <>
      <td style={{ ...num, fontWeight: 600, color: stockColor(item.status) }}>{formatNumber(item.stock)}</td>
      {opts.colores !== undefined && <td style={num}>{opts.colores}</td>}
      <td style={num}>
        <div style={{ fontWeight: 600 }}>{formatNumber(item.units)}</div>
        {/* Delta chip only when commerce data exists for the window — a null
            units means "not synced", where even "new" would be a lie. */}
        {item.units != null && (
          <div style={{ fontSize: 11, fontWeight: 500, marginTop: 1 }}>
            <DeltaBadge deltaPct={item.deltaPct} />
          </div>
        )}
      </td>
      <td style={{ ...num, color: 'var(--text-muted)' }}>{formatNumber(item.unitsPrev)}</td>
      <td style={num}>
        {/* null = spend not synced for the window; ≤0 = covered but nothing
            spent. Both render '—' (never €0.00) — the page banner explains
            which case applies. */}
        {item.spend != null && item.spend > 0 ? (
          money(item.spend)
        ) : (
          <span style={{ color: 'var(--text-muted)' }}>—</span>
        )}
      </td>
      <td style={num}>{money(item.revenue)}</td>
      <td style={{ ...num, fontWeight: 600, color: item.roas != null && item.roas >= 3 ? 'var(--success)' : undefined }}>
        {item.roas != null ? formatRoas(item.roas) : <span style={{ color: 'var(--text-muted)' }}>—</span>}
      </td>
      <td style={num}>{item.ads != null ? item.ads : <span style={{ color: 'var(--text-muted)' }}>—</span>}</td>
      <td style={left}>
        <StatusPill status={item.status} />
      </td>
      <td style={{ ...left, fontSize: 12.5, color: ACTION_COLOR[item.action] }}>{ACTION_LABEL[item.action]}</td>
      <td style={left}>
        <SessionCell total={item.sessions} split={item.sessionsByType} />
      </td>
    </>
  );
}

function StatusPill({ status }: { status: InventoryStatus }) {
  const st = STATUS_STYLE[status];
  return (
    <span
      style={{
        display: 'inline-flex',
        alignItems: 'center',
        gap: 6,
        fontSize: 12,
        padding: '3px 9px',
        borderRadius: 20,
        fontWeight: 500,
        background: st.bg,
        color: st.fg,
      }}
    >
      <span style={{ width: 7, height: 7, borderRadius: '50%', background: st.dot }} />
      {STATUS_LABEL[status]}
    </span>
  );
}

function DeltaBadge({ deltaPct }: { deltaPct: number | null }) {
  if (deltaPct === null) return <span style={{ color: 'var(--text-muted)' }}>new</span>;
  const up = deltaPct >= 0;
  return (
    <span style={{ color: up ? 'var(--success)' : 'var(--danger)' }}>
      {up ? '▲ +' : '▼ '}
      {deltaPct}%
    </span>
  );
}
