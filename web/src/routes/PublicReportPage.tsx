import { useParams } from 'react-router-dom';
import { ReportDocument } from '@/components/reports/ReportDocument';
import { MonthlyReportDocument } from '@/components/reports/MonthlyReportDocument';
import { WeeklyReportDocument } from '@/components/reports/WeeklyReportDocument';
import { CreativeReportDocument } from '@/components/reports/CreativeReportDocument';
import { usePublicReport } from '@/hooks/useReports';

/**
 * Public, read-only report at /r/:token — what a client opens from a link Bosco
 * sent. No app shell, no auth: the unguessable token is the gate. Read-only, so
 * the commentary renders as saved (not editable).
 */
export function PublicReportPage() {
  const { token } = useParams();
  const { data, isLoading, isError } = usePublicReport(token);

  // Freshness is recomputed live on every view (publicShow rebuilds the report),
  // so a client NEVER sees a stale/partial report — even if the operator created
  // the link while data was behind. No "show anyway" here: this is the client's
  // view (Bosco, 2026-06-30).
  const stale = !!data?.freshness && !data.freshness.upToDate;

  return (
    <div style={{ minHeight: '100vh', background: '#efeee9', padding: '28px 16px' }}>
      <style>{`@media print{.rpt-public-bar{display:none}}`}</style>
      <div
        className="rpt-public-bar"
        style={{ maxWidth: 1040, margin: '0 auto 16px', display: 'flex', justifyContent: 'flex-end' }}
      >
        {data && !stale && (
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
      {data &&
        (stale ? (
          <div
            style={{
              maxWidth: 1040,
              margin: '0 auto',
              background: '#fff',
              borderRadius: 12,
              padding: '64px 32px',
              textAlign: 'center',
            }}
          >
            <h2 style={{ color: '#0f0f0f', marginBottom: 10 }}>This report is being updated</h2>
            <p style={{ color: '#767676', maxWidth: 460, margin: '0 auto', lineHeight: 1.6 }}>
              The latest figures are still syncing, so we’re holding the report back rather than show
              you partial numbers. Please check this link again shortly.
            </p>
          </div>
        ) : data.reportType === 'monthly' ? (
          <MonthlyReportDocument data={data} editable={false} />
        ) : data.reportType === 'weekly' ? (
          <WeeklyReportDocument data={data} editable={false} />
        ) : data.reportType === 'creatives' ? (
          <CreativeReportDocument data={data} editable={false} />
        ) : (
          <ReportDocument data={data} editable={false} />
        ))}
    </div>
  );
}
