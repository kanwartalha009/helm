import { useEffect, useLayoutEffect, useRef, useState, type ReactNode } from 'react';

/**
 * REV2 R6 (monthly-report-v2-mom.md) — in-platform slideshow for client
 * meetings: a title slide, then one slide per enabled section, keyboard ←/→ +
 * click zones + Esc, slide counter and progress dots. Same section components
 * in a full-screen shell (no reveal.js).
 *
 * `renderSection` is injected by the caller so BOTH the authenticated report
 * (MomReportDocument) and the public share view (MomPublicReportPage) share this
 * one shell.
 *
 * Polish (Kanwar, 2026-07-17): a per-slide brand header for a professional look;
 * a real browser Fullscreen toggle rendered as an ICON so it never overlaps the
 * header text; fit-to-slide sizing so nothing scrolls and short slides don't
 * leave dead space. Share links additionally SKIP empty sections (only slides
 * with real data) and close on a "Thank you" slide.
 */
export interface PresentationSlideSection {
  key: string;
  label: string;
}

const EMPTY_PX = 60; // a slide whose content is shorter than this has no real data

export function PresentationMode({
  open,
  onClose,
  brandName,
  agencyName,
  monthLabel,
  sections,
  renderSection,
  skipEmpty = false,
  showThankYou = false,
}: {
  open: boolean;
  onClose: () => void;
  brandName: string;
  agencyName: string;
  monthLabel: string;
  sections: PresentationSlideSection[];
  renderSection: (section: PresentationSlideSection) => ReactNode;
  /** Share links: hide slides whose section returned no data. */
  skipEmpty?: boolean;
  /** Share links: append a closing "Thank you" slide. */
  showThankYou?: boolean;
}) {
  const containerRef = useRef<HTMLDivElement>(null);
  const [index, setIndex] = useState(0);
  const [isFullscreen, setIsFullscreen] = useState(false);
  const [heights, setHeights] = useState<Record<string, number>>({});

  // Raw slide layout: 0 = title, 1..N = sections, then an optional Thank-you slide.
  const rawCount = 1 + sections.length + (showThankYou ? 1 : 0);
  const thankYouRaw = showThankYou ? rawCount - 1 : -1;

  const isEmptyRaw = (raw: number): boolean => {
    if (!skipEmpty) return false;
    if (raw === 0 || raw === thankYouRaw) return false;
    const s = sections[raw - 1];
    if (!s) return false;
    const h = heights[s.key];
    return h !== undefined && h < EMPTY_PX;
  };

  // The slides actually shown (raw indices), skipping measured-empty sections.
  const visible: number[] = [];
  for (let i = 0; i < rawCount; i++) if (!isEmptyRaw(i)) visible.push(i);

  // Always re-open on the title slide.
  useEffect(() => {
    if (open) setIndex(0);
  }, [open]);

  // If the current slide became empty (data loaded late), hop to the nearest
  // visible slide so the viewer never lands on a hidden one.
  useEffect(() => {
    if (!open || visible.length === 0 || visible.includes(index)) return;
    const fallback = [...visible].reverse().find((v) => v <= index) ?? visible[0];
    setIndex(fallback);
  }, [open, index, visible]);

  const pos = Math.max(0, visible.indexOf(index));
  const go = (delta: number) => {
    const nextPos = Math.min(Math.max(pos + delta, 0), visible.length - 1);
    setIndex(visible[nextPos]);
  };

  useEffect(() => {
    if (!open) return;
    const handler = (e: KeyboardEvent) => {
      if (e.key === 'ArrowRight') go(1);
      else if (e.key === 'ArrowLeft') go(-1);
      else if (e.key === 'Escape' && !document.fullscreenElement) onClose();
    };
    window.addEventListener('keydown', handler);
    return () => window.removeEventListener('keydown', handler);
  }, [open, pos, visible.length, onClose]);

  useEffect(() => {
    const onFs = () => setIsFullscreen(!!document.fullscreenElement);
    document.addEventListener('fullscreenchange', onFs);
    return () => document.removeEventListener('fullscreenchange', onFs);
  }, []);

  useEffect(() => {
    if (!open && document.fullscreenElement) document.exitFullscreen?.().catch(() => undefined);
  }, [open]);

  if (!open) return null;

  const toggleFullscreen = () => {
    if (document.fullscreenElement) document.exitFullscreen?.().catch(() => undefined);
    else containerRef.current?.requestFullscreen?.().catch(() => undefined);
  };

  const iconBtn: React.CSSProperties = {
    background: 'rgba(0,0,0,0.06)', border: 'none', borderRadius: 6,
    width: 34, height: 34, cursor: 'pointer', display: 'inline-flex',
    alignItems: 'center', justifyContent: 'center', color: '#0c0a09',
  };

  const reportHeight = (key: string, h: number) =>
    setHeights((prev) => (prev[key] === h ? prev : { ...prev, [key]: h }));

  return (
    <div ref={containerRef} style={{ position: 'fixed', inset: 0, zIndex: 2000, background: '#fff', overflow: 'hidden' }}>
      {/* Icon controls, top-right (Kanwar, 2026-07-17 — icons, no text overlap). */}
      <div style={{ position: 'absolute', top: 14, right: 14, zIndex: 10, display: 'flex', gap: 8 }}>
        <button onClick={toggleFullscreen} style={iconBtn} title={isFullscreen ? 'Exit full screen' : 'Full screen'} aria-label={isFullscreen ? 'Exit full screen' : 'Full screen'}>
          {isFullscreen ? <CompressIcon /> : <ExpandIcon />}
        </button>
        <button onClick={onClose} style={iconBtn} title="Exit presentation" aria-label="Exit presentation">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round"><path d="M6 6l12 12M18 6L6 18" /></svg>
        </button>
      </div>

      {/* Edge click zones (thin, so buttons/links inside a slide stay clickable). */}
      <div onClick={() => go(-1)} aria-label="Previous slide" style={{ position: 'absolute', left: 0, top: 0, bottom: 0, width: '8%', zIndex: 5, cursor: pos > 0 ? 'pointer' : 'default' }} />
      <div onClick={() => go(1)} aria-label="Next slide" style={{ position: 'absolute', right: 0, top: 0, bottom: 0, width: '8%', zIndex: 5, cursor: pos < visible.length - 1 ? 'pointer' : 'default' }} />

      <div
        style={{
          display: 'flex',
          height: '100%',
          width: `${rawCount * 100}vw`,
          transform: `translateX(-${index * 100}vw)`,
          transition: 'transform 0.3s ease',
        }}
      >
        {/* Title slide */}
        <div style={{ width: '100vw', flexShrink: 0, height: '100%', display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', textAlign: 'center', padding: 24 }}>
          <div className="muted text-sm" style={{ letterSpacing: 1.5, textTransform: 'uppercase' }}>{agencyName}</div>
          <h1 style={{ fontSize: 44, margin: '14px 0', fontWeight: 700 }}>{brandName}</h1>
          <div className="muted" style={{ fontSize: 20 }}>MoM Strategy Report — {monthLabel}</div>
        </div>

        {/* Section slides: brand header + fit-to-slide content. */}
        {sections.map((s) => (
          <div key={s.key} style={{ width: '100vw', flexShrink: 0, height: '100%', display: 'flex', flexDirection: 'column' }}>
            <div style={{ display: 'flex', alignItems: 'baseline', justifyContent: 'space-between', gap: 16, padding: '18px 132px 12px 56px', borderBottom: '1px solid #ECECEC', flexShrink: 0 }}>
              <div style={{ display: 'flex', alignItems: 'baseline', gap: 12, minWidth: 0 }}>
                <span style={{ fontSize: 15, fontWeight: 700 }}>{brandName}</span>
                <span className="muted" style={{ fontSize: 13, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{s.label}</span>
              </div>
              <div className="muted text-sm" style={{ whiteSpace: 'nowrap' }}>{monthLabel} · {agencyName}</div>
            </div>
            <FitSlide onHeight={(h) => reportHeight(s.key, h)}>{renderSection(s)}</FitSlide>
          </div>
        ))}

        {/* Closing "Thank you" slide (share links). */}
        {showThankYou && (
          <div style={{ width: '100vw', flexShrink: 0, height: '100%', display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', textAlign: 'center', padding: 24 }}>
            <h1 style={{ fontSize: 52, margin: 0, fontWeight: 700 }}>Thank you</h1>
            <div className="muted" style={{ fontSize: 20, marginTop: 14 }}>{brandName} · {monthLabel}</div>
            <div className="muted text-sm" style={{ marginTop: 6, letterSpacing: 1, textTransform: 'uppercase' }}>{agencyName}</div>
          </div>
        )}
      </div>

      <div style={{ position: 'absolute', bottom: 14, left: 0, right: 0, display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 8, pointerEvents: 'none' }}>
        <div className="muted text-sm">{pos + 1} / {visible.length}</div>
        <div style={{ display: 'flex', gap: 5, pointerEvents: 'auto' }}>
          {visible.map((raw, i) => (
            <div
              key={raw}
              onClick={() => setIndex(raw)}
              style={{ width: 6, height: 6, borderRadius: 999, cursor: 'pointer', background: i === pos ? '#1f6f5c' : 'rgba(0,0,0,0.15)' }}
            />
          ))}
        </div>
      </div>
    </div>
  );
}

function ExpandIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round">
      <path d="M8 3H5a2 2 0 0 0-2 2v3M16 3h3a2 2 0 0 1 2 2v3M8 21H5a2 2 0 0 1-2-2v-3M16 21h3a2 2 0 0 0 2-2v-3" />
    </svg>
  );
}

function CompressIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round">
      <path d="M8 3v3a2 2 0 0 1-2 2H3M16 3v3a2 2 0 0 0 2 2h3M8 21v-3a2 2 0 0 0-2-2H3M16 21v-3a2 2 0 0 1 2-2h3" />
    </svg>
  );
}

/**
 * Fit-to-slide (Kanwar, 2026-07-17). Scales section content to fit the slide so
 * nothing scrolls, centring short content so it doesn't leave dead space; falls
 * back to a natural scroll only when fitting would shrink text below readability.
 * Reports the content's natural height so the shell can skip empty slides.
 */
function FitSlide({ children, onHeight }: { children: ReactNode; onHeight?: (h: number) => void }) {
  const wrapRef = useRef<HTMLDivElement>(null);
  const contentRef = useRef<HTMLDivElement>(null);
  const [t, setT] = useState<{ scale: number; top: number; scroll: boolean }>({ scale: 1, top: 0, scroll: false });

  useLayoutEffect(() => {
    const wrap = wrapRef.current;
    const content = contentRef.current;
    if (!wrap || !content) return;
    const measure = () => {
      const availH = wrap.clientHeight - 48;
      const availW = wrap.clientWidth;
      const ch = content.offsetHeight;
      const cw = content.offsetWidth;
      onHeight?.(ch);
      if (ch <= 0 || cw <= 0 || availH <= 0) return;
      const ideal = Math.min(1, availH / ch, availW / cw);
      const FLOOR = 0.5;
      if (ideal >= FLOOR) setT({ scale: ideal, top: Math.max(24, (availH - ch * ideal) / 2), scroll: false });
      else setT({ scale: 1, top: 0, scroll: true });
    };
    measure();
    const ro = new ResizeObserver(measure);
    ro.observe(content);
    ro.observe(wrap);
    window.addEventListener('resize', measure);
    return () => {
      ro.disconnect();
      window.removeEventListener('resize', measure);
    };
  }, [onHeight]);

  return (
    <div ref={wrapRef} style={{ flex: 1, position: 'relative', overflowY: t.scroll ? 'auto' : 'hidden', overflowX: 'hidden' }}>
      <div
        ref={contentRef}
        style={
          t.scroll
            ? { width: 'min(1320px, 94%)', margin: '24px auto 56px' }
            : { position: 'absolute', top: t.top, left: '50%', width: 'min(1320px, 94%)', transform: `translateX(-50%) scale(${t.scale})`, transformOrigin: 'top center', transition: 'transform 0.15s ease, top 0.15s ease' }
        }
      >
        {children}
      </div>
    </div>
  );
}
