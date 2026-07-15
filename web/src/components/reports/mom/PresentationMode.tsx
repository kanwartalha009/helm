import { useEffect, useState, type ReactNode } from 'react';

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
 * "Commentary/To-Do as a toggleable footer strip": MomSectionCard already
 * renders its own "Commentary & To-Do" toggle + editor beneath the
 * chart/table (M2) — reusing that component as-is here satisfies the same
 * intent (toggleable, per-slide, not always-on) without a second commentary
 * UI to keep in sync with the first. The public share renderer (read-only,
 * no editor) shows commentary already-written, same toggle affordance.
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

  // Always re-open on the title slide, never wherever the last session left off.
  useEffect(() => {
    if (open) setIndex(0);
  }, [open]);

  useEffect(() => {
    if (!open) return;
    const handler = (e: KeyboardEvent) => {
      if (e.key === 'ArrowRight') setIndex((i) => Math.min(i + 1, slideCount - 1));
      else if (e.key === 'ArrowLeft') setIndex((i) => Math.max(i - 1, 0));
      else if (e.key === 'Escape') onClose();
    };
    window.addEventListener('keydown', handler);
    return () => window.removeEventListener('keydown', handler);
  }, [open, slideCount, onClose]);

  if (!open) return null;

  const next = () => setIndex((i) => Math.min(i + 1, slideCount - 1));
  const prev = () => setIndex((i) => Math.max(i - 1, 0));

  return (
    <div style={{ position: 'fixed', inset: 0, zIndex: 2000, background: '#fff', overflow: 'hidden' }}>
      <button
        onClick={onClose}
        aria-label="Exit presentation"
        style={{
          position: 'absolute', top: 14, right: 14, zIndex: 10,
          background: 'rgba(0,0,0,0.06)', border: 'none', borderRadius: 6,
          width: 32, height: 32, cursor: 'pointer', fontSize: 18, lineHeight: 1,
        }}
      >
        ×
      </button>

      {/* Click zones — thin edge strips, not a full overlay, so buttons/links/
          the commentary editor inside a slide's own section card stay clickable. */}
      <div
        onClick={prev}
        aria-label="Previous slide"
        style={{ position: 'absolute', left: 0, top: 0, bottom: 0, width: '12%', zIndex: 5, cursor: index > 0 ? 'pointer' : 'default' }}
      />
      <div
        onClick={next}
        aria-label="Next slide"
        style={{ position: 'absolute', right: 0, top: 0, bottom: 0, width: '12%', zIndex: 5, cursor: index < slideCount - 1 ? 'pointer' : 'default' }}
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

        {sections.map((s) => (
          <div key={s.key} style={{ width: '100vw', flexShrink: 0, height: '100%', overflowY: 'auto', padding: '56px 72px' }}>
            <div style={{ maxWidth: 1100, margin: '0 auto' }}>{renderSection(s)}</div>
          </div>
        ))}
      </div>

      <div style={{ position: 'absolute', bottom: 16, left: 0, right: 0, display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 8, pointerEvents: 'none' }}>
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
