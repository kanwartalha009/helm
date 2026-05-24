import { useEffect, type ReactNode } from 'react';
import { cn } from '@/lib/cn';

interface DrawerProps {
  open: boolean;
  onClose: () => void;
  title?: ReactNode;
  size?: 'sm' | 'lg';
  footer?: ReactNode;
  children: ReactNode;
}

/**
 * Right-anchored slide-in panel. Used for multi-step wizards that are
 * heavier than a modal but lighter than a full route — keeps the user
 * grounded on whatever they navigated from.
 */
export function Drawer({ open, onClose, title, size = 'sm', footer, children }: DrawerProps) {
  useEffect(() => {
    if (!open) return;
    const handler = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
    };
    window.addEventListener('keydown', handler);
    // Lock body scroll while open.
    const prev = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      window.removeEventListener('keydown', handler);
      document.body.style.overflow = prev;
    };
  }, [open, onClose]);

  // `inert` is the modern replacement for aria-hidden on closed dialogs —
  // it BOTH prevents focus AND signals to assistive tech, so React doesn't
  // warn about a hidden ancestor of a focused element. TypeScript's DOM lib
  // doesn't typed inert on every host yet, so we pass it via the
  // bracket-key form to silence the prop check.
  const closedProps = open
    ? {}
    : ({ inert: '' as any } as Record<string, unknown>);

  return (
    <>
      <div
        className={cn('drawer-backdrop', open && 'open')}
        onClick={onClose}
        {...closedProps}
      />
      <aside
        className={cn('drawer', size === 'lg' && 'wide', open && 'open')}
        role="dialog"
        aria-modal="true"
        {...closedProps}
      >
        {title && (
          <header className="drawer-header">
            <span className="drawer-title">{title}</span>
            <button
              type="button"
              onClick={onClose}
              aria-label="Close"
              style={{
                width: 28,
                height: 28,
                border: 0,
                background: 'transparent',
                cursor: 'pointer',
                color: 'var(--text-muted)',
                fontSize: 18,
                display: 'inline-flex',
                alignItems: 'center',
                justifyContent: 'center',
                borderRadius: 4,
              }}
            >
              ×
            </button>
          </header>
        )}
        <div className="drawer-body">{children}</div>
        {footer && <footer className="drawer-footer">{footer}</footer>}
      </aside>
    </>
  );
}
