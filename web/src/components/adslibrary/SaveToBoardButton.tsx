import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui';
import { cn } from '@/lib/cn';
import { useAddBoardItem, useBoards, useCreateBoard } from '@/hooks/useAdsLibraryBoards';
import { toast } from '@/stores/toastStore';

/**
 * Save an ad (internal winner or market ad) to a board — a small dropdown of the
 * user's boards plus an inline "new board". Used on Winners cards and the Market
 * drawer (Phase 4 seam went live here).
 */
export function SaveToBoardButton({ source, refId, tags }: { source: 'internal' | 'market'; refId: string; tags?: string[] }) {
  const { data: boards = [] } = useBoards();
  const create = useCreateBoard();
  const add = useAddBoardItem();
  const [open, setOpen] = useState(false);
  const [name, setName] = useState('');
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;
    const h = (e: MouseEvent) => { if (!ref.current?.contains(e.target as Node)) setOpen(false); };
    window.addEventListener('mousedown', h);
    return () => window.removeEventListener('mousedown', h);
  }, [open]);

  const save = (boardId: number) => {
    add.mutate({ boardId, source, ref_id: refId, tags }, { onSuccess: () => { setOpen(false); toast.success('Saved to board'); } });
  };
  const createAndSave = () => {
    if (!name.trim()) return;
    create.mutate({ name: name.trim() }, { onSuccess: (r) => save(r.id) });
  };

  return (
    <div className={cn('dropdown', open && 'open')} ref={ref} style={{ display: 'inline-block' }}>
      <Button size="sm" variant="ghost" onClick={(e) => { e.stopPropagation(); setOpen((v) => !v); }}>Save to board</Button>
      <div className="dropdown-menu down" style={{ minWidth: 220, padding: 6 }} onClick={(e) => e.stopPropagation()}>
        {boards.length === 0 && <div className="dropdown-item" style={{ color: 'var(--text-muted)', cursor: 'default' }}>No boards yet</div>}
        {boards.map((b) => (
          <button key={b.id} type="button" className="dropdown-item" onClick={() => save(b.id)}>{b.name}</button>
        ))}
        <div className="dropdown-divider" />
        <div className="flex items-center gap-8" style={{ padding: '2px 4px' }}>
          <input className="input" style={{ height: 30 }} placeholder="New board…" value={name} onChange={(e) => setName(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter') createAndSave(); }} />
          <Button size="sm" variant="secondary" disabled={!name.trim() || create.isPending} onClick={createAndSave}>Add</Button>
        </div>
      </div>
    </div>
  );
}
