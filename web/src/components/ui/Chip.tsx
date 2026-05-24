import type { HTMLAttributes, ReactNode } from 'react';
import { cn } from '@/lib/cn';

interface ChipProps extends HTMLAttributes<HTMLSpanElement> {
  active?: boolean;
  children: ReactNode;
}

export function Chip({ active = false, className, children, ...rest }: ChipProps) {
  return (
    <span className={cn('chip', active && 'active', className)} {...rest}>
      {children}
    </span>
  );
}
