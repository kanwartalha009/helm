import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';

interface BannerProps {
  variant?: 'info' | 'warning' | 'default';
  icon?: ReactNode;
  children: ReactNode;
  className?: string;
}

export function Banner({
  variant = 'default',
  icon,
  children,
  className
}: BannerProps) {
  return (
    <div
      className={cn(
        'banner',
        variant === 'info' && 'banner-info',
        variant === 'warning' && 'banner-warning',
        className
      )}
    >
      {icon}
      <span>{children}</span>
    </div>
  );
}
