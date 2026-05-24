import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';

interface Crumb {
  label: ReactNode;
  to?: string;
}

export function Breadcrumb({ crumbs }: { crumbs: Crumb[] }) {
  return (
    <div className="breadcrumb">
      {crumbs.map((c, i) => (
        <span key={i} className="flex items-center gap-6">
          {c.to ? <Link to={c.to}>{c.label}</Link> : <span className="current">{c.label}</span>}
          {i < crumbs.length - 1 && <span className="sep">/</span>}
        </span>
      ))}
    </div>
  );
}
