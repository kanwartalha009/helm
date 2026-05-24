import { clsx, type ClassValue } from 'clsx';

// Small helper. We import clsx through this module so swapping it later
// (e.g. for tailwind-merge if we adopt utility-first styling) is one file.
export function cn(...inputs: ClassValue[]): string {
  return clsx(inputs);
}
