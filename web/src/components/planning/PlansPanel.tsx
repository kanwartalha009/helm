import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Card } from '@/components/ui';
import { api } from '@/lib/api';
import { toast } from '@/stores/toastStore';

interface Moment {
  momentKey: string; market: string; label: string;
  startsOn: string; endsOn: string; kind: string; year: number;
}

interface PlanEntry {
  label: string;
  value: string;
  basis: 'Verified' | 'Proxy' | 'Modeled' | 'Source';
  source: string;
  detail: string | null;
}

interface PlanBlock { entries: PlanEntry[]; note?: string | null; blocked?: boolean; reason?: string }

interface Plan {
  id: number; title: string; status: string;
  blocks: Record<string, PlanBlock>;
  narrative: string | null;
  llmAvailable: boolean;
  note: string;
}

const BASIS_COLOR: Record<string, string> = {
  Verified: 'var(--success, #1f6f5c)',
  Proxy:    'var(--warning, #9a6700)',
  Modeled:  'var(--text-secondary)',
  Source:   'var(--text-muted)',
};

const BLOCK_TITLE: Record<string, string> = {
  timeline: 'Timeline',
  budget: 'Budget & CAC ceiling',
  channel: 'Channels',
  creative: 'Creative',
  measurement: 'Measurement',
};

/**
 * Seasonal campaign plans (GO-4.3) — the crown jewel.
 *
 * Every figure is rule-assembled from the brand's own data, the market calendar, or a
 * cited constant, and each one shows its BASIS (Verified / Proxy / Modeled / Source) plus
 * the source it came from. A client can point at any number and ask "where's that from?"
 * and get a real answer — which is the entire difference between this and the generic
 * advice everyone already distrusts.
 *
 * The AI narrative only ever REWRITES these figures as prose. It never produces one, and
 * it is stored separately from them.
 */
export function PlansPanel({ slug, canEdit }: { slug?: string; canEdit: boolean }) {
  const qc = useQueryClient();
  const [openId, setOpenId] = useState<number | null>(null);

  const { data: moments } = useQuery({
    queryKey: ['brand', slug, 'plan-moments'],
    enabled: !!slug,
    queryFn: async (): Promise<Moment[]> => {
      const { data } = await api.get<{ rows: Moment[] }>(`/brands/${slug}/plan-moments`);
      return data.rows;
    },
  });

  const { data: plans } = useQuery({
    queryKey: ['brand', slug, 'plans'],
    enabled: !!slug,
    queryFn: async (): Promise<{ id: number; title: string; status: string }[]> => {
      const { data } = await api.get<{ rows: { id: number; title: string; status: string }[] }>(`/brands/${slug}/plans`);
      return data.rows;
    },
  });

  const generate = useMutation({
    mutationFn: async (m: Moment) => {
      const { data } = await api.post<Plan>(`/brands/${slug}/plans`, {
        moment_key: m.momentKey, market: m.market, year: m.year,
      });
      return data;
    },
    onSuccess: (p) => {
      qc.invalidateQueries({ queryKey: ['brand', slug, 'plans'] });
      setOpenId(p.id);
      toast.success('Plan generated', 'Every number is traceable — check the basis on each line.');
    },
    onError: (e: unknown) => {
      // The refusals are first-class answers, not failures.
      const msg = (e as { response?: { data?: { reason?: string } } })?.response?.data?.reason;
      toast.error('Plan not generated', msg ?? 'Could not generate this plan.');
    },
  });

  if (!moments || moments.length === 0) {
    return (
      <Card style={{ padding: 16, marginTop: 16 }}>
        <div style={{ fontWeight: 600, marginBottom: 4 }}>Seasonal plans</div>
        <div className="muted text-sm">
          No upcoming market moments. Seed the calendar (<code>calendar:seed</code>) to plan against soldes,
          rebajas, Three Kings, Black Friday and the rest.
        </div>
      </Card>
    );
  }

  return (
    <>
      <Card style={{ padding: 16, marginTop: 16 }}>
        <div style={{ fontWeight: 600, marginBottom: 8 }}>Seasonal plans</div>

        <div className="muted text-xs mb-16" style={{ maxWidth: 760, lineHeight: 1.55 }}>
          Pick a moment and Helm assembles a plan from your own history, the market calendar and sourced
          planning constants. Every figure shows where it came from.
        </div>

        <div style={{ display: 'grid', gap: 8 }}>
          {moments.slice(0, 6).map((m) => (
            <div key={`${m.market}:${m.momentKey}`} className="flex items-center justify-between" style={{ gap: 8, flexWrap: 'wrap' }}>
              <span className="text-sm">
                <b>{m.market}</b> · {m.label}{' '}
                <span className="muted text-xs">{m.startsOn} – {m.endsOn}</span>
                {m.kind === 'legal_sale' && (
                  <span className="text-xs" style={{ color: 'var(--warning, #9a6700)' }}> · fixed by law</span>
                )}
              </span>
              {canEdit && (
                <Button size="sm" variant="secondary" disabled={generate.isPending} onClick={() => generate.mutate(m)}>
                  Generate plan
                </Button>
              )}
            </div>
          ))}
        </div>

        {(plans?.length ?? 0) > 0 && (
          <div style={{ marginTop: 14, borderTop: '1px solid var(--border)', paddingTop: 10 }}>
            <div className="text-xs muted mb-8">Existing plans</div>
            <div style={{ display: 'grid', gap: 5 }}>
              {plans!.map((p) => (
                <button
                  key={p.id}
                  type="button"
                  className="text-sm"
                  style={{ background: 'none', border: 0, cursor: 'pointer', textAlign: 'left', color: 'var(--accent)' }}
                  onClick={() => setOpenId(openId === p.id ? null : p.id)}
                >
                  {p.title} <span className="muted text-xs">· {p.status}</span>
                </button>
              ))}
            </div>
          </div>
        )}
      </Card>

      {openId && <PlanDetail slug={slug} id={openId} canEdit={canEdit} />}
    </>
  );
}

function PlanDetail({ slug, id, canEdit }: { slug?: string; id: number; canEdit: boolean }) {
  const qc = useQueryClient();

  const { data: plan } = useQuery({
    queryKey: ['brand', slug, 'plan', id],
    queryFn: async (): Promise<Plan> => {
      const { data } = await api.get<Plan>(`/brands/${slug}/plans/${id}`);
      return data;
    },
  });

  const narrate = useMutation({
    mutationFn: async () => {
      const { data } = await api.post<{ narrative: string }>(`/brands/${slug}/plans/${id}/narrate`);
      return data;
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['brand', slug, 'plan', id] });
      toast.success('Narrative written', 'The AI rewrote your plan — it did not change a single number.');
    },
    onError: () => toast.error('Could not write the narrative', 'The plan itself is complete without it.'),
  });

  if (!plan) return null;

  return (
    <Card style={{ padding: 18, marginTop: 12 }}>
      <div className="flex items-center justify-between mb-16" style={{ flexWrap: 'wrap', gap: 8 }}>
        <div style={{ fontWeight: 700, fontSize: 16 }}>{plan.title}</div>
        {canEdit && plan.llmAvailable && (
          <Button size="sm" variant="secondary" disabled={narrate.isPending} onClick={() => narrate.mutate()}>
            {narrate.isPending ? 'Writing…' : plan.narrative ? 'Rewrite narrative' : 'Write client narrative'}
          </Button>
        )}
      </div>

      <div style={{ display: 'grid', gap: 18 }}>
        {Object.entries(plan.blocks).map(([name, block]) => (
          <div key={name}>
            <div className="text-xs" style={{ fontWeight: 700, letterSpacing: '.06em', textTransform: 'uppercase', color: 'var(--text-secondary)', marginBottom: 6 }}>
              {BLOCK_TITLE[name] ?? name}
            </div>

            {/* A blocked block states what's missing rather than inventing a number. */}
            {block.blocked ? (
              <div className="muted text-sm" style={{ lineHeight: 1.55 }}>{block.reason}</div>
            ) : (
              <>
                {block.note && (
                  <div className="text-xs" style={{ color: 'var(--warning, #9a6700)', fontWeight: 600, marginBottom: 6 }}>
                    {block.note}
                  </div>
                )}
                <div style={{ display: 'grid', gap: 7 }}>
                  {block.entries.map((e, i) => (
                    <div key={i}>
                      <div className="flex items-center justify-between text-sm" style={{ gap: 8, flexWrap: 'wrap' }}>
                        <span className="muted">{e.label}</span>
                        <span className="flex items-center gap-8">
                          <span style={{ fontWeight: 600 }}>{e.value}</span>
                          {/* The basis — this is what makes the number checkable. */}
                          <span
                            className="text-xs"
                            title={e.source}
                            style={{ color: BASIS_COLOR[e.basis], fontWeight: 600, borderBottom: '1px dotted currentColor', cursor: 'help' }}
                          >
                            {e.basis}
                          </span>
                        </span>
                      </div>
                      {e.detail && <div className="muted text-xs" style={{ marginTop: 1, lineHeight: 1.5 }}>{e.detail}</div>}
                    </div>
                  ))}
                </div>
              </>
            )}
          </div>
        ))}
      </div>

      {plan.narrative && (
        <div style={{ marginTop: 20, borderTop: '1px solid var(--border)', paddingTop: 14 }}>
          <div className="text-xs" style={{ fontWeight: 700, letterSpacing: '.06em', textTransform: 'uppercase', color: 'var(--text-secondary)', marginBottom: 6 }}>
            Client narrative <span style={{ fontWeight: 400, textTransform: 'none', letterSpacing: 0 }}>· AI-written prose · the numbers above are unchanged</span>
          </div>
          <div className="text-sm" style={{ whiteSpace: 'pre-wrap', lineHeight: 1.6 }}>{plan.narrative}</div>
        </div>
      )}

      <div className="text-xs muted mt-16" style={{ maxWidth: 760, lineHeight: 1.55 }}>{plan.note}</div>
    </Card>
  );
}
