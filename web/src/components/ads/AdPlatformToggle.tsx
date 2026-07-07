import type { AdsPlatform } from '@/types/ads';

const ALL: { key: AdsPlatform; label: string }[] = [
  { key: 'meta', label: 'Meta' },
  { key: 'google', label: 'Google' },
  { key: 'tiktok', label: 'TikTok' },
];

/**
 * Ad-platform switcher that only offers what's actually connected to the brand.
 * A brand with a single ad platform gets no toggle at all (nothing to switch),
 * and platforms without an active connection never appear — no dead "Coming
 * soon" buttons.
 */
export function AdPlatformToggle({
  available,
  value,
  onChange,
}: {
  available: AdsPlatform[];
  value: AdsPlatform;
  onChange: (p: AdsPlatform) => void;
}) {
  const opts = ALL.filter((o) => available.includes(o.key));
  if (opts.length <= 1) return null;

  return (
    <div className="segmented">
      {opts.map((o) => (
        <button key={o.key} type="button" className={value === o.key ? 'active' : ''} onClick={() => onChange(o.key)}>
          {o.label}
        </button>
      ))}
    </div>
  );
}

/** Ad platforms with an active connection, from a brand's `platforms` list. */
export function adPlatformsOf(platforms: readonly string[] | undefined): AdsPlatform[] {
  const set = new Set(platforms ?? []);
  return (['meta', 'google', 'tiktok'] as AdsPlatform[]).filter((p) => set.has(p));
}
