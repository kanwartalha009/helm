import type { ReactNode } from 'react';

interface PageHeaderProps {
  title: ReactNode;
  subtitle?: ReactNode;
  actions?: ReactNode;
  leading?: ReactNode;
}

export function PageHeader({ title, subtitle, actions, leading }: PageHeaderProps) {
  return (
    <div className="page-header">
      <div className={leading ? 'flex items-center gap-12' : undefined}>
        {leading}
        <div>
          <h2 className="page-title">{title}</h2>
          {subtitle && <p className="page-subtitle">{subtitle}</p>}
        </div>
      </div>
      {actions && <div className="flex items-center gap-8">{actions}</div>}
    </div>
  );
}
