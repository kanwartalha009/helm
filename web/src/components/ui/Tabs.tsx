import { useState, type ReactNode } from 'react';
import { cn } from '@/lib/cn';

interface Tab {
  id: string;
  label: ReactNode;
  content: ReactNode;
}

interface TabsProps {
  tabs: Tab[];
  defaultTabId?: string;
}

export function Tabs({ tabs, defaultTabId }: TabsProps) {
  const [activeId, setActiveId] = useState(defaultTabId ?? tabs[0]?.id);
  return (
    <>
      <div className="tabs">
        {tabs.map((tab) => (
          <a
            key={tab.id}
            className={cn('tab', tab.id === activeId && 'active')}
            onClick={(e) => {
              e.preventDefault();
              setActiveId(tab.id);
            }}
            href={`#${tab.id}`}
          >
            {tab.label}
          </a>
        ))}
      </div>
      {tabs.map((tab) => (
        <div key={tab.id} style={{ display: tab.id === activeId ? undefined : 'none' }}>
          {tab.content}
        </div>
      ))}
    </>
  );
}
