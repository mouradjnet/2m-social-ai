import { cn } from '@/lib/cn'

export interface Step {
  id: string
  label: string
  title: string
}

interface StepperProps {
  steps: Step[]
  currentId: string
  /** Vem do campo `completion` da API: um passo pode estar completo fora de ordem. */
  completedIds?: string[]
  /** Passos que a API marcou como nao-obrigatorios; ganham a marca "opcional". */
  optionalIds?: string[]
  onSelect?: (id: string) => void
}

/**
 * Linhas finas e indicadores circulares pequenos, como o design pede.
 * Cada passo do wizard vive no seu proprio card — o Stepper e so a navegacao.
 */
export function Stepper({
  steps,
  currentId,
  completedIds = [],
  optionalIds = [],
  onSelect,
}: StepperProps) {
  return (
    <nav aria-label="Progresso">
      <ol className="flex flex-col">
        {steps.map((step, index) => {
          const isCurrent = step.id === currentId
          const isComplete = completedIds.includes(step.id)
          const isOptional = optionalIds.includes(step.id)
          const isLast = index === steps.length - 1

          return (
            <li key={step.id} className="flex gap-4">
              <div className="flex flex-col items-center">
                <span
                  aria-hidden
                  className={cn(
                    'mt-1.5 size-2.5 shrink-0 rounded-full transition-colors',
                    isCurrent || isComplete ? 'bg-primary' : 'bg-outline-variant',
                  )}
                />
                {!isLast && (
                  <span
                    aria-hidden
                    className={cn(
                      'w-px flex-1 my-1',
                      isComplete ? 'bg-primary' : 'bg-outline-variant',
                    )}
                  />
                )}
              </div>

              <button
                type="button"
                onClick={onSelect ? () => onSelect(step.id) : undefined}
                disabled={!onSelect}
                aria-current={isCurrent ? 'step' : undefined}
                className={cn(
                  'pb-8 text-left disabled:cursor-default',
                  isLast && 'pb-0',
                )}
              >
                <span
                  className={cn(
                    'block text-label-md font-display',
                    isCurrent || isComplete ? 'text-on-surface' : 'text-on-surface-variant',
                  )}
                >
                  {step.label}
                </span>
                <span
                  className={cn(
                    'block text-body-sm',
                    isCurrent ? 'text-on-surface' : 'text-on-surface-variant',
                  )}
                >
                  {step.title}
                  {isOptional && (
                    <span className="text-label-sm text-on-surface-variant"> · opcional</span>
                  )}
                </span>
              </button>
            </li>
          )
        })}
      </ol>
    </nav>
  )
}
