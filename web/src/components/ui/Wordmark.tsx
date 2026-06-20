import { Link } from 'react-router-dom';
import type { CSSProperties } from 'react';

export function Wordmark({ to = '/', style }: { to?: string; style?: CSSProperties }) {
  return (
    <Link to={to} className="wordmark" style={style}>
      <span className="wordmark-dot" />
      Roasdriven
    </Link>
  );
}
