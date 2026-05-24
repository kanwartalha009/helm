import { useUiStore } from '@/stores/uiStore';
import { Button, Tag } from '@/components/ui';
import type { ReactNode } from 'react';

interface TopbarProps {
  title: ReactNode;
  tag?: ReactNode;
  actions?: ReactNode;
}

export function Topbar({ title, tag, actions }: TopbarProps) {
  const setPaletteOpen = useUiStore((s) => s.setPaletteOpen);
  const setAddBrandOpen = useUiStore((s) => s.setAddBrandDrawerOpen);

  return (
    <header className="app-topbar">
      <div className="flex items-center gap-12">
        <h3 style={{ fontSize: 15, fontWeight: 500 }}>{title}</h3>
        {tag && <Tag>{tag}</Tag>}
      </div>
      <div className="flex items-center gap-8">
        <Button
          size="sm"
          variant="secondary"
          onClick={() => setPaletteOpen(true)}
          leftIcon={
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75">
              <path d="M21 21l-4.35-4.35" />
              <circle cx="11" cy="11" r="7" />
            </svg>
          }
          rightIcon={<span className="kbd">⌘K</span>}
          style={{ gap: 8 }}
        >
          Search
        </Button>
        {actions ?? (
          <Button
            size="sm"
            variant="primary"
            onClick={() => setAddBrandOpen(true)}
            leftIcon={
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <path d="M12 5v14M5 12h14" />
              </svg>
            }
          >
            Add brand
          </Button>
        )}
      </div>
    </header>
  );
}
