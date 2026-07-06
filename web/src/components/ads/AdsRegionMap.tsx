import { useEffect, useMemo, useRef } from 'react';
import { ADS_MAP } from './worldMapData';
import type { AdsCountryRow } from '@/types/ads';

/**
 * Performance-by-region map: a light world base (real land geometry, Europe-
 * framed Mercator) with a data dot per country sized by spend, and wheel/drag/
 * button zoom + pan. Country dots are placed by an ISO-2 centroid lookup baked
 * into worldMapData.ts, so the projection always matches the base. Countries we
 * have no centroid for (rare, long-tail) simply don't plot a dot — they still
 * appear in the table beside the map.
 */
export function AdsRegionMap({ rows }: { rows: AdsCountryRow[] }) {
  const svgRef = useRef<SVGSVGElement>(null);
  const gRef = useRef<SVGGElement>(null);

  const dots = useMemo(() => {
    const maxSpend = Math.max(1, ...rows.map((r) => r.spend));
    return rows
      .map((r) => {
        const c = ADS_MAP.centroids[r.key.toUpperCase()];
        if (!c) return null;
        return { x: c[0], y: c[1], r: 4 + Math.sqrt(Math.max(r.spend, 0) / maxSpend) * 9, key: r.key };
      })
      .filter((d): d is { x: number; y: number; r: number; key: string } => d !== null);
  }, [rows]);

  useEffect(() => {
    const svg = svgRef.current;
    const g = gRef.current;
    if (!svg || !g) return;

    let scale = 1;
    let tx = 0;
    let ty = 0;
    const MIN = 0.12;
    const MAX = 12;
    const apply = () => g.setAttribute('transform', `translate(${tx.toFixed(2)} ${ty.toFixed(2)}) scale(${scale.toFixed(4)})`);

    const toView = (clientX: number, clientY: number) => {
      const pt = svg.createSVGPoint();
      pt.x = clientX;
      pt.y = clientY;
      const ctm = svg.getScreenCTM();
      if (!ctm) return { x: 0, y: 0 };
      const p = pt.matrixTransform(ctm.inverse());
      return { x: p.x, y: p.y };
    };
    const zoomAt = (px: number, py: number, f: number) => {
      const next = Math.min(MAX, Math.max(MIN, scale * f));
      const k = next / scale;
      tx = px - (px - tx) * k;
      ty = py - (py - ty) * k;
      scale = next;
      apply();
    };

    const onWheel = (e: WheelEvent) => {
      e.preventDefault();
      const p = toView(e.clientX, e.clientY);
      zoomAt(p.x, p.y, e.deltaY < 0 ? 1.15 : 1 / 1.15);
    };
    let drag: { x: number; y: number; tx: number; ty: number } | null = null;
    const onDown = (e: PointerEvent) => {
      drag = { x: e.clientX, y: e.clientY, tx, ty };
      try {
        svg.setPointerCapture(e.pointerId);
      } catch {
        /* ignore */
      }
      svg.classList.add('grabbing');
    };
    const onMove = (e: PointerEvent) => {
      if (!drag) return;
      const ctm = svg.getScreenCTM();
      const sx = ctm ? ctm.a : 1;
      const sy = ctm ? ctm.d : 1;
      tx = drag.tx + (e.clientX - drag.x) / sx;
      ty = drag.ty + (e.clientY - drag.y) / sy;
      apply();
    };
    const onUp = () => {
      drag = null;
      svg.classList.remove('grabbing');
    };

    const vb = svg.viewBox.baseVal;
    const cx = vb.width / 2;
    const cy = vb.height / 2;
    const zin = svg.parentElement?.querySelector('[data-z="in"]') ?? null;
    const zout = svg.parentElement?.querySelector('[data-z="out"]') ?? null;
    const inFn = () => zoomAt(cx, cy, 1.4);
    const outFn = () => zoomAt(cx, cy, 1 / 1.4);

    svg.addEventListener('wheel', onWheel, { passive: false });
    svg.addEventListener('pointerdown', onDown);
    svg.addEventListener('pointermove', onMove);
    svg.addEventListener('pointerup', onUp);
    svg.addEventListener('pointercancel', onUp);
    zin?.addEventListener('click', inFn);
    zout?.addEventListener('click', outFn);
    apply();

    return () => {
      svg.removeEventListener('wheel', onWheel);
      svg.removeEventListener('pointerdown', onDown);
      svg.removeEventListener('pointermove', onMove);
      svg.removeEventListener('pointerup', onUp);
      svg.removeEventListener('pointercancel', onUp);
      zin?.removeEventListener('click', inFn);
      zout?.removeEventListener('click', outFn);
    };
  }, []);

  return (
    <div className="amap-wrap">
      <svg ref={svgRef} className="amap-svg" viewBox={ADS_MAP.viewBox} preserveAspectRatio="xMidYMid slice">
        <rect x={-2000} y={-2000} width={6000} height={6000} fill="#EEF3FB" />
        <g ref={gRef}>
          <path d={ADS_MAP.land} fill="#C6DBF4" stroke="#A9C6EC" strokeWidth={0.5} strokeLinejoin="round" />
          {dots.map((d) => (
            <circle key={d.key} cx={d.x} cy={d.y} r={d.r} fill="#2F6BE8" fillOpacity={0.8} stroke="#fff" strokeWidth={0.9} />
          ))}
        </g>
      </svg>
      <div className="amap-zoom">
        <button type="button" data-z="in" aria-label="Zoom in">+</button>
        <button type="button" data-z="out" aria-label="Zoom out">−</button>
      </div>
      <span className="amap-attr">Regions · Meta</span>
    </div>
  );
}
