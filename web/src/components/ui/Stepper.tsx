import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';

interface Step {
  label: ReactNode;
  state: 'done' | 'active' | 'pending';
}

export function Stepper({ steps }: { steps: Step[] }) {
  return (
    <div className="stepper">
      {steps.map((step, i) => (
        <span key={i} className="flex items-center gap-12">
          <div className={cn('step', step.state === 'active' && 'active', step.state === 'done' && 'done')}>
            <span className="step-num">{step.state === 'done' ? '✓' : i + 1}</span>
            <span className="step-label">{step.label}</span>
          </div>
          {i < steps.length - 1 && <span className="step-line" />}
        </span>
      ))}
    </div>
  );
}
