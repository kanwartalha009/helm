import type { CSSProperties } from 'react';
import { cn } from '@/lib/cn';

interface AvatarProps {
  initials: string;
  size?: number;
  round?: boolean;
  inverted?: boolean;
  className?: string;
  style?: CSSProperties;
}

export function Avatar({ initials, size, round = false, inverted = false, className, style }: AvatarProps) {
  const inlineStyle: CSSProperties = { ...style };
  if (size) {
    inlineStyle.width = size;
    inlineStyle.height = size;
    inlineStyle.fontSize = size <= 22 ? 10 : size <= 32 ? 12 : 15;
  }
  if (round) inlineStyle.borderRadius = '50%';
  if (inverted) {
    inlineStyle.background = 'var(--accent)';
    inlineStyle.color = 'var(--accent-fg)';
    inlineStyle.borderColor = 'var(--accent)';
  }
  return (
    <span className={cn('brand-avatar', className)} style={inlineStyle}>
      {initials}
    </span>
  );
}
