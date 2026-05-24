import type { HTMLAttributes, ReactNode } from 'react';
import { cn } from '@/lib/cn';

type TagVariant = 'default' | 'warning' | 'success';

interface TagProps extends HTMLAttributes<HTMLSpanElement> {
  variant?: TagVariant;
  children: ReactNode;
}

const VARIANT: Record<TagVariant, string> = {
  default: '',
  warning: 'tag-warning',
  success: 'tag-success',
};

export function Tag({ variant = 'default', className, children, ...rest }: TagProps) {
  return (
    <span className={cn('tag', VARIANT[variant], className)} {...rest}>
      {children}
    </span>
  );
}

type DotVariant = 'success' | 'warning' | 'muted';

export function Dot({ variant = 'muted' }: { variant?: DotVariant }) {
  return <span className={cn('dot', `dot-${variant}`)} />;
}
