import { useState, type CSSProperties } from 'react';
import { formatMoney, formatNumber, formatRoas } from '@/lib/formatters';
import type { InventoryAction, InventoryProduct, InventoryStatus } from '@/types/inventory';

// Design tokens are pulled from CSS variables so this stays in the Helm system
// (warm neutrals, single accent, no shadows). The header is near-black to match
// the approved mockup; everything else is 1px borders on --surface.
const STATUS_LABEL: Record<InventoryStatus, string> = { ok: 'OK', alert: 'Alert', pause: 'Pause' };

const STATUS_STYLE: Record<InventoryStatus, { bg: string; fg: string; dot: string }> = {
  ok:    { bg: 'var(--success-bg, #F0FDF4)', fg: '#166534', dot: 'var(--success)' },
  alert: { bg: 'var(--warning-bg, #FEF3C7)', fg: 'var(--warning)', dot: 'var(--warning)' },
  pause: { bg: 'var(--danger-bg, #FEF2F2)', fg: 'var(--danger)', dot: 'var(--danger)' },
};

// Action copy lives in the UI (the API returns only the enum). Colour follows
// urgency: out-of-stock is a hard stop, low-stock a warning, the rest neutral.
const ACTION_LABEL: Record<InventoryAction, string> = {
  out_of_stock: 'Out of stock — pause ads',
  low_stock: 'Low stock — reorder',
  no_spend: 'No Meta spend',
  ok: 'Stock OK',
};
const ACTION_COLOR: Record<InventoryAction, string> = {
  out_of_stock: 'var(--danger)',
  low_stock: 'var(--warning)',
  no_spend: 'var(--text-muted)',
  ok: 'var(--text-secondary)',
};

// Stock colour tiers mirror the status thresholds (≤0 danger, ≤20 warning).
function stockColor(status: InventoryStatus): string {
  return status === 'pause' ? 'var(--danger)' : status === 'alert' ? 'var(--warning)' : 'var(--success)';
}

const thBase: CSSProperties = {
  position: 'sticky',
  top: 0,
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

export function InventoryTable({
  products,
  currency,
}: {
  products: InventoryProduct[];
  currency: string;
}) {
  const [open, setOpen] = useState<Set<string>>(new Set());
  const toggle = (handle: string) =>
    setOpen((prev) => {
      const next = new Set(prev);
      if (next.has(handle)) next.delete(handle);
      else next.add(handle);
      return next;
    });

  const money = (v: number) => formatMoney(v, currency, { whole: true });

  return (
    <div
      style={{
        background: 'var(--surface)',
        border: '1px solid var(--border)',
        borderRadius: 'var(--radius-lg)',
        overflow: 'hidden',
      }}
    >
      <div style={{ overflowX: 'auto' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
          <thead>
            <tr>
              <th style={{ ...thL, width: 34 }}>#</th>
              <th style={thL}>Product</th>
              <th style={thBase}>Stock total</th>
              <th style={thBase}>Variants</th>
              <th style={thBase}>Units</th>
              <th style={thBase}>Units prev</th>
              <th style={thBase}>Meta spend</th>
              <th style={thBase}>Revenue</th>
              <th style={thBase}>ROAS blended</th>
              <th style={thBase}>Active ads</th>
              <th style={thL}>Status</th>
              <th style={thL}>Action</th>
            </tr>
          </thead>
          <tbody>
            {products.map((p, i) => {
              const isOpen = open.has(p.handle);
              const st = STATUS_STYLE[p.status];
              return (
                <ProductRows
                  key={p.handle}
                  p={p}
                  rank={i + 1}
                  isOpen={isOpen}
                  onToggle={() => toggle(p.handle)}
                  money={money}
                  statusStyle={st}
                />
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function ProductRows({
  p,
  rank,
  isOpen,
  onToggle,
  money,
  statusStyle,
}: {
  p: InventoryProduct;
  rank: number;
  isOpen: boolean;
  onToggle: () => void;
  money: (v: number) => string;
  statusStyle: { bg: string; fg: string; dot: string };
}) {
  return (
    <>
      <tr
        onClick={onToggle}
        style={{ cursor: 'pointer' }}
        onMouseEnter={(e) => (e.currentTarget.style.background = 'var(--surface-subtle)')}
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
              <div style={{ fontWeight: 500 }}>{p.title}</div>
              <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>{p.variantCount} variants</div>
            </div>
          </div>
        </td>
        <td style={{ ...tdBase, fontWeight: 600, color: stockColor(p.status) }}>
          {formatNumber(p.stock)}
        </td>
        <td style={tdBase}>{p.variantCount}</td>
        <td style={tdBase}>
          <div style={{ fontWeight: 600 }}>{formatNumber(p.units)}</div>
          <div style={{ fontSize: 11, fontWeight: 500, marginTop: 1 }}>
            <DeltaBadge deltaPct={p.deltaPct} />
          </div>
        </td>
        <td style={{ ...tdBase, color: 'var(--text-muted)' }}>{formatNumber(p.unitsPrev)}</td>
        <td style={tdBase}>
          {p.spend > 0 ? money(p.spend) : <span style={{ color: 'var(--text-muted)' }}>—</span>}
        </td>
        <td style={tdBase}>{money(p.revenue)}</td>
        <td style={{ ...tdBase, fontWeight: 600, color: p.roas != null && p.roas >= 3 ? 'var(--success)' : undefined }}>
          {p.roas != null ? formatRoas(p.roas) : <span style={{ color: 'var(--text-muted)' }}>—</span>}
        </td>
        <td style={tdBase}>{p.ads}</td>
        <td style={tdL}>
          <span
            style={{
              display: 'inline-flex',
              alignItems: 'center',
              gap: 6,
              fontSize: 12,
              padding: '3px 9px',
              borderRadius: 20,
              fontWeight: 500,
              background: statusStyle.bg,
              color: statusStyle.fg,
            }}
          >
            <span style={{ width: 7, height: 7, borderRadius: '50%', background: statusStyle.dot }} />
            {STATUS_LABEL[p.status]}
          </span>
        </td>
        <td style={{ ...tdL, fontSize: 12.5, color: ACTION_COLOR[p.action] }}>{ACTION_LABEL[p.action]}</td>
      </tr>

      {isOpen && (
        <tr>
          <td colSpan={12} style={{ padding: 0, background: 'var(--surface-subtle)', borderTop: '1px solid var(--border)' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse' }}>
              <tbody>
                {p.variants.length === 0 ? (
                  <tr>
                    <td style={{ padding: '10px 12px 10px 44px', fontSize: 12.5, color: 'var(--text-muted)' }}>
                      No variant detail available.
                    </td>
                  </tr>
                ) : (
                  p.variants.map((v, vi) => {
                    const low = v.q <= 0;
                    return (
                      <tr key={`${v.t}-${vi}`}>
                        <td
                          style={{
                            padding: '8px 12px 8px 44px',
                            borderTop: vi === 0 ? '1px dashed var(--border-strong, #D6D3D1)' : '1px solid var(--border)',
                            fontSize: 12.5,
                            color: 'var(--text-secondary)',
                            textAlign: 'left',
                          }}
                        >
                          {p.title} · {v.t}
                        </td>
                        <td
                          style={{
                            padding: '8px 12px',
                            borderTop: vi === 0 ? '1px dashed var(--border-strong, #D6D3D1)' : '1px solid var(--border)',
                            fontSize: 12.5,
                            textAlign: 'right',
                            fontVariantNumeric: 'tabular-nums',
                            width: 160,
                          }}
                        >
                          <span style={low ? { color: 'var(--danger)', fontWeight: 600 } : undefined}>
                            {formatNumber(v.q)}
                          </span>{' '}
                          <span style={{ color: 'var(--text-muted)', fontSize: 11 }}>in stock</span>
                        </td>
                        <td
                          style={{
                            borderTop: vi === 0 ? '1px dashed var(--border-strong, #D6D3D1)' : '1px solid var(--border)',
                          }}
                        />
                      </tr>
                    );
                  })
                )}
              </tbody>
            </table>
          </td>
        </tr>
      )}
    </>
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
