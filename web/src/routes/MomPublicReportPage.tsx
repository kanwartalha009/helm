import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { Button } from '@/components/ui';
import { usePublicMomShell } from '@/hooks/useMomReport';
import { PresentationMode } from '@/components/reports/mom/PresentationMode';
import { PublicMomSectionCard } from '@/components/reports/mom/PublicMomSectionCard';

/**
 * Public, read-only mom report at /mom/r/:token — the client-facing twin of
 * PublicReportPage.tsx, adapted for mom's section-streamed shape (M0's own
 * architecture, see MomShareController's docblock — mom has no equivalent of
 * v1's single publicShow() payload). No app shell, no auth: the unguessable
 * token is the gate, same contract as v1's /r/:token.
 *
 * Unlike v1's publicShow (which rebuilds the WHOLE report and can hold the
 * page back with a "being updated" screen when freshness is stale), mom's
 * shell is a SNAPSHOT of the resolved section manifest at share-creation time
 * (M1's share-immunity rule) — each section's own live rebuild is what's
 * fresh, and a section that isn't 'ok' this pass just auto-hides
 * (PublicMomSectionCard) rather than holding back the entire page.
 */
export function MomPublicReportPage() {
  const { token } = useParams();
  const { data: shell, isLoading, isError } = usePublicMomShell(token);
  const [presenting, setPresenting] = useState(false);

  // S-GOALS moved into the executive overview as goal cards (Kanwar,
  // 2026-07-15) — never a standalone section, incl. on shared links whose
  // snapshot layout may still list it.
  const enabled = shell?.sections.filter((s) => s.enabled && s.key !== 'S-GOALS') ?? [];

  return (
    <div style={{ minHeight: '100vh', background: '#efeee9', padding: '28px 16px' }}>
      <style>{`@media print{.mom-public-bar{display:none}}`}</style>

      {isLoading && (
        <div style={{ textAlign: 'center', color: '#767676', padding: 64 }}>Loading report…</div>
      )}
      {isError && (
        <div style={{ textAlign: 'center', color: '#767676', padding: 64 }}>
          This report link is invalid or has expired.
        </div>
      )}

      {shell && (
        <div style={{ maxWidth: 1040, margin: '0 auto', display: 'flex', flexDirection: 'column', gap: 14 }}>
          <div
            className="mom-public-bar"
            style={{ display: 'flex', alignItems: 'baseline', justifyContent: 'space-between', gap: 12 }}
          >
            <div>
              <div className="muted text-sm" style={{ letterSpacing: 1, textTransform: 'uppercase' }}>
                {shell.brand.name}
              </div>
              <h2 style={{ margin: 0, fontSize: 20 }}>MoM Strategy Report</h2>
            </div>
            <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
              <span className="muted text-sm">{shell.month.label}</span>
              <Button size="sm" variant="secondary" type="button" onClick={() => setPresenting(true)}>
                Present
              </Button>
            </div>
          </div>

          {enabled.map((section) => (
            <PublicMomSectionCard key={section.key} token={token!} section={section} currency={shell.currency} />
          ))}

          <PresentationMode
            open={presenting}
            onClose={() => setPresenting(false)}
            brandName={shell.brand.name}
            agencyName={shell.branding?.agency_name || 'Roasdriven'}
            monthLabel={shell.month.label}
            sections={enabled}
            // Client-facing share deck: only slides with real data, closing on a
            // "Thank you" slide (Kanwar, 2026-07-17).
            skipEmpty
            showThankYou
            renderSection={(s) => {
              const full = enabled.find((e) => e.key === s.key)!;
              return <PublicMomSectionCard token={token!} section={full} currency={shell.currency} />;
            }}
          />
        </div>
      )}
    </div>
  );
}
