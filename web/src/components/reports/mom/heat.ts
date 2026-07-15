/**
 * M5 addendum (Kanwar, 2026-07-15 — "mark table coloured to see the numbers
 * precisely") — the grading math ported from v1's MonthlyReportDocument.tsx
 * (gradeCol / heatClass / roasHeat), so mom's tables read with the SAME
 * heat-map language the agency already knows from v1, not a second
 * convention invented for v2. Returns a grade key ('' | 'g1'|'g2'|'g3'|
 * 'r1'|'r2'|'r3') rather than a CSS class name — HeatTable maps that key to
 * an inline style, matching this codebase's existing mom-component
 * convention (StatTile/charts.tsx use inline styles, not injected CSS).
 */
import type { CSSProperties } from 'react';

export type HeatGrade = '' | 'g1' | 'g2' | 'g3' | 'r1' | 'r2' | 'r3';

export const HEAT_STYLE: Record<Exclude<HeatGrade, ''>, { background: string; color?: string; fontWeight?: number }> = {
  g1: { background: '#f2f7f3' },
  g2: { background: '#e3efe7', color: '#1c6b45' },
  g3: { background: '#d2e7da', color: '#1c6b45', fontWeight: 600 },
  r1: { background: '#fbf3f2' },
  r2: { background: '#f4e4e1', color: '#a83a31' },
  r3: { background: '#eccfc9', color: '#a83a31', fontWeight: 600 },
};

export function heatCellStyle(grade: HeatGrade | undefined | null): CSSProperties {
  if (!grade) return {};
  return HEAT_STYLE[grade];
}

/**
 * Grade a value against the rest of its column, min-max normalised — ported
 * verbatim from v1's gradeCol. `dir` flips it for cost metrics (CAC/CPM/CPC)
 * where LOWER is better. Only grades when the column has >= 3 comparable
 * values and real spread — a column of 1-2 rows, or all-equal values, stays
 * unshaded rather than producing a meaningless extreme.
 */
export function gradeColumn(v: number | null | undefined, values: (number | null | undefined)[], dir: 'high' | 'low' = 'high'): HeatGrade {
  if (v === null || v === undefined) return '';
  const xs = values.filter((x): x is number => x !== null && x !== undefined && Number.isFinite(x));
  if (xs.length < 3) return '';
  const min = Math.min(...xs);
  const max = Math.max(...xs);
  if (max === min) return '';
  let t = (v - min) / (max - min);
  if (dir === 'low') t = 1 - t;
  if (t >= 0.82) return 'g3';
  if (t >= 0.6) return 'g2';
  if (t > 0.52) return 'g1';
  if (t <= 0.18) return 'r3';
  if (t <= 0.4) return 'r2';
  if (t < 0.48) return 'r1';
  return '';
}

/** Grade a month-over-month % delta directly — ported from v1's heatClass, which took cur/prev; this takes the already-computed delta so it works whether the delta came from a raw pair or a backend-precomputed *Pct field. */
export function heatFromDeltaPct(deltaPct: number | null | undefined): HeatGrade {
  if (deltaPct === null || deltaPct === undefined) return '';
  const d = deltaPct / 100;
  if (d >= 0.5) return 'g3';
  if (d >= 0.15) return 'g2';
  if (d > 0.02) return 'g1';
  if (d <= -0.5) return 'r3';
  if (d <= -0.15) return 'r2';
  if (d < -0.02) return 'r1';
  return '';
}

/** Grade a value against a fixed benchmark ratio — ported from v1's roasHeat (e.g. country ROAS vs the brand's blended ROAS). */
export function heatVsBenchmark(v: number | null | undefined, benchmark: number | null | undefined): HeatGrade {
  if (v === null || v === undefined || !benchmark) return '';
  const r = v / benchmark;
  if (r >= 1.5) return 'g3';
  if (r >= 1.15) return 'g2';
  if (r > 1.02) return 'g1';
  if (r <= 0.5) return 'r3';
  if (r <= 0.85) return 'r2';
  if (r < 0.98) return 'r1';
  return '';
}
