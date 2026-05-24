import { useEffect, useRef, useState, type ReactNode } from 'react';
import { cn } from '@/lib/cn';

interface PopoverProps {
  trigger: ReactNode;
  children: ReactNode;
  align?: 'left' | 'right';
  wide?: boolean;
}

export function Popover({ trigger, children, align = 'left', wide = false }: PopoverProps) {
  const [open, setOpen] = useState(false);
  const wrapRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;
    const handler = (e: MouseEvent) => {
      if (!wrapRef.current?.contains(e.target as Node)) setOpen(false);
    };
    const esc = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setOpen(false);
    };
    window.addEventListener('mousedown', handler);
    window.addEventListener('keydown', esc);
    return () => {
      window.removeEventListener('mousedown', handler);
      window.removeEventListener('keydown', esc);
    };
  }, [open]);

  return (
    <div className={cn('popover', open && 'open')} ref={wrapRef}>
      <div onClick={() => setOpen((v) => !v)}>{trigger}</div>
      <div className={cn('popover-panel', align === 'right' && 'right', wide && 'wide')}>
        {children}
      </div>
    </div>
  );
}

export function PopoverItem({
  active,
  meta,
  children,
  onClick,
}: {
  active?: boolean;
  meta?: ReactNode;
  children: ReactNode;
  onClick?: () => void;
}) {
  return (
    <button className={cn('popover-item', active && 'active')} onClick={onClick}>
      {children}
      {meta && <span className="meta">{meta}</span>}
    </button>
  );
}

export function PopoverLabel({ children }: { children: ReactNode }) {
  return <div className="popover-label">{children}</div>;
}

export function PopoverDivider() {
  return <div className="popover-divider" />;
}
