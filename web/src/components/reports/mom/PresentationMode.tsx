import { useEffect, useLayoutEffect, useRef, useState, type ReactNode } from 'react';

/**
 * REV2 R6 (monthly-report-v2-mom.md) — "PRESENTATION MODE (in-platform
 * slideshow for client meetings). A 'Present' button on the report view (and
 * on share links): renders the SAME resolved sections as full-screen slides —
 * title slide..., then one slide per enabled section in layout order,
 * commentary/To-Do rendered as a footer strip (toggleable), keyboard ←/→ +
 * click zones + Esc, slide counter, progress dots. Implementation: same
 * section components in a full-screen shell (CSS transform/scroll-snap; no
 * new deps, no reveal.js)."
 *
 * `renderSection` is injected by the caller rather than hard-coded to
 * MomSectionCard, so BOTH call sites can share this one component: the
 * authenticated report page (MomReportPage, section fetch via useMomSection)
 * and the public share view (M5 addendum, section fetch via the token-gated
 * public endpoint — no Sanctum auth). Same slideshow shell either way.
 *
 * Presentation polish (Kanwar, 2026-07-17): a per-slide brand header (brand +
 * section + month + agency) for a professional client-meeting look; a real
 * browser Fullscreen toggle (the Fullscreen API, so it fills the display, not
 * just the viewport); and a wider content column so wide MoM matrices use the
 * horizontal space instead of leaving big empty margins. The "view full table"
 * detail popup now sits above this overlay (globals.css .modal-backdrop z-index)
 * so it works while presenting.
 */
export interface PresentationSlideSection {
  key: string;
  label: string;
}

export function PresentationMode({
  open,
  onClose,
  brandName,
  agencyName,
  monthLabel,
  sections,
  renderSection,
}: {
  open: boolean;
  onClose: () => void;
  brandName: string;
  agencyName: string;
  monthLabel: string;
  sections: PresentationSlideSection[];
  renderSection: (section: PresentationSlideSection) => ReactNode;
}) {
  const slideCount = sections.length + 1; // +1 title slide
  const [index, setIndex] = useState(0);
  const containerRef = useRef<HTMLDivElement>(null);
  const [isFullscreen, setIsFullscreen] = useState(false);

  // Always re-open on the title slide, never wherever the last session left off.
  useEffect(() => {
    if (open) setIndex(0);
  }, [open]);

  useEffect(() => {
    if (!open) return;
    const handler = (e: KeyboardEvent) => {
      if (e.key === 'ArrowRight') setIndex((i) => Math.min(i + 1, slideCount - 1));
      else if (e.key === 'ArrowLeft') setIndex((i) => Math.max(i - 1, 0));
      else if (e.key === 'Escape' && !document.fullscreenElement) onClose();
    };
    window.addEventListener('keydown', handler);
    return () => window.removeEventListener('keydown', handler);
  }, [open, slideCount, onClose]);

  // Keep the button state in sync when the user leaves fullscreen with F11/Esc.
  useEffect(() => {
    const onFs = () => setIsFullscreen(!!document.fullscreenElement);
    document.addEventListener('fullscreenchange', onFs);
    return () => document.removeEventListener('fullscreenchange', onFs);
  }, []);

  // Exit the browser fullscreen when the presentation itself closes.
  useEffect(() => {
    if (!open && document.fullscreenElement) document.exitFullscreen?.().catch(() => undefined);
  }, [open]);

  if (!open) return null;

  const next = () => setIndex((i) => Math.min(i + 1, slideCount - 1));
  const prev = () => setIndex((i) => Math.max(i - 1, 0));

  const toggleFullscreen = () => {
    const el = containerRef.current;
    if (document.fullscreenElement) {
      document.exitFullscreen?.().catch(() => undefined);
    } else {
      el?.requestFullscreen?.().catch(() => undefined);
    }
  };

  const ctrlBtn: React.CSSProperties = {
    background: 'rgba(0,0,0,0.06)', border: 'none', borderRadius: 6,
    height: 32, cursor: 'pointer', fontSize: 13, lineHeight: 1, padding: '0 10px',
    display: 'inline-flex', alignItems: 'center', gap: 6, color: '#0c0a09',
  };

  return (
    <div ref={containerRef} style={{ position: 'fixed', inset: 0, zIndex: 2000, background: '#fff', overflow: 'hidden' }}>
      <div style={{ position: 'absolute', top: 14, right: 14, zIndex: 10, display: 'flex', gap: 8 }}>
        <button onClick={toggleFullscreen} style={ctrlBtn} aria-label="Toggle fullscreen">
          {isFullscreen ? '⤢ Exit full screen' : '⤢ Full screen'}
        </button>
        <button onClick={onClose} aria-label="Exit presentation" style={{ ...ctrlBtn, width: 32, justifyContent: 'center', padding: 0, fontSize: 18 }}>
          ×
        </button>
      </div>

      {/* Click zones — thin edge strips, not a full overlay, so buttons/links/
          the commentary editor inside a slide's own section card stay clickable. */}
      <div
        onClick={prev}
        aria-label="Previous slide"
        style={{ position: 'absolute', left: 0, top: 0, bottom: 0, width: '8%', zIndex: 5, cursor: index > 0 ? 'pointer' : 'default' }}
      />
      <div
        onClick={next}
        aria-label="Next slide"
        style={{ position: 'absolute', right: 0, top: 0, bottom: 0, width: '8%', zIndex: 5, cursor: index < slideCount - 1 ? 'pointer' : 'default' }}
      />

      <div
        style={{
          display: 'flex',
          height: '100%',
          width: `${slideCount * 100}vw`,
          transform: `translateX(-${index * 100}vw)`,
          transition: 'transform 0.3s ease',
        }}
      >
        {/* Title slide */}
        <div
          style={{
            width: '100vw', flexShrink: 0, height: '100%',
            display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center',
            textAlign: 'center', padding: 24,
          }}
        >
          <div className="muted text-sm" style={{ letterSpacing: 1.5, textTransform: 'uppercase' }}>{agencyName}</div>
          <h1 style={{ fontSize: 44, margin: '14px 0', fontWeight: 700 }}>{brandName}</h1>
          <div className="muted" style={{ fontSize: 20 }}>MoM Strategy Report — {monthLabel}</div>
        </div>

        {/* One slide per section: a brand header strip, then the section content
            in a wide centred column so the matrices use the horizontal space. */}
        {sections.map((s) => (
          <div key={s.key} style={{ width: '100vw', flexShrink: 0, height: '100%', display: 'flex', flexDirection: 'column' }}>
            <div
              style={{
                display: 'flex', alignItems: 'baseline', justifyContent: 'space-between', gap: 16,
                padding: '18px 56px 12px', borderBottom: '1px solid #ECECEC', flexShrink: 0,
              }}
            >
              <div style={{ display: 'flex', alignItems: 'baseline', gap: 12, minWidth: 0 }}>
                <span style={{ fontSize: 15, fontWeight: 700 }}>{brandName}</span>
                <span className="muted" style={{ fontSize: 13, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{s.label}</span>
              </div>
              <div className="muted text-sm" style={{ whiteSpace: 'nowrap' }}>{monthLabel} · {agencyName}</div>
            </div>
            <FitSlide>{renderSection(s)}</FitSlide>
          </div>
        ))}
      </div>

      <div style={{ position: 'absolute', bottom: 14, left: 0, right: 0, display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 8, pointerEvents: 'none' }}>
        <div className="muted text-sm">{index + 1} / {slideCount}</div>
        <div style={{ display: 'flex', gap: 5, pointerEvents: 'auto' }}>
          {Array.from({ length: slideCount }).map((_, i) => (
            <div
              key={i}
              onClick={() => setIndex(i)}
              style={{
                width: 6, height: 6, borderRadius: 999, cursor: 'pointer',
                background: i === index ? '#1f6f5c' : 'rgba(0,0,0,0.15)',
              }}
            />
          ))}
        </div>
      </div>
    </div>
  );
}

/**
 * Fit-to-slide (Kanwar, 2026-07-17 — "some slides scroll, some have a lot of
 * empty space; optimise"). Measures the section content against the available
 * slide area and scales it down to fit so nothing scrolls, centring it
 * vertically so short sections don't leave dead space at the bottom. When the
 * content is so tall that fitting it would shrink text below readability
 * (< 50%), it falls back to a natural scroll at full size instead of clipping.
 */
function FitSlide({ children }: { children: ReactNode }) {
  const wrapRef = useRef<HTMLDivElement>(null);
  const contentRef = useRef<HTMLDivElement>(null);
  const [t, setT] = useState<{ scale: number; top: number; scroll: boolean }>({ scale: 1, top: 0, scroll: false });

  useLayoutEffect(() => {
    const wrap = wrapRef.current;
    const content = contentRef.current;
    if (!wrap || !content) return;
    const measure = () => {
      const availH = wrap.clientHeight - 48; // leave room above the progress dots
      const availW = wrap.clientWidth;
      const ch = content.offsetHeight;
      const cw = content.offsetWidth;
      if (ch <= 0 || cw <= 0 || availH <= 0) return;
      const ideal = Math.min(1, availH / ch, availW / cw);
      const FLOOR = 0.5; // don't shrink text below half — scroll instead of clipping
      if (ideal >= FLOOR) {
        setT({ scale: ideal, top: Math.max(24, (availH - ch * ideal) / 2), scroll: false });
      } else {
        setT({ scale: 1, top: 0, scroll: true });
      }
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
  }, []);

  return (
    <div ref={wrapRef} style={{ flex: 1, position: 'relative', overflowY: t.scroll ? 'auto' : 'hidden', overflowX: 'hidden' }}>
      <div
        ref={contentRef}
        style={
          t.scroll
            ? { width: 'min(1320px, 94%)', margin: '24px auto 56px' }
            : {
                position: 'absolute',
                top: t.top,
                left: '50%',
                width: 'min(1320px, 94%)',
                transform: `translateX(-50%) scale(${t.scale})`,
                transformOrigin: 'top center',
                transition: 'transform 0.15s ease, top 0.15s ease',
              }
        }
      >
        {children}
      </div>
    </div>
  );
}

