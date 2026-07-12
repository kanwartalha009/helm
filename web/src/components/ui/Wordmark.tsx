import { Link } from 'react-router-dom';
import type { CSSProperties } from 'react';
import { APP_NAME } from '@/lib/branding';

/**
 * Product wordmark. `name` lets authenticated surfaces pass the live workspace
 * agency name (white-label); when absent it falls back to the build-time
 * APP_NAME, so pre-login pages and un-branded workspaces still render a name.
 */
export function Wordmark({ to = '/', style, name }: { to?: string; style?: CSSProperties; name?: string | null }) {
  return (
    <Link to={to} className="wordmark" style={style}>
      <span className="wordmark-dot" />
      {name || APP_NAME}
    </Link>
  );
}
