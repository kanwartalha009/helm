import { useBrandsQuality } from '@/hooks/useDataQuality';

const COLOR: Record<string, string> = {
  good: 'var(--success, #1f6f5c)',
  ok:   'var(--warning, #9a6700)',
  poor: 'var(--danger, #b3261e)',
};

/**
 * Data-quality chip on the dashboard brand row (GO-1.3).
 *
 * It reads a SEPARATE endpoint (useBrandsQuality) and looks the brand up by id, rather
 * than being threaded through the dashboard row payload. That is deliberate: the
 * dashboard runs two engines behind the `helm:dashboard-parity` gate, and adding a
 * field to that payload would mean changing both engines identically and re-proving
 * parity. React Query caches the one extra request across every row.
 *
 * Renders nothing until the scores land, so it can never delay or break the table.
 */
export function QualityDot({ brandId }: { brandId: number }) {
  const { data } = useBrandsQuality();
  const row = data?.rows.find((r) => r.brandId === brandId);

  if (!row) return null;

  const color = COLOR[row.tier] ?? 'var(--text-muted)';
  const title = row.meetsGate
    ? `Data quality ${row.score}/100 — good enough for recommendations.`
    : `Data quality ${row.score}/100 — below the ${data?.threshold ?? 70} threshold, so Helm won't make recommendations for this brand yet. Open the brand to see what's missing.`;

  return (
    <span title={title} style={{ display: 'inline-flex', alignItems: 'center', gap: 3 }}>
      <span aria-hidden style={{ width: 6, height: 6, borderRadius: '50%', background: color, flexShrink: 0 }} />
      <span style={{ color: row.meetsGate ? undefined : color }}>{row.score}</span>
    </span>
  );
}
