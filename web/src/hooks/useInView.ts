import { useEffect, useRef, useState } from 'react';

/**
 * Latching IntersectionObserver hook (Kanwar, 2026-07-21) — powers lazy loading
 * of the MoM report's section cards. Returns a ref to attach to the card and a
 * boolean that flips true the first time the element comes within `rootMargin`
 * of the viewport and then STAYS true (latched), so scrolling back past a card
 * never re-triggers a fetch or unmounts loaded content.
 *
 * `rootMargin` defaults to a generous 300px so a card starts fetching just
 * before it scrolls into view — the shimmer is usually done by the time it's
 * on screen, so scrolling feels instant rather than showing a loading flash.
 *
 * Degrades safely: if IntersectionObserver isn't available (very old browser /
 * SSR), it returns inView=true immediately so nothing is ever hidden.
 */
export function useInView<T extends Element = HTMLDivElement>(rootMargin = '300px'): {
  ref: (node: T | null) => void;
  inView: boolean;
} {
  const [inView, setInView] = useState(false);
  const nodeRef = useRef<T | null>(null);
  const observerRef = useRef<IntersectionObserver | null>(null);

  useEffect(() => {
    return () => observerRef.current?.disconnect();
  }, []);

  const ref = (node: T | null) => {
    // Clean up any prior observation before (re)binding.
    observerRef.current?.disconnect();
    nodeRef.current = node;
    if (node === null || inView) {
      return; // already latched, or detaching — nothing to observe
    }
    if (typeof IntersectionObserver === 'undefined') {
      setInView(true); // no observer support → never hide content
      return;
    }
    observerRef.current = new IntersectionObserver(
      (entries) => {
        if (entries.some((e) => e.isIntersecting)) {
          setInView(true); // latch on: never observe again
          observerRef.current?.disconnect();
        }
      },
      { rootMargin },
    );
    observerRef.current.observe(node);
  };

  return { ref, inView };
}
