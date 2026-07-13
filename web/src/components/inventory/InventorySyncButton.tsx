import { useEffect, useRef } from 'react';
import { Button } from '@/components/ui';
import { useInventorySyncStatus, useStartInventorySync } from '@/hooks/useInventorySync';
import { toast } from '@/stores/toastStore';

/**
 * "Sync now" for Inventory Intelligence — refreshes stock, sales, product ad spend and sessions
 * for a short recent window, and shows what is being pulled while it runs.
 *
 * Two things this deliberately does NOT do:
 *
 *  1. **No fake progress bar.** We cannot know how long a Shopify pull will take, and a bar that
 *     crawls to 90% and sits there is a lie. The job reports the STEP it is on ("3/5 · Sessions"),
 *     which is true, and the step count is the honest progress.
 *
 *  2. **No optimistic "done".** The table only refetches when the run actually reports `done` —
 *     showing the old numbers under a fresh timestamp would be worse than showing them under an
 *     honest "syncing" label.
 */
export function InventorySyncButton({ slug, onSynced }: { slug?: string; onSynced: () => void }) {
  const start = useStartInventorySync(slug);
  const { data } = useInventorySyncStatus(slug, !!slug);

  const run = data?.run ?? null;
  const active = run?.status === 'queued' || run?.status === 'running';

  // `start.isPending` covers the POST round-trip, so the label changes on the very first paint
  // after the click. Without it the button sits there looking DEAD for a few hundred ms — which
  // is exactly long enough for someone to conclude it doesn't work and click it again.
  const pending = start.isPending || active;

  // Refetch the table exactly once, when the run flips out of the active state. `useRef` guards
  // against the poll re-firing this on every subsequent tick.
  const lastHandled = useRef<number | null>(null);
  useEffect(() => {
    if (!run || active) return;
    if (lastHandled.current === run.id) return;

    // Only act on a run that we watched finish — not on a stale `done` from a previous session,
    // which would fire a pointless refetch and a confusing toast on every page load.
    if (run.finishedAt === null) return;
    const finishedRecently = Date.now() - new Date(run.finishedAt).getTime() < 60_000;
    if (!finishedRecently) {
      lastHandled.current = run.id;
      return;
    }

    lastHandled.current = run.id;

    if (run.status === 'done') {
      onSynced();
      toast.success('Inventory synced', 'Stock, sales, ad spend and sessions are up to date.');
    } else if (run.status === 'failed') {
      toast.error('Sync failed', run.message ?? 'Check the logs and try again.');
    }
  }, [run, active, onSynced]);

  return (
    <div style={{ display: 'inline-flex', alignItems: 'center', gap: 10 }}>
      <Button
        size="sm"
        variant="secondary"
        type="button"
        disabled={pending || !slug}
        onClick={() => start.mutate()}
      >
        {pending ? 'Syncing…' : 'Sync now'}
      </Button>

      {pending && (
        <span
          className="text-xs muted"
          style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}
          aria-live="polite"
        >
          <Spinner />
          {/* The job writes "3/5 · Sessions" BEFORE each step, so this names what is happening
              NOW rather than what last finished. Falls back to "Queued…" during the POST and
              before the worker picks the job up — never a bare spinner with no words. */}
          {start.isPending ? 'Queueing…' : (run?.message ?? 'Queued…')}
        </span>
      )}
    </div>
  );
}

function Spinner() {
  return (
    <>
      <span
        aria-hidden
        style={{
          width: 11,
          height: 11,
          border: '2px solid var(--border-strong, #cbd5e1)',
          borderTopColor: 'var(--accent, #4f46e5)',
          borderRadius: '50%',
          display: 'inline-block',
          animation: 'helm-spin .7s linear infinite',
        }}
      />
      <style>{`@keyframes helm-spin { to { transform: rotate(360deg) } }`}</style>
    </>
  );
}
