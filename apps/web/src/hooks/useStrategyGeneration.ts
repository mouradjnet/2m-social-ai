import { useEffect } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { ApiError, api } from '@/lib/api'
import type { AiRun } from '@/lib/types'

export const POLL_INTERVAL_MS = 1500
export const SLOW_AFTER_MS = 120_000

export type GenerationState =
  | { kind: 'idle' }
  | { kind: 'starting' }
  | { kind: 'running'; slow: boolean }
  | { kind: 'failed'; message: string; retryable: boolean }
  | { kind: 'budget'; spentCents: number; limitCents: number }
  | { kind: 'lost' }

interface BudgetBody {
  spent_cents: number
  limit_cents: number
}

interface MessageBody {
  message: string
}

/**
 * O unico lugar que sabe que existem URL, intervalo de polling e mutacao.
 *
 * O ai_run_id vive na query string, nao em useState: se vivesse na memoria,
 * recarregar a pagina no meio da geracao perderia o acompanhamento, e uma
 * recusa nunca mostraria o porque — o usuario clicaria em Gerar de novo e
 * levaria outra recusa.
 */
export function useStrategyGeneration(projectId: string) {
  const queryClient = useQueryClient()
  const [params, setParams] = useSearchParams()
  const runId = params.get('run')

  const runQuery = useQuery({
    queryKey: ['ai-run', runId],
    queryFn: () => api<AiRun>(`/ai-runs/${runId}`),
    enabled: runId !== null,
    refetchInterval: (query) => {
      const status = query.state.data?.status
      return status === 'succeeded' || status === 'failed' ? false : POLL_INTERVAL_MS
    },
  })

  const generation = useMutation({
    mutationFn: () =>
      api<{ ai_run_id: number }>(`/projects/${projectId}/strategies:generate`, {
        method: 'POST',
      }),
    onSuccess: ({ ai_run_id }) => setParams({ run: String(ai_run_id) }, { replace: true }),
  })

  const succeeded = runQuery.data?.status === 'succeeded'

  // O useQuery da v5 nao tem mais onSuccess. Este efeito e a unica forma de
  // reagir ao sucesso da execucao. Nao e descuido.
  useEffect(() => {
    if (!succeeded) return
    queryClient.invalidateQueries({ queryKey: ['strategies', projectId] })
    setParams({}, { replace: true })
  }, [succeeded, projectId, queryClient, setParams])

  const state = deriveState(
    runId,
    runQuery.data,
    runQuery.error,
    generation.error,
    generation.isPending,
  )

  return {
    state,
    generate: () => {
      generation.reset()
      generation.mutate()
    },
    retry: () => {
      setParams({}, { replace: true })
      generation.reset()
      generation.mutate()
    },
    dismiss: () => {
      generation.reset()
      setParams({}, { replace: true })
    },
  }
}

/** A ordem e a regra: o 402 acontece SEM criar execucao, entao vem primeiro. */
function deriveState(
  runId: string | null,
  run: AiRun | undefined,
  runError: unknown,
  generationError: unknown,
  generationPending: boolean,
): GenerationState {
  if (generationError instanceof ApiError && generationError.status === 402) {
    const body = generationError.body as BudgetBody

    return { kind: 'budget', spentCents: body.spent_cents, limitCents: body.limit_cents }
  }

  // Ja existe uma geracao em andamento — tipicamente uma segunda aba. Insistir
  // nao adianta: quem precisa terminar e a outra. Nunca `retryable`.
  if (generationError instanceof ApiError && generationError.status === 409) {
    const body = generationError.body as MessageBody

    return { kind: 'failed', message: body.message, retryable: false }
  }

  if (runId && runError instanceof ApiError && runError.status === 404) {
    return { kind: 'lost' }
  }

  if (run?.status === 'failed') {
    return {
      kind: 'failed',
      message: run.error ?? 'A geração falhou.',
      retryable: run.error_code === 'provider_failed',
    }
  }

  if (run?.status === 'queued' || run?.status === 'running') {
    return { kind: 'running', slow: Date.now() - Date.parse(run.created_at) > SLOW_AFTER_MS }
  }

  if (generationPending || (runId !== null && run === undefined)) {
    return { kind: 'starting' }
  }

  return { kind: 'idle' }
}
