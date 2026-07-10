import type { HTMLAttributes, ReactNode } from 'react'
import { cn } from '@/lib/cn'

interface CardProps extends HTMLAttributes<HTMLDivElement> {
  /** Sombra so aparece no hover; a profundidade padrao vem do contorno. */
  interactive?: boolean
}

export function Card({ interactive = false, className, ...props }: CardProps) {
  return (
    <div
      className={cn(
        'rounded-card border border-outline-variant bg-surface-container-lowest p-6',
        interactive && 'transition-shadow hover:shadow-lift',
        className,
      )}
      {...props}
    />
  )
}

export function CardTitle({ children }: { children: ReactNode }) {
  return <h3 className="text-headline-md font-display">{children}</h3>
}

export function CardDescription({ children }: { children: ReactNode }) {
  return <p className="text-body-sm text-on-surface-variant mt-1">{children}</p>
}
