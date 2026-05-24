import { useToastStore, type ToastVariant } from '@/stores/toastStore';
import { cn } from '@/lib/cn';

const ICON: Record<ToastVariant, JSX.Element> = {
  success: (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
      <polyline points="20 6 9 17 4 12" />
    </svg>
  ),
  error: (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
      <circle cx="12" cy="12" r="10" />
      <line x1="15" y1="9" x2="9" y2="15" />
      <line x1="9" y1="9" x2="15" y2="15" />
    </svg>
  ),
  info: (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
      <circle cx="12" cy="12" r="10" />
      <line x1="12" y1="16" x2="12" y2="12" />
      <line x1="12" y1="8" x2="12.01" y2="8" />
    </svg>
  ),
};

const ACCENT: Record<ToastVariant, string> = {
  success: 'var(--success)',
  error: 'var(--danger)',
  info: 'var(--text-secondary)',
};

export function Toaster() {
  const toasts = useToastStore((s) => s.toasts);
  const dismiss = useToastStore((s) => s.dismiss);

  if (toasts.length === 0) return null;

  return (
    <div
      style={{
        position: 'fixed',
        right: 20,
        bottom: 20,
        zIndex: 1000,
        display: 'flex',
        flexDirection: 'column',
        gap: 10,
        maxWidth: 380,
      }}
    >
      {toasts.map((t) => (
        <div
          key={t.id}
          className={cn('card')}
          style={{
            padding: '12px 14px',
            display: 'flex',
            alignItems: 'flex-start',
            gap: 10,
            background: 'var(--surface)',
          }}
        >
          <span style={{ color: ACCENT[t.variant], marginTop: 1, flexShrink: 0 }}>
            {ICON[t.variant]}
          </span>
          <div style={{ flex: 1, minWidth: 0 }}>
            <div style={{ fontSize: 13.5, fontWeight: 500, color: 'var(--text)' }}>{t.title}</div>
            {t.description && (
              <div style={{ fontSize: 12.5, color: 'var(--text-secondary)', marginTop: 2 }}>
                {t.description}
              </div>
            )}
          </div>
          <button
            onClick={() => dismiss(t.id)}
            style={{
              background: 'transparent',
              border: 0,
              cursor: 'pointer',
              color: 'var(--text-muted)',
              fontSize: 16,
              padding: 0,
              marginTop: -2,
            }}
            aria-label="Dismiss"
          >
            ×
          </button>
        </div>
      ))}
    </div>
  );
}
