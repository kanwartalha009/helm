import type { CSSProperties } from 'react';

/**
 * Animated shimmer placeholder (Kanwar, 2026-07-21). Used while a lazily-loaded
 * report section is pending (off-screen or fetching) so the page has stable
 * height and reads as actively loading rather than blank or a bare "Loading…".
 * The shimmer itself is the shared `.skeleton` class in globals.css (theme-aware).
 */
export function Skeleton({
  width = '100%',
  height = 14,
  radius = 8,
  style,
}: {
  width?: number | string;
  height?: number | string;
  radius?: number | string;
  style?: CSSProperties;
}) {
  return <div className="skeleton" style={{ width, height, borderRadius: radius, ...style }} aria-hidden="true" />;
}

/**
 * A section-sized loading block — a couple of header lines over a table-ish
 * grid of shimmer rows. Approximates the height of a real section card so the
 * layout doesn't jump when the real content swaps in.
 */
export function SectionSkeleton({ rows = 4 }: { rows?: number }) {
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 10, padding: '4px 0' }} aria-busy="true">
      <Skeleton width={180} height={16} />
      <Skeleton width="100%" height={12} />
      <div style={{ display: 'flex', flexDirection: 'column', gap: 8, marginTop: 6 }}>
        {Array.from({ length: rows }).map((_, i) => (
          <Skeleton key={i} width="100%" height={28} />
        ))}
      </div>
    </div>
  );
}
