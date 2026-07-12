import { useEffect, useRef } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { ApiError, api } from '@/lib/api'
import type { AiRun } from '@/lib/types'

export const POLL_INTERVAL_MS = 1500
export const SLOW_AFTER_MS = 120_000

export type GenerationState =
  | { kind: 'idle' }
  | { kind: 'starting' }
  | { kind: 'running'; slow: boolean; agent: string }
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

interface Config {
  projectId: string
  /** O endpoint padrao: 'strategies:generate' | 'copy:generate' */
  endpoint: string
  /** A query a invalidar quando a execucao tem sucesso. */
  invalidateKey: unknown[]
}

/**
 * Uma pagina pode gerar por mais de um agente (a de Conteudo escreve e agenda), mas
 * o ai_run_id mora na query string: duas instancias do hook brigariam pelo mesmo
 * `?run=`. Por isso o endpoint e o corpo sao escolhidos na chamada, nao no hook.
 */
interface GenerateOptions {
  endpoint?: string
  body?: unknown
}

/**
 * O unico lugar que sabe que existem URL, intervalo de polling e mutacao.
 * Serve os dois agentes: o que muda e o endpoint e a query a invalidar.
 *
 * O ai_run_id vive na query string, nao em useState: se vivesse na memoria,
 * recarregar a pagina no meio da geracao perderia o acompanhamento, e uma
 * recusa nunca mostraria o porque — o usuario clicaria em Gerar de novo e
 * levaria outra recusa.
 */
export function useGeneration({ projectId, endpoint, invalidateKey }: Config) {
  const queryClient = useQueryClient()
  const [params, setParams] = useSearchParams()
  const runId = params.get('run')

  // invalidateKey e um array literal recriado a cada render pelo chamador. Uma
  // ref evita que ele entre nas deps do efeito (identidade nova toda render) sem
  // precisar de JSON.stringify nem de disable de lint.
  const invalidateRef = useRef(invalidateKey)
  invalidateRef.current = invalidateKey

  const runQuery = useQuery({
    queryKey: ['ai-run', runId],
    queryFn: () => api<AiRun>(`/ai-runs/${runId}`),
    enabled: runId !== null,
    refetchInterval: (query) => {
      const status = query.state.data?.status
      return status === 'succeeded' || status === 'failed' ? false : POLL_INTERVAL_MS
    },
  })

  // O retry precisa reenviar a MESMA geracao — mesmo endpoint, mesma janela.
  const lastRef = useRef<GenerateOptions>({})

  const generation = useMutation({
    mutationFn: (options: GenerateOptions) =>
      api<{ ai_run_id: number }>(`/projects/${projectId}/${options.endpoint ?? endpoint}`, {
        method: 'POST',
        ...(options.body === undefined ? {} : { body: JSON.stringify(options.body) }),
      }),
    onSuccess: ({ ai_run_id }) => setParams({ run: String(ai_run_id) }, { replace: true }),
  })

  const succeeded = runQuery.data?.status === 'succeeded'

  // O useQuery da v5 nao tem mais onSuccess. Este efeito e a unica forma de
  // reagir ao sucesso da execucao. Nao e descuido.
  useEffect(() => {
    if (!succeeded) return
    queryClient.invalidateQueries({ queryKey: invalidateRef.current })
    setParams({}, { replace: true })
  }, [succeeded, queryClient, setParams])

  const state = deriveState(
    runId,
    runQuery.data,
    runQuery.error,
    generation.error,
    generation.isPending,
  )

  return {
    state,
    generate: (options: GenerateOptions = {}) => {
      lastRef.current = options
      generation.reset()
      generation.mutate(options)
    },
    retry: () => {
      setParams({}, { replace: true })
      generation.reset()
      generation.mutate(lastRef.current)
    },
    dismiss: () => {
      generation.reset()
      setParams({}, { replace: true })
    },
  }
}

/** A ordem e a regra: os erros de mutacao (422/402/409) acontecem SEM criar
 *  execucao. 422 (sem estrategia ativa, so no copy) e a pre-condicao mais
 *  especifica; 402 orcamento; 409 concorrencia. Nao-retentaveis: quem precisa
 *  agir e o usuario (aprovar estrategia) ou a outra geracao (terminar). */
function deriveState(
  runId: string | null,
  run: AiRun | undefined,
  runError: unknown,
  generationError: unknown,
  generationPending: boolean,
): GenerationState {
  if (generationError instanceof ApiError && generationError.status === 422) {
    const body = generationError.body as MessageBody

    return { kind: 'failed', message: body.message, retryable: false }
  }

  if (generationError instanceof ApiError && generationError.status === 402) {
    const body = generationError.body as BudgetBody

    return { kind: 'budget', spentCents: body.spent_cents, limitCents: body.limit_cents }
  }

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
    // O agente vem do run, nao de quem clicou: a ContentPage dispara cinco
    // agentes pelo mesmo hook, e o ai_run_id vive na URL — um label guardado
    // em memoria mentiria depois de um reload no meio da geracao.
    return {
      kind: 'running',
      slow: Date.now() - Date.parse(run.created_at) > SLOW_AFTER_MS,
      agent: run.agent,
    }
  }

  if (generationPending || (runId !== null && run === undefined)) {
    return { kind: 'starting' }
  }

  return { kind: 'idle' }
}
