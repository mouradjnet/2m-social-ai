import { Button } from '@/components/ui/Button'
import type { GenerationState } from '@/hooks/useStrategyGeneration'

interface Props {
  state: GenerationState
  onRetry: () => void
  onDismiss: () => void
}

function dollars(cents: number): string {
  return `US$ ${(cents / 100).toFixed(2)}`
}

/** Desenha o estado. Nao sabe que a rede existe. */
export function GenerationStatus({ state, onRetry, onDismiss }: Props) {
  switch (state.kind) {
    case 'idle':
      return null

    case 'starting':
    case 'running':
      return (
        <p className="text-body-md text-on-surface-variant mt-6" role="status">
          Gerando sua estratégia…
          {state.kind === 'running' && state.slow && (
            <span className="mt-1 block">Isto está demorando mais que o normal.</span>
          )}
        </p>
      )

    case 'failed':
      return (
        <div className="bg-error-container rounded-card mt-6 p-4" role="alert">
          <p className="text-body-md text-on-surface">{state.message}</p>
          <div className="mt-3 flex gap-2">
            {/* Sem botao quando a falha e uma recusa: o mesmo prompt sera
                recusado de novo, e a tentativa custa orcamento. */}
            {state.retryable && (
              <Button size="sm" onClick={onRetry}>
                Tentar de novo
              </Button>
            )}
            <Button size="sm" variant="secondary" onClick={onDismiss}>
              Dispensar
            </Button>
          </div>
        </div>
      )

    case 'budget':
      return (
        <div className="bg-error-container rounded-card mt-6 p-4" role="alert">
          <p className="text-body-md text-on-surface">
            Orçamento mensal de IA esgotado para este espaço de trabalho. Você gastou{' '}
            {dollars(state.spentCents)} de {dollars(state.limitCents)}.
          </p>
          <Button className="mt-3" size="sm" variant="secondary" onClick={onDismiss}>
            Dispensar
          </Button>
        </div>
      )

    case 'lost':
      return (
        <div className="bg-error-container rounded-card mt-6 p-4" role="alert">
          <p className="text-body-md text-on-surface">Não encontramos essa geração.</p>
          <Button className="mt-3" size="sm" variant="secondary" onClick={onDismiss}>
            Dispensar
          </Button>
        </div>
      )
  }
}
