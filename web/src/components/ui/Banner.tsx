import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';

interface BannerProps {
  variant?: 'info' | 'warning' | 'default';
  icon?: ReactNode;
  children: ReactNode;
}

export function Banner({ variant = 'default', icon, children }: BannerProps) {
  return (
    <div
      className={cn(
        'banner',
        variant === 'info' && 'banner-info',
        variant === 'warning' && 'banner-warning'
      )}
    >
      {icon}
      <span>{children}</span>
    </div>
  );
}
