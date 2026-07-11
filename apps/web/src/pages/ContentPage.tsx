import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { ContentBoard } from '@/components/content/ContentBoard'
import { GenerationStatus } from '@/components/strategy/GenerationStatus'
import { Button } from '@/components/ui/Button'
import { Shell } from '@/components/ui/Shell'
import { useGeneration } from '@/hooks/useGeneration'
import { api } from '@/lib/api'
import { groupByStatus } from '@/lib/groupByStatus'
import type { Content } from '@/lib/types'

export function ContentPage() {
  const { projectId } = useParams()
  const queryClient = useQueryClient()
  const { state, generate, retry, dismiss } = useGeneration({
    projectId: projectId!,
    endpoint: 'copy:generate',
    invalidateKey: ['contents', projectId],
  })

  const contents = useQuery({
    queryKey: ['contents', projectId],
    queryFn: () => api<{ data: Content[] }>(`/projects/${projectId}/contents`),
  })

  const move = useMutation({
    mutationFn: ({ id, status }: { id: number; status: Content['status'] }) =>
      api(`/contents/${id}`, { method: 'PATCH', body: JSON.stringify({ status }) }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['contents', projectId] }),
  })

  if (contents.isPending) return <Shell>Carregando…</Shell>
  if (contents.isError) return <Shell>Projeto não encontrado.</Shell>

  const pieces = contents.data.data
  const generating = state.kind === 'starting' || state.kind === 'running'

  return (
    <Shell>
      <Link
        to={`/projects/${projectId}/strategy`}
        className="text-body-sm text-on-surface-variant hover:text-primary"
      >
        ← Estratégia
      </Link>

      <div className="mt-4 flex items-center justify-between gap-4">
        <h1 className="text-display-lg text-on-surface">Conteúdo</h1>
        <Button disabled={generating} onClick={generate}>
          Gerar conteúdo
        </Button>
      </div>

      <GenerationStatus state={state} onRetry={retry} onDismiss={dismiss} />

      <div className="mt-8">
        {pieces.length === 0 && state.kind === 'idle' ? (
          <p className="text-body-lg text-on-surface-variant">
            Ainda não há conteúdo. Gere a partir da estratégia ativa.
          </p>
        ) : (
          <ContentBoard
            groups={groupByStatus(pieces)}
            pending={move.isPending}
            onMove={(id, status) => move.mutate({ id, status })}
          />
        )}
      </div>
    </Shell>
  )
}
