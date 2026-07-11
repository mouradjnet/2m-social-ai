import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { GenerationStatus } from '@/components/strategy/GenerationStatus'
import { StrategyCard } from '@/components/strategy/StrategyCard'
import { Button } from '@/components/ui/Button'
import { Shell } from '@/components/ui/Shell'
import { useGeneration } from '@/hooks/useGeneration'
import { api } from '@/lib/api'
import type { Strategy } from '@/lib/types'

export function StrategyPage() {
  const { projectId } = useParams()
  const queryClient = useQueryClient()
  const { state, generate, retry, dismiss } = useGeneration({
    projectId: projectId!,
    endpoint: 'strategies:generate',
    invalidateKey: ['strategies', projectId],
  })

  const strategies = useQuery({
    queryKey: ['strategies', projectId],
    queryFn: () => api<{ data: Strategy[] }>(`/projects/${projectId}/strategies`),
  })

  const setStatus = useMutation({
    mutationFn: ({ id, status }: { id: number; status: 'active' | 'archived' }) =>
      api(`/strategies/${id}`, { method: 'PATCH', body: JSON.stringify({ status }) }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['strategies', projectId] }),
  })

  if (strategies.isPending) return <Shell>Carregando…</Shell>
  if (strategies.isError) return <Shell>Projeto não encontrado.</Shell>

  // So a mais recente. O historico existe no banco e nao aparece aqui.
  const current = strategies.data.data[0]

  return (
    <Shell>
      <Link
        to={`/projects/${projectId}/brand-profile`}
        className="text-body-sm text-on-surface-variant hover:text-primary"
      >
        ← Perfil da Marca
      </Link>

      <h1 className="text-display-lg text-on-surface mt-4">Estratégia editorial</h1>
      <p className="text-body-lg text-on-surface-variant mt-4 max-w-2xl">
        A partir do perfil da sua marca, o Estrategista define a linha editorial e os pilares que
        vão sustentar o planejamento dos próximos meses.
      </p>

      <Link
        to={`/projects/${projectId}/content`}
        className="text-body-sm text-primary mt-4 inline-block hover:underline"
      >
        Ver conteúdo →
      </Link>

      <GenerationStatus state={state} onRetry={retry} onDismiss={dismiss} />

      <div className="mt-8">
        {current ? (
          <StrategyCard
            strategy={current}
            pending={setStatus.isPending}
            generating={state.kind === 'starting' || state.kind === 'running'}
            onApprove={() => setStatus.mutate({ id: current.id, status: 'active' })}
            onArchive={() => setStatus.mutate({ id: current.id, status: 'archived' })}
            onRegenerate={generate}
          />
        ) : (
          state.kind === 'idle' && <Button onClick={() => generate()}>Gerar estratégia</Button>
        )}
      </div>
    </Shell>
  )
}
