import { useEffect, type ReactNode } from 'react';
import { cn } from '@/lib/cn';

interface ModalProps {
  open: boolean;
  onClose: () => void;
  title?: ReactNode;
  size?: 'sm' | 'lg';
  children: ReactNode;
  footer?: ReactNode;
}

export function Modal({ open, onClose, title, size = 'sm', children, footer }: ModalProps) {
  useEffect(() => {
    if (!open) return;
    const handler = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
    };
    window.addEventListener('keydown', handler);
    return () => window.removeEventListener('keydown', handler);
  }, [open, onClose]);

  if (!open) return null;

  return (
    <div
      className={cn('modal-backdrop', open && 'open')}
      onClick={(e) => {
        if (e.target === e.currentTarget) onClose();
      }}
    >
      <div className={cn('modal', size === 'lg' && 'modal-lg')}>
        {title && (
          <div className="modal-header">
            <span className="modal-title">{title}</span>
            <button className="modal-close" onClick={onClose} aria-label="Close">
              ×
            </button>
          </div>
        )}
        <div className="modal-body">{children}</div>
        {footer && <div className="modal-footer">{footer}</div>}
      </div>
    </div>
  );
}
