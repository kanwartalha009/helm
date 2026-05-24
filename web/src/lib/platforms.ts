import type { Platform } from '@/types/domain';

export const PLATFORMS: { key: Platform; label: string; initial: string }[] = [
  { key: 'shopify', label: 'Shopify', initial: 'S' },
  { key: 'meta', label: 'Meta Ads', initial: 'M' },
  { key: 'google', label: 'Google Ads', initial: 'G' },
  { key: 'tiktok', label: 'TikTok Ads', initial: 'T' },
];

export function platformLabel(key: Platform): string {
  return PLATFORMS.find((p) => p.key === key)?.label ?? key;
}
