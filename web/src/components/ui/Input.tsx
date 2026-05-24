import { forwardRef, type InputHTMLAttributes, type ReactNode } from 'react';
import { cn } from '@/lib/cn';

interface InputProps extends InputHTMLAttributes<HTMLInputElement> {
  label?: ReactNode;
  hint?: ReactNode;
  trailing?: ReactNode;
}

export const Input = forwardRef<HTMLInputElement, InputProps>(function Input(
  { label, hint, trailing, className, id, ...rest },
  ref
) {
  const inputId = id ?? rest.name;
  return (
    <div className="field">
      {label && (
        <div className="flex items-center justify-between">
          <label className="field-label" htmlFor={inputId}>
            {label}
          </label>
          {trailing}
        </div>
      )}
      <input ref={ref} id={inputId} className={cn('input', className)} {...rest} />
      {hint && <span className="field-hint">{hint}</span>}
    </div>
  );
});
