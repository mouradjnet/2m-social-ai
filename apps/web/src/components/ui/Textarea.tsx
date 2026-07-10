import { useId } from 'react'
import type { TextareaHTMLAttributes } from 'react'
import { cn } from '@/lib/cn'

interface TextareaProps extends Omit<TextareaHTMLAttributes<HTMLTextAreaElement>, 'id'> {
  label: string
  hint?: string
  error?: string
}

export function Textarea({ label, hint, error, className, ...props }: TextareaProps) {
  const id = useId()
  const describedBy = error ? `${id}-error` : hint ? `${id}-hint` : undefined

  return (
    <div className="flex flex-col gap-2">
      <label htmlFor={id} className="text-label-sm font-display text-on-surface uppercase">
        {label}
      </label>

      <textarea
        id={id}
        rows={3}
        aria-invalid={error ? true : undefined}
        aria-describedby={describedBy}
        className={cn(
          'rounded-control border bg-surface-container-lowest px-3 py-2 text-body-md',
          'placeholder:text-on-surface-variant/60 transition-colors',
          error ? 'border-error' : 'border-outline-variant focus:border-primary',
          className,
        )}
        {...props}
      />

      {error ? (
        <p id={`${id}-error`} className="text-body-sm text-error">
          {error}
        </p>
      ) : hint ? (
        <p id={`${id}-hint`} className="text-body-sm text-on-surface-variant">
          {hint}
        </p>
      ) : null}
    </div>
  )
}
