/**
 * REV2 R1 (monthly-report-v2-mom.md) — "Charts in the report document =
 * lightweight self-contained SVG/div components (same convention as existing
 * report docs — they must render on public share links and print cleanly; do
 * NOT import the app's Recharts into the shared document)."
 *
 * Every component here is pure SVG + inline styles, zero chart-library
 * dependency, so it is safe to reuse inside a future public share document
 * without pulling Recharts into that bundle. One shared accent palette, plain
 * axes, no chart junk — per R1's own instruction.
 */
import { useEffect, useRef, useState } from 'react';

const ACCENT = '#3B5BFB';
const ACCENT_GHOST = '#B7C2FA'; // compare/prior-period series
const GRID = '#E7E9F0';
const POSITIVE = '#1F6F5C';
const NEGATIVE = '#B3261E';
const PALETTE = ['#3B5BFB', '#F5A524', '#1F6F5C', '#B3261E', '#7C4DFF', '#0EA5E9', '#EC4899', '#78716C'];

export function paletteColor(i: number): string {
  return PALETTE[i % PALETTE.length];
}

function fmtCompact(n: number): string {
  const abs = Math.abs(n);
  if (abs >= 1_000_000) return (n / 1_000_000).toFixed(1).replace(/\.0$/, '') + 'M';
  if (abs >= 1_000) return (n / 1_000).toFixed(1).replace(/\.0$/, '') + 'k';
  return String(Math.round(n));
}

/** Tiny inline trend line — used inside stat tiles (REV2 R4: "12-month sparkline"). */
export function Sparkline({
  values,
  width = 96,
  height = 28,
  color = ACCENT,
}: {
  values: (number | null)[];
  width?: number;
  height?: number;
  color?: string;
}) {
  const clean = values.map((v) => (v === null || Number.isNaN(v) ? null : v));
  const nums = clean.filter((v): v is number => v !== null);
  if (nums.length < 2) {
    return (
      <svg width={width} height={height} aria-hidden style={{ display: 'block' }}>
        <line x1={0} y1={height / 2} x2={width} y2={height / 2} stroke={GRID} strokeWidth={1} />
      </svg>
    );
  }

  const min = Math.min(...nums);
  const max = Math.max(...nums);
  const range = max - min || 1;
  const step = width / (clean.length - 1);

  let d = '';
  clean.forEach((v, i) => {
    if (v === null) return;
    const x = i * step;
    const y = height - ((v - min) / range) * height;
    d += (d === '' ? 'M' : 'L') + x.toFixed(1) + ',' + y.toFixed(1) + ' ';
  });

  const lastIdx = clean.length - 1 - [...clean].reverse().findIndex((v) => v !== null);
  const lastVal = clean[lastIdx];
  const lastX = lastIdx * step;
  const lastY = lastVal !== null ? height - ((lastVal - min) / range) * height : height / 2;

  return (
    <svg width={width} height={height} aria-hidden style={{ display: 'block' }}>
      <path d={d.trim()} fill="none" stroke={color} strokeWidth={1.5} strokeLinecap="round" strokeLinejoin="round" />
      <circle cx={lastX} cy={lastY} r={2} fill={color} />
    </svg>
  );
}

/**
 * Monthly trend line(s) with an optional GHOST (dashed) compare series — REV2
 * R3's "dashed/ghost compare series" convention, and R1's S1 chart twin
 * ("monthly trend lines Rev/Spend/ROAS").
 */
export function TrendLineChart({
  labels,
  series,
  compareSeries,
  height = 180,
  valueFormatter = fmtCompact,
  seriesLabel,
  compareLabel,
  seriesColor = ACCENT,
  compareColor = ACCENT_GHOST,
  compareDashed = true,
}: {
  labels: string[];
  series: (number | null)[];
  compareSeries?: (number | null)[] | null;
  height?: number;
  valueFormatter?: (n: number) => string;
  // When set, a small legend renders above the chart marking which line is
  // which — solid = `series`, dashed/ghost = `compareSeries`.
  seriesLabel?: string;
  compareLabel?: string;
  seriesColor?: string;
  compareColor?: string;
  compareDashed?: boolean;
}) {
  // Responsive: the chart draws at its CONTAINER's pixel width and a FIXED
  // (compact) pixel height — so the line always spreads to fill the width with
  // no right-side empty space AND never balloons in height when there are only
  // a few points (the two failure modes of a fixed viewBox + preserveAspectRatio).
  const ref = useRef<HTMLDivElement>(null);
  const [measured, setMeasured] = useState(600);
  useEffect(() => {
    const el = ref.current;
    if (!el || typeof ResizeObserver === 'undefined') return;
    const ro = new ResizeObserver((entries) => {
      const w = entries[0]?.contentRect.width ?? 0;
      if (w > 0) setMeasured(w);
    });
    ro.observe(el);
    return () => ro.disconnect();
  }, []);

  const width = Math.max(260, measured);
  const padL = 44;
  const padB = 20;
  const padT = 10;
  const innerW = width - padL - 8;
  const innerH = height - padT - padB;

  const all = [...series, ...(compareSeries ?? [])].filter((v): v is number => v !== null && !Number.isNaN(v));
  if (all.length === 0) {
    return <EmptyChart height={height} />;
  }
  const min = Math.min(0, ...all);
  const max = Math.max(...all) || 1;
  const range = max - min || 1;
  const step = labels.length > 1 ? innerW / (labels.length - 1) : 0;

  const toPath = (vals: (number | null)[]) => {
    let d = '';
    vals.forEach((v, i) => {
      if (v === null || Number.isNaN(v)) return;
      const x = padL + i * step;
      const y = padT + innerH - ((v - min) / range) * innerH;
      d += (d === '' ? 'M' : 'L') + x.toFixed(1) + ',' + y.toFixed(1) + ' ';
    });
    return d.trim();
  };

  // Label every gridline, not just top/bottom — so the y-axis scale is legible.
  const gridFracs = [0, 0.5, 1];
  const showLegend = !!seriesLabel || !!(compareSeries && compareLabel);

  return (
    <div ref={ref}>
      {showLegend && (
        <div style={{ display: 'flex', gap: 14, marginBottom: 4, fontSize: 11 }} className="muted">
          {seriesLabel && (
            <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5 }}>
              <span style={{ width: 16, height: 0, borderTop: `2px solid ${seriesColor}` }} />
              {seriesLabel}
            </span>
          )}
          {compareSeries && compareLabel && (
            <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5 }}>
              <span style={{ width: 16, height: 0, borderTop: `2px ${compareDashed ? 'dashed' : 'solid'} ${compareColor}` }} />
              {compareLabel}
            </span>
          )}
        </div>
      )}
      {/* viewBox matches the measured pixel size 1:1 → fills width, fixed
          compact height, no letterbox and no distortion. */}
      <svg width="100%" height={height} viewBox={`0 0 ${width} ${height}`} style={{ display: 'block' }} role="img">
      {gridFracs.map((f, i) => {
        const y = padT + innerH * f;
        return <line key={`g${i}`} x1={padL} y1={y} x2={width} y2={y} stroke={GRID} strokeWidth={1} />;
      })}
      {gridFracs.map((f, i) => (
        <text key={`t${i}`} x={0} y={padT + innerH * f + 4} fontSize={10} fill="var(--text-muted, #6b7280)">
          {valueFormatter(max - range * f)}
        </text>
      ))}

      {compareSeries && (
        <path
          d={toPath(compareSeries)}
          fill="none"
          stroke={compareColor}
          strokeWidth={compareDashed ? 1.5 : 2}
          strokeDasharray={compareDashed ? '4 3' : undefined}
          strokeLinecap="round"
          strokeLinejoin="round"
        />
      )}
      <path d={toPath(series)} fill="none" stroke={seriesColor} strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" />

      {series.map((v, i) =>
        v === null ? null : (
          <circle key={i} cx={padL + i * step} cy={padT + innerH - ((v - min) / range) * innerH} r={2.5} fill={seriesColor} />
        ),
      )}

      {labels.map((l, i) =>
        l === '' ? null : (
          <text
            key={i}
            x={padL + i * step}
            y={height - 4}
            fontSize={10}
            textAnchor="middle"
            fill="var(--text-muted, #6b7280)"
          >
            {l}
          </text>
        ),
      )}
      </svg>
    </div>
  );
}

/** Ranked horizontal bars — S5/S6 countries, S7/S8 categories/sellers chart twin. */
export function RankedBarChart({
  rows,
  height,
  barHeight = 22,
  valueFormatter = fmtCompact,
}: {
  rows: { label: string; value: number; deltaPct?: number | null; color?: string }[];
  height?: number;
  barHeight?: number;
  valueFormatter?: (n: number) => string;
}) {
  if (rows.length === 0) return <EmptyChart height={height ?? 120} />;
  const max = Math.max(...rows.map((r) => Math.abs(r.value)), 1);
  const h = height ?? rows.length * (barHeight + 8) + 8;

  return (
    <div style={{ width: '100%', minHeight: h }}>
      {rows.map((r, i) => {
        const pct = Math.max(2, (Math.abs(r.value) / max) * 100);
        const arrow = r.deltaPct === null || r.deltaPct === undefined ? null : r.deltaPct >= 0 ? '▲' : '▼';
        const arrowColor = r.deltaPct !== null && r.deltaPct !== undefined && r.deltaPct < 0 ? NEGATIVE : POSITIVE;
        return (
          <div key={r.label + i} style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 8, height: barHeight }}>
            <div style={{ width: 110, fontSize: 12, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }} title={r.label}>
              {r.label}
            </div>
            <div style={{ flex: 1, background: GRID, borderRadius: 4, height: barHeight - 8, position: 'relative' }}>
              <div
                style={{
                  width: `${pct}%`,
                  height: '100%',
                  borderRadius: 4,
                  background: r.color ?? ACCENT,
                  transition: 'width .2s ease',
                }}
              />
            </div>
            <div style={{ width: 66, fontSize: 12, textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>
              {valueFormatter(r.value)}
            </div>
            {arrow && (
              <div style={{ width: 34, fontSize: 11, color: arrowColor }}>
                {arrow} {r.deltaPct !== null && r.deltaPct !== undefined ? Math.abs(r.deltaPct).toFixed(0) : ''}%
              </div>
            )}
          </div>
        );
      })}
    </div>
  );
}

/** Stacked area of share-by-group over time — S4 tiers chart twin. */
export function StackedAreaChart({
  labels,
  series,
  height = 200,
}: {
  labels: string[];
  series: { key: string; label: string; values: number[]; color?: string }[];
  height?: number;
}) {
  if (labels.length === 0 || series.length === 0) return <EmptyChart height={height} />;
  const width = Math.max(280, labels.length * 64);
  const padL = 8;
  const padB = 34;
  const padT = 10;
  const innerW = width - padL - 8;
  const innerH = height - padT - padB;
  const step = labels.length > 1 ? innerW / (labels.length - 1) : innerW;

  // Stack cumulative sums per label index.
  const totals = labels.map((_, i) => series.reduce((s, ser) => s + (ser.values[i] ?? 0), 0)) || [1];
  const maxTotal = Math.max(...totals, 1);

  let cumulative = labels.map(() => 0);
  const layers = series.map((ser, si) => {
    const bottom = [...cumulative];
    const top = labels.map((_, i) => cumulative[i] + (ser.values[i] ?? 0));
    cumulative = top;

    const topPts = labels.map((_, i) => {
      const x = padL + i * step;
      const y = padT + innerH - (top[i] / maxTotal) * innerH;
      return `${x.toFixed(1)},${y.toFixed(1)}`;
    });
    const bottomPts = labels
      .map((_, i) => {
        const x = padL + i * step;
        const y = padT + innerH - (bottom[i] / maxTotal) * innerH;
        return `${x.toFixed(1)},${y.toFixed(1)}`;
      })
      .reverse();

    return { d: `M${topPts.join(' L')} L${bottomPts.join(' L')} Z`, color: ser.color ?? paletteColor(si), label: ser.label };
  });

  return (
    <div>
      <svg width="100%" height={height} viewBox={`0 0 ${width} ${height}`} preserveAspectRatio="xMinYMid meet" role="img">
        {layers.map((l) => (
          <path key={l.label} d={l.d} fill={l.color} fillOpacity={0.85} stroke="white" strokeWidth={0.5} />
        ))}
        {labels.map((l, i) => (
          <text key={l} x={padL + i * step} y={height - padB + 16} fontSize={10} textAnchor="middle" fill="var(--text-muted, #6b7280)">
            {l}
          </text>
        ))}
      </svg>
      <div style={{ display: 'flex', flexWrap: 'wrap', gap: 10, marginTop: 4 }}>
        {layers.map((l) => (
          <span key={l.label} style={{ display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: 11 }}>
            <span style={{ width: 8, height: 8, borderRadius: 2, background: l.color, display: 'inline-block' }} />
            {l.label}
          </span>
        ))}
      </div>
    </div>
  );
}

/** Donut chart with a legend — S7/S8/S13-S15 chart twins ("donut/stacked bars"). */
export function DonutChart({
  rows,
  size = 120,
  thickness = 18,
}: {
  rows: { label: string; value: number; color?: string }[];
  size?: number;
  thickness?: number;
}) {
  const total = rows.reduce((s, r) => s + Math.max(0, r.value), 0);
  if (total <= 0) return <EmptyChart height={size} />;

  const r = size / 2;
  const inner = r - thickness;
  let angle = -90;

  const arcs = rows.map((row, i) => {
    const frac = Math.max(0, row.value) / total;
    const sweep = frac * 360;
    const start = angle;
    const end = angle + sweep;
    angle = end;

    const largeArc = sweep > 180 ? 1 : 0;
    const toXY = (deg: number, radius: number) => {
      const rad = (deg * Math.PI) / 180;
      return [r + radius * Math.cos(rad), r + radius * Math.sin(rad)];
    };
    const [x1, y1] = toXY(start, r);
    const [x2, y2] = toXY(end, r);
    const [x3, y3] = toXY(end, inner);
    const [x4, y4] = toXY(start, inner);

    const d = `M ${x1.toFixed(1)} ${y1.toFixed(1)} A ${r} ${r} 0 ${largeArc} 1 ${x2.toFixed(1)} ${y2.toFixed(1)} L ${x3.toFixed(1)} ${y3.toFixed(1)} A ${inner} ${inner} 0 ${largeArc} 0 ${x4.toFixed(1)} ${y4.toFixed(1)} Z`;

    return { d, color: row.color ?? paletteColor(i), label: row.label, pct: (frac * 100).toFixed(0) };
  });

  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 14 }}>
      <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} role="img">
        {arcs.map((a) => (
          <path key={a.label} d={a.d} fill={a.color} />
        ))}
      </svg>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
        {arcs.map((a) => (
          <span key={a.label} style={{ display: 'inline-flex', alignItems: 'center', gap: 5, fontSize: 12 }}>
            <span style={{ width: 8, height: 8, borderRadius: 2, background: a.color, display: 'inline-block' }} />
            {a.label} <span className="muted">({a.pct}%)</span>
          </span>
        ))}
      </div>
    </div>
  );
}

export function DeltaChip({ pct, invert = false }: { pct: number | null | undefined; invert?: boolean }) {
  if (pct === null || pct === undefined || Number.isNaN(pct)) {
    return <span className="muted text-sm">—</span>;
  }
  const good = invert ? pct <= 0 : pct >= 0;
  const color = good ? POSITIVE : NEGATIVE;
  const arrow = pct >= 0 ? '▲' : '▼';
  return (
    <span style={{ color, fontSize: 12, fontWeight: 600, whiteSpace: 'nowrap' }}>
      {arrow} {Math.abs(pct).toFixed(1)}%
    </span>
  );
}

export function EmptyChart({ height = 120 }: { height?: number }) {
  return (
    <div
      style={{
        height,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        color: 'var(--text-muted, #6b7280)',
        fontSize: 12,
        border: `1px dashed ${GRID}`,
        borderRadius: 8,
      }}
    >
      Not enough data to chart yet
    </div>
  );
}

export { fmtCompact };
