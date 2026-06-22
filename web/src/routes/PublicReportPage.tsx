import { useParams } from 'react-router-dom';
import { ReportDocument } from '@/components/reports/ReportDocument';
import { usePublicReport } from '@/hooks/useReports';

/**
 * Public, read-only report at /r/:token — what a client opens from a link Bosco
 * sent. No app shell, no auth: the unguessable token is the gate. Read-only, so
 * the commentary renders as saved (not editable).
 */
export function PublicReportPage() {
  const { token } = useParams();
  const { data, isLoading, isError } = usePublicReport(token);

  return (
    <div style={{ minHeight: '100vh', background: '#efeee9', padding: '28px 16px' }}>
      <style>{`@media print{.rpt-public-bar{display:none}}`}</style>
      <div
        className="rpt-public-bar"
        style={{ maxWidth: 1040, margin: '0 auto 16px', display: 'flex', justifyContent: 'flex-end' }}
      >
        {data && (
          <button
            onClick={() => window.print()}
            style={{
              fontSize: 12,
              padding: '7px 14px',
              borderRadius: 8,
              border: '1px solid #ccc9c2',
              background: '#fff',
              color: '#0f0f0f',
              cursor: 'pointer',
            }}
          >
            Save as PDF
          </button>
        )}
      </div>

      {isLoading && (
        <div style={{ textAlign: 'center', color: '#767676', padding: 64 }}>Loading report…</div>
      )}
      {isError && (
        <div style={{ textAlign: 'center', color: '#767676', padding: 64 }}>
          This report link is invalid or has expired.
        </div>
      )}
      {data && <ReportDocument data={data} editable={false} />}
    </div>
  );
}
