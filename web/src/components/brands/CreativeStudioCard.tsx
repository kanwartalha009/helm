import { useState } from 'react';
import { Card, Button } from '@/components/ui';
import { useBrandStyle } from '@/hooks/useBrandStyle';
import {
  useCreativeDrafts,
  useGenerateCreative,
  useUpdateCreativeDraft,
  useDiscardCreativeDraft,
  type CreativeDraft,
  type CreativeKind,
} from '@/hooks/useCreativeStudio';

const KIND_LABEL: Record<CreativeKind, string> = {
  copy: 'Ad copy',
  hook: 'Hook',
  ugc_script: 'UGC script',
};

/**
 * GO-5.1 (master plan §8) — creative testing engine, text-only. Generate copy
 * variants, hooks and UGC scripts grounded in the brand's CONFIRMED moodboard;
 * every result is a draft an operator reviews, edits, then approves or discards.
 * Generation is disabled with a clear pointer until the style is confirmed.
 */
export function CreativeStudioCard({ slug, canEdit }: { slug?: string; canEdit: boolean }) {
  const { data: style } = useBrandStyle(slug);
  const { data: drafts } = useCreativeDrafts(slug);
  const generate = useGenerateCreative(slug);
  const update = useUpdateCreativeDraft(slug);
  const discard = useDiscardCreativeDraft(slug);

  const confirmed = style?.status === 'confirmed';
  const list = drafts ?? [];

  return (
    <div className="field">
      <label className="field-label" style={{ margin: 0 }}>Creative studio (text)</label>
      <span className="field-hint">
        Copy variants, hooks and UGC scripts grounded in this brand's confirmed moodboard, proven hooks and product
        facts. Every result is a draft you review before it goes anywhere — nothing is auto-published.
      </span>

      <Card style={{ padding: 16, marginTop: 10, display: 'flex', flexDirection: 'column', gap: 14 }}>
        {!confirmed && (
          <div className="muted text-sm" style={{ padding: '4px 0' }}>
            Confirm this brand's moodboard/style (the card above) before generating — creative needs a confirmed style to stay on-brand.
          </div>
        )}

        {canEdit && (
          <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            <Button
              size="sm"
              variant="secondary"
              type="button"
              disabled={!confirmed || generate.isPending}
              onClick={() => generate.mutate({ n: 3 })}
            >
              {generate.isPending ? 'Generating…' : 'Generate variants'}
            </Button>
            {list.length > 0 && <span className="muted text-sm">{list.length} draft{list.length === 1 ? '' : 's'}</span>}
          </div>
        )}

        {list.length === 0 && <div className="muted text-sm">No drafts yet.</div>}

        <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
          {list.map((d) => (
            <DraftRow
              key={d.id}
              draft={d}
              canEdit={canEdit}
              busy={update.isPending || discard.isPending}
              onApprove={() => update.mutate({ id: d.id, status: 'approved' })}
              onDiscard={() => discard.mutate(d.id)}
            />
          ))}
        </div>
      </Card>
    </div>
  );
}

function DraftRow({
  draft,
  canEdit,
  busy,
  onApprove,
  onDiscard,
}: {
  draft: CreativeDraft;
  canEdit: boolean;
  busy: boolean;
  onApprove: () => void;
  onDiscard: () => void;
}) {
  const [open, setOpen] = useState(false);
  const c = draft.content ?? {};
  const preview =
    draft.kind === 'copy' ? (c.headline || c.body || '') : draft.kind === 'hook' ? c.text || '' : c.title || c.script || '';

  return (
    <div style={{ border: '1px solid var(--border)', borderRadius: 8, padding: 10 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
        <span className="chip" style={{ fontSize: 10 }}>{KIND_LABEL[draft.kind]}</span>
        <span
          className="chip"
          style={{
            fontSize: 10,
            background: draft.status === 'approved' ? '#1f6f5c1a' : 'var(--surface, #f4f4f2)',
            borderColor: draft.status === 'approved' ? '#1f6f5c' : 'var(--border)',
          }}
        >
          {draft.status}
        </span>
        <span style={{ flex: 1, fontSize: 13, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{preview}</span>
        <Button size="sm" variant="ghost" type="button" onClick={() => setOpen((o) => !o)}>
          {open ? 'Hide' : 'View'}
        </Button>
        {canEdit && draft.status === 'draft' && (
          <Button size="sm" variant="secondary" type="button" disabled={busy} onClick={onApprove}>
            Approve
          </Button>
        )}
        {canEdit && (
          <Button size="sm" variant="ghost" type="button" disabled={busy} onClick={onDiscard}>
            Discard
          </Button>
        )}
      </div>

      {open && (
        <div style={{ marginTop: 8, fontSize: 13, whiteSpace: 'pre-wrap', lineHeight: 1.5 }}>
          {draft.kind === 'copy' && (
            <>
              <div style={{ fontWeight: 650 }}>{c.headline}</div>
              <div>{c.body}</div>
            </>
          )}
          {draft.kind === 'hook' && <div>{c.text}</div>}
          {draft.kind === 'ugc_script' && (
            <>
              {c.title && <div style={{ fontWeight: 650 }}>{c.title}</div>}
              <div>{c.script}</div>
            </>
          )}
          {draft.model && <div className="muted" style={{ fontSize: 10, marginTop: 6 }}>Generated by {draft.model}</div>}
        </div>
      )}
    </div>
  );
}
