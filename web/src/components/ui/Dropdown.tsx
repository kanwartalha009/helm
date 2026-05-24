import { useEffect, useRef, useState, type ReactNode } from 'react';
import { cn } from '@/lib/cn';

interface DropdownProps {
  trigger: ReactNode;
  children: ReactNode;
  align?: 'left' | 'right';
  direction?: 'up' | 'down';
}

export function Dropdown({ trigger, children, align = 'left', direction = 'down' }: DropdownProps) {
  const [open, setOpen] = useState(false);
  const wrapRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;
    const handler = (e: MouseEvent) => {
      if (!wrapRef.current?.contains(e.target as Node)) setOpen(false);
    };
    window.addEventListener('mousedown', handler);
    return () => window.removeEventListener('mousedown', handler);
  }, [open]);

  return (
    <div className={cn('dropdown', open && 'open')} ref={wrapRef}>
      <div onClick={() => setOpen((v) => !v)}>{trigger}</div>
      <div className={cn('dropdown-menu', align === 'right' && 'right', direction)}>
        {children}
      </div>
    </div>
  );
}

export function DropdownItem({
  children,
  onClick,
  danger,
  href,
}: {
  children: ReactNode;
  onClick?: () => void;
  danger?: boolean;
  href?: string;
}) {
  const className = cn('dropdown-item', danger && 'danger');
  if (href) {
    return (
      <a href={href} className={className} onClick={onClick}>
        {children}
      </a>
    );
  }
  return (
    <button className={className} onClick={onClick}>
      {children}
    </button>
  );
}

export function DropdownDivider() {
  return <div className="dropdown-divider" />;
}

export function DropdownLabel({ children }: { children: ReactNode }) {
  return <div className="dropdown-label">{children}</div>;
}
