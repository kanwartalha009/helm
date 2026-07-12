import { useState } from 'react';
import { Button, Card } from '@/components/ui';
import {
  useBoard,
  useBoards,
  useBrief,
  useCreateBoard,
  useCreateBrief,
  useRemoveBoardItem,
  useSuggestTags,
  useUpdateBoardItem,
} from '@/hooks/useAdsLibraryBoards';
import { formatRoas } from '@/lib/formatters';
import { toast } from '@/stores/toastStore';
import type { BoardItem } from '@/types/adsLibrary';

/**
 * Boards tab (Phase 4) — board grid → board view (saved ads + tags + Verified tag
 * benchmarks) → Create brief. Save-to-board buttons live on the Winners cards and
 * the Market drawer (SaveToBoardButton).
 */
export function BoardsView() {
  const [boardId, setBoardId] = useState<number | null>(null);
  const [briefId, setBriefId] = useState<number | null>(null);

  if (briefId) return <BriefView briefId={briefId} onBack={() => setBriefId(null)} />;
  if (boardId) return <BoardDetail boardId={boardId} onBack={() => setBoardId(null)} onBrief={setBriefId} />;
  return <BoardsGrid onOpen={setBoardId} />;
}

function BoardsGrid({ onOpen }: { onOpen: (id: number) => void }) {
  const { data: boards = [], isLoading } = useBoards();
  const create = useCreateBoard();
  const [name, setName] = useState('');

  const add = () => {
    if (!name.trim()) return;
    create.mutate({ name: name.trim() }, { onSuccess: () => { setName(''); toast.success('Board created', name.trim()); } });
  };

  return (
    <>
      <div className="filter-bar mb-16" style={{ gap: 8 }}>
        <input className="input" style={{ maxWidth: 260 }} placeholder="New board name (e.g. Q3 hooks — footwear)" value={name} onChange={(e) => setName(e.target.value)} />
        <Button size="sm" variant="secondary" disabled={!name.trim() || create.isPending} onClick={add}>Create board</Button>
      </div>
      {isLoading && <div className="muted" style={{ padding: 24 }}>Loading boards…</div>}
      {boards.length === 0 && !isLoading && (
        <Card style={{ padding: 24, textAlign: 'center' }}>
          <div className="muted text-sm">No boards yet. Create one, then save winners or market ads to it from their cards.</div>
        </Card>
      )}
      <div className="adlib-grid">
        {boards.map((b) => (
          <Card key={b.id} className="adlib-card" style={{ padding: 16, cursor: 'pointer' }} onClick={() => onOpen(b.id)}>
            <div style={{ fontWeight: 600 }}>{b.name}</div>
            <div className="muted text-sm mt-8">{b.itemCount} item{b.itemCount === 1 ? '' : 's'}{b.niche ? ` · ${b.niche}` : ''}</div>
          </Card>
        ))}
      </div>
    </>
  );
}

function BoardDetail({ boardId, onBack, onBrief }: { boardId: number; onBack: () => void; onBrief: (id: number) => void }) {
  const { data, isLoading } = useBoard(boardId);
  const remove = useRemoveBoardItem();
  const createBrief = useCreateBrief();

  const makeBrief = () => {
    createBrief.mutate({ boardId }, { onSuccess: (r) => { toast.success('Brief created'); onBrief(r.id); } });
  };

  if (isLoading || !data) return <div className="muted" style={{ padding: 24 }}>Loading board…</div>;

  return (
    <>
      <div className="flex items-center justify-between mb-16">
        <button type="button" className="muted text-sm" style={{ background: 'none', border: 0, cursor: 'pointer' }} onClick={onBack}>← Boards</button>
        <Button size="sm" variant="primary" disabled={data.items.length === 0 || createBrief.isPending} onClick={makeBrief}>Create brief</Button>
      </div>
      <h3 className="section-title">{data.board.name}</h3>

      {data.benchmarks.length > 0 && (
        <Card style={{ padding: 14, marginBottom: 16 }}>
          <div style={{ fontWeight: 600, marginBottom: 8 }}>Proven patterns <span className="adlib-verified">Verified — our data</span></div>
          <div style={{ display: 'grid', gap: 4 }}>
            {data.benchmarks.map((b) => (
              <div key={b.tag} className="flex items-center justify-between text-sm">
                <span>{b.tag}</span>
                <span className="muted">
                  {b.enough ? <>median ROAS {formatRoas(b.medianRoas)} · CTR {b.medianCtr}% · {b.count} creatives</> : `not enough tagged data yet (${b.count})`}
                </span>
              </div>
            ))}
          </div>
        </Card>
      )}

      {data.items.length === 0 ? (
        <Card style={{ padding: 24, textAlign: 'center' }}><div className="muted text-sm">Empty board. Save ads to it from the Winners or Market tabs.</div></Card>
      ) : (
        <div className="adlib-grid">
          {data.items.map((it) => (
            <BoardItemCard key={it.id} boardId={boardId} item={it} onRemove={() => remove.mutate({ boardId, itemId: it.id })} />
          ))}
        </div>
      )}
    </>
  );
}

/**
 * One board item with an inline tag editor. Tags drive the Verified benchmarks,
 * so this is where the operator curates them. The LLM "Suggest" button is optional
 * (D-016): it proposes tags from the ad TEXT only, constrained to the taxonomy —
 * the operator always confirms each one; nothing is auto-applied.
 */
function BoardItemCard({ boardId, item, onRemove }: { boardId: number; item: BoardItem; onRemove: () => void }) {
  const update = useUpdateBoardItem();
  const suggest = useSuggestTags();
  const [editing, setEditing] = useState(false);
  const [suggested, setSuggested] = useState<string[]>([]);
  const [taxonomy, setTaxonomy] = useState<string[]>([]);

  const setTags = (tags: string[]) => update.mutate({ boardId, itemId: item.id, tags });
  const addTag = (t: string) => { if (!item.tags.includes(t)) setTags([...item.tags, t]); };
  const removeTag = (t: string) => setTags(item.tags.filter((x) => x !== t));

  const runSuggest = () => {
    suggest.mutate({ boardId, itemId: item.id }, {
      onSuccess: (r) => {
        setTaxonomy(r.taxonomy);
        setSuggested(r.suggested.filter((t) => !item.tags.includes(t)));
        setEditing(true);
        if (!r.enabled) toast.info('Tagging by hand', r.note ?? 'No LLM key on file.');
        else if (r.suggested.length === 0) toast.info('No suggestions', r.note ?? 'Nothing obvious — tag it by hand.');
      },
      onError: () => toast.error('Suggest failed', 'Try again or tag by hand.'),
    });
  };

  const palette = (taxonomy.length ? taxonomy : suggested).filter((t) => !item.tags.includes(t));

  return (
    <Card className="adlib-card" style={{ padding: 14 }}>
      <div className="adlib-chips" style={{ marginBottom: 6 }}>
        <span className={item.badge === 'Verified' ? 'adlib-verified' : 'adlib-proxy'}>{item.badge}</span>
        {item.tags.map((t) => (
          <button
            key={t}
            type="button"
            className="adlib-chip subtle"
            style={{ cursor: 'pointer', border: 0 }}
            title="Remove tag"
            onClick={() => removeTag(t)}
          >
            {t} ✕
          </button>
        ))}
      </div>
      <div style={{ fontWeight: 600, fontSize: 13.5 }}>{item.name}</div>
      {item.bodyText && <div className="adlib-copy">{item.bodyText}</div>}

      {editing && (
        <div style={{ marginTop: 8 }}>
          {suggested.length > 0 && (
            <div className="text-xs muted" style={{ marginBottom: 4 }}>Suggested — click to add:</div>
          )}
          <div className="adlib-chips" style={{ gap: 4 }}>
            {suggested.map((t) => (
              <button key={t} type="button" className="adlib-chip" style={{ cursor: 'pointer', border: '1px dashed var(--border)' }} onClick={() => { addTag(t); setSuggested(suggested.filter((x) => x !== t)); }}>
                + {t}
              </button>
            ))}
          </div>
          {palette.length > 0 && (
            <>
              <div className="text-xs muted" style={{ margin: '6px 0 4px' }}>All tags:</div>
              <div className="adlib-chips" style={{ gap: 4 }}>
                {palette.map((t) => (
                  <button key={t} type="button" className="adlib-chip subtle" style={{ cursor: 'pointer', border: 0 }} onClick={() => addTag(t)}>+ {t}</button>
                ))}
              </div>
            </>
          )}
        </div>
      )}

      <div className="flex items-center gap-8 mt-8" style={{ flexWrap: 'wrap' }}>
        {item.permalink && <a href={item.permalink} target="_blank" rel="noreferrer" className="text-xs">View live ↗</a>}
        <button type="button" className="text-xs" style={{ background: 'none', border: 0, cursor: 'pointer', color: 'var(--accent)' }} disabled={suggest.isPending} onClick={runSuggest}>
          {suggest.isPending ? 'Suggesting…' : editing ? 'Re-suggest' : 'Suggest tags'}
        </button>
        {editing && <button type="button" className="muted text-xs" style={{ background: 'none', border: 0, cursor: 'pointer' }} onClick={() => setEditing(false)}>done</button>}
        <button type="button" className="muted text-xs" style={{ background: 'none', border: 0, cursor: 'pointer', marginLeft: 'auto' }} onClick={onRemove}>remove</button>
      </div>
    </Card>
  );
}

function BriefView({ briefId, onBack }: { briefId: number; onBack: () => void }) {
  const { data, isLoading } = useBrief(briefId);
  if (isLoading || !data) return <div className="muted" style={{ padding: 24 }}>Loading brief…</div>;
  const blocks = data.blocks as Record<string, unknown>;
  const hooks = (blocks.provenHooks as { tag: string; medianRoas: number | null; enough: boolean }[] | undefined) ?? [];
  const refs = (blocks.referenceAds as { source: string; refId: string }[] | undefined) ?? [];

  return (
    <>
      <button type="button" className="muted text-sm mb-16" style={{ background: 'none', border: 0, cursor: 'pointer' }} onClick={onBack}>← Board</button>
      <h3 className="section-title">{data.title}</h3>
      <Card style={{ padding: 18, display: 'grid', gap: 16 }}>
        <Block title="Objective"><span className="muted text-sm">{(blocks.objective as string) || 'Add the campaign objective.'}</span></Block>
        <Block title="Audience"><span className="muted text-sm">{(blocks.audience as string) || '—'}</span></Block>
        <Block title="Proven hooks (from our data)">
          {hooks.length === 0 ? <span className="muted text-sm">Tag board items to surface benchmarks.</span> : (
            <div style={{ display: 'grid', gap: 3 }}>
              {hooks.map((h) => <div key={h.tag} className="text-sm">{h.tag} — {h.enough ? `median ROAS ${formatRoas(h.medianRoas)}` : 'not enough data'}</div>)}
            </div>
          )}
        </Block>
        <Block title={`Reference ads (${refs.length})`}>
          <div className="muted text-sm">{refs.map((r) => `${r.source}:${r.refId}`).join(', ') || '—'}</div>
        </Block>
        <Block title="Product facts">
          <span className="muted text-sm">{blocks.productFacts ? JSON.stringify(blocks.productFacts) : 'Attach a product when creating the brief for price + stock.'}</span>
        </Block>
      </Card>
    </>
  );
}

function Block({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div>
      <div className="text-xs" style={{ fontWeight: 700, letterSpacing: '.06em', textTransform: 'uppercase', color: 'var(--text-secondary)', marginBottom: 6 }}>{title}</div>
      {children}
    </div>
  );
}
