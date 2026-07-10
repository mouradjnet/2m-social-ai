import { useId } from 'react'
import type { InputHTMLAttributes } from 'react'
import { cn } from '@/lib/cn'

interface InputProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'id'> {
  label: string
  hint?: string
  error?: string
}

/**
 * Label acima do campo em label-sm (Geist). A borda vira esmeralda apenas no
 * foco. O erro e anunciado por aria-describedby, nao so pela cor: cor sozinha
 * nao comunica nada a quem nao a enxerga.
 */
export function Input({ label, hint, error, className, ...props }: InputProps) {
  const id = useId()
  const describedBy = error ? `${id}-error` : hint ? `${id}-hint` : undefined

  return (
    <div className="flex flex-col gap-2">
      <label htmlFor={id} className="text-label-sm font-display text-on-surface uppercase">
        {label}
      </label>

      <input
        id={id}
        aria-invalid={error ? true : undefined}
        aria-describedby={describedBy}
        className={cn(
          'h-10 rounded-control border bg-surface-container-lowest px-3 text-body-md',
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
