import { useState } from 'react';
import { Link } from 'react-router-dom';
import { ANOMALY_LABEL, useAnomalyFeed } from '@/hooks/useAnomalies';

const SEVERITY_COLOR: Record<string, string> = {
  critical: 'var(--danger, #b3261e)',
  warn:     'var(--warning, #9a6700)',
  info:     'var(--text-secondary)',
};

/**
 * Open-anomaly bell (GO-2.4). Reads a side endpoint and shows the count of OPEN
 * anomalies across every brand the user can see.
 *
 * Renders nothing at all when there is nothing wrong — a permanently-lit bell is a
 * bell people stop looking at, and the whole value of this feed is that it only speaks
 * when it has something checkable to say.
 */
export function AnomalyBell() {
  const { data } = useAnomalyFeed();
  const [open, setOpen] = useState(false);

  if (!data || data.open === 0) return null;

  const worst = data.rows.some((r) => r.severity === 'critical') ? 'critical' : 'warn';
  const color = SEVERITY_COLOR[worst];

  return (
    <div style={{ position: 'relative' }}>
      <button
        type="button"
        onClick={() => setOpen(!open)}
        title={`${data.open} open ${data.open === 1 ? 'anomaly' : 'anomalies'}`}
        style={{
          display: 'inline-flex', alignItems: 'center', gap: 5, cursor: 'pointer',
          background: 'none', border: '1px solid var(--border)', borderRadius: 8,
          padding: '5px 9px', color,
          fontSize: 12, fontWeight: 600,
        }}
      >
        <span aria-hidden style={{ width: 7, height: 7, borderRadius: '50%', background: color }} />
        {data.open}
      </button>

      {open && (
        <div
          style={{
            position: 'absolute', right: 0, top: 'calc(100% + 6px)', zIndex: 50,
            width: 380, maxHeight: 420, overflowY: 'auto',
            background: 'var(--surface)', border: '1px solid var(--border)',
            borderRadius: 10, padding: 12,
          }}
        >
          <div className="flex items-center justify-between mb-8">
            <span style={{ fontWeight: 600, fontSize: 13 }}>Open anomalies</span>
            <button type="button" className="muted text-xs" style={{ background: 'none', border: 0, cursor: 'pointer' }} onClick={() => setOpen(false)}>close</button>
          </div>

          <div style={{ display: 'grid', gap: 9 }}>
            {data.rows.map((a) => (
              <Link
                key={a.id}
                to={`/brands/${a.brand?.slug ?? ''}`}
                onClick={() => setOpen(false)}
                style={{ display: 'block', borderLeft: `3px solid ${SEVERITY_COLOR[a.severity]}`, paddingLeft: 8 }}
              >
                <div className="text-sm" style={{ fontWeight: 600 }}>{a.brand?.name}</div>
                <div className="muted text-xs">
                  {ANOMALY_LABEL[a.kind] ?? a.kind}{a.subject ? ` · ${a.subject}` : ''} · {a.date}
                </div>
              </Link>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}
