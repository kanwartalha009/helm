import { Card } from '@/components/ui';
import type { MomNextStepItem, MomSectionManifestEntry } from '@/hooks/useMomReport';
import { usePublicMomSection } from '@/hooks/useMomReport';
import { SectionBody } from './MomSectionCard';

const GROUP_LABEL: Record<string, string> = {
  mes: 'This month',
  ads: 'Ads',
  countries: 'Countries',
  email: 'Email',
  cro: 'CRO',
};

interface S0PublicPayload {
  status: string;
  items?: MomNextStepItem[];
}

interface S19PublicPayload {
  status: string;
  body?: string;
  note?: string;
}

/**
 * M5 addendum (Kanwar, 2026-07-15 — public share links) — the read-only twin
 * of MomSectionCard for the token-gated public view. Reuses the SAME
 * SectionBody chart/table render path (exported from MomSectionCard.tsx) for
 * every metric section, so a client's link can never visually drift from
 * what the agency sees internally.
 *
 * "Shared view AUTO-HIDES incomplete sections" (M2/M5's no-empty-fields law):
 * a section whose live rebuild isn't 'ok' renders NOTHING here — no
 * "coming soon" placeholder, no backfill CTA (an internal-only affordance a
 * client has no use for, and MomShareController::publicSection() never
 * attaches the hint anyway). This is the one deliberate behavioral
 * difference from the authenticated card.
 *
 * S0/S19 (editorial sections) get their own small read-only renderers here
 * rather than reusing SNextStepsCard/SNovedadesCard directly — those own
 * components carry save mutations and edit affordances that have no place
 * on a public, unauthenticated link.
 */
export function PublicMomSectionCard({
  token,
  section,
  currency,
}: {
  token: string;
  section: MomSectionManifestEntry;
  currency: string;
}) {
  const { data, isLoading, isError } = usePublicMomSection(token, section.key);

  // Loading/error states render a quiet placeholder rather than nothing —
  // this is a genuine in-flight/network state, not the backend's own honest
  // non-'ok' status (which auto-hides below). Keeps the slide/scroll order
  // stable while the section is still resolving.
  if (isLoading) {
    return (
      <Card style={{ padding: 18, opacity: 0.5 }}>
        <div style={{ fontSize: 14, fontWeight: 650 }}>{section.label}</div>
      </Card>
    );
  }
  if (isError || !data) return null;
  if (data.status !== 'ok') return null;

  if (section.key === 'S0') return <PublicNextSteps label={section.label} payload={data as unknown as S0PublicPayload} />;
  if (section.key === 'S19') return <PublicNovedades label={section.label} payload={data as unknown as S19PublicPayload} />;

  return (
    <Card style={{ padding: 18 }}>
      <div style={{ fontSize: 14, fontWeight: 650, marginBottom: 10 }}>{section.label}</div>
      <SectionBody payload={data as Record<string, any>} sectionKey={section.key} view={section.view} currency={currency} />
    </Card>
  );
}

function PublicNextSteps({ label, payload }: { label: string; payload: S0PublicPayload }) {
  const items = (payload.items ?? []).filter((it) => it.status !== 'dropped' && it.text.trim() !== '');
  if (items.length === 0) return null;
  return (
    <Card style={{ padding: 18 }}>
      <div style={{ fontSize: 14, fontWeight: 650, marginBottom: 8 }}>{label}</div>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
        {items.map((it, i) => (
          <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13 }}>
            <span
              style={{
                fontSize: 10, textTransform: 'uppercase', letterSpacing: 0.5,
                color: it.status === 'done' ? '#1f6f5c' : '#9a9a9a', minWidth: 42,
              }}
            >
              {it.status === 'done' ? 'Done' : 'Open'}
            </span>
            <span className="muted text-sm" style={{ minWidth: 80 }}>{GROUP_LABEL[it.group] ?? it.group}</span>
            <span style={{ textDecoration: it.status === 'done' ? 'line-through' : undefined }}>{it.text}</span>
          </div>
        ))}
      </div>
    </Card>
  );
}

function PublicNovedades({ label, payload }: { label: string; payload: S19PublicPayload }) {
  if (!payload.body || payload.body.trim() === '') return null;
  return (
    <Card style={{ padding: 18 }}>
      <div style={{ fontSize: 14, fontWeight: 650, marginBottom: 8 }}>{label}</div>
      <div style={{ fontSize: 13, whiteSpace: 'pre-wrap', lineHeight: 1.6 }}>{payload.body}</div>
    </Card>
  );
}
