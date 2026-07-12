import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ContentBoard } from '@/components/content/ContentBoard'
import { GenerationStatus } from '@/components/strategy/GenerationStatus'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Shell } from '@/components/ui/Shell'
import { useGeneration } from '@/hooks/useGeneration'
import { api } from '@/lib/api'
import { groupByStatus } from '@/lib/groupByStatus'
import type { Content } from '@/lib/types'

/** O default do servidor: a janela comeca amanha. */
function tomorrow(): string {
  const d = new Date()
  d.setDate(d.getDate() + 1)

  return d.toISOString().slice(0, 10)
}

export function ContentPage() {
  const { projectId } = useParams()
  const queryClient = useQueryClient()
  const [startsOn, setStartsOn] = useState(tomorrow)
  const [days, setDays] = useState(14)

  // Um hook so: o ai_run_id mora no `?run=`, e duas instancias brigariam por ele.
  // Escrever e agendar invalidam a mesma query, entao o endpoint vai na chamada.
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
  const aprovadas = pieces.filter((p) => p.status === 'approved').length
  const emRevisao = pieces.filter((p) => p.status === 'review').length

  const schedule = () =>
    generate({ endpoint: 'schedule:generate', body: { starts_on: startsOn, days } })

  return (
    <Shell>
      <div className="flex items-center justify-between gap-4">
        <Link
          to={`/projects/${projectId}/strategy`}
          className="text-body-sm text-on-surface-variant hover:text-primary"
        >
          ← Estratégia
        </Link>

        <Link
          to={`/projects/${projectId}/calendar`}
          className="text-body-sm text-on-surface-variant hover:text-primary"
        >
          Ver calendário →
        </Link>
      </div>

      <div className="mt-4 flex items-center justify-between gap-4">
        <h1 className="text-display-lg text-on-surface">Conteúdo</h1>
        <Button disabled={generating} onClick={() => generate()}>
          Gerar conteúdo
        </Button>
      </div>

      <div className="mt-4 flex flex-wrap items-end gap-3">
        <Input
          label="A partir de"
          type="date"
          value={startsOn}
          onChange={(e) => setStartsOn(e.target.value)}
        />

        <Input
          label="Por quantos dias"
          type="number"
          min={1}
          max={60}
          value={days}
          onChange={(e) => setDays(Number(e.target.value))}
          className="w-28"
        />

        <Button variant="secondary" disabled={generating || aprovadas === 0} onClick={schedule}>
          Agendar aprovadas ({aprovadas})
        </Button>

        <Button
          variant="secondary"
          disabled={generating || emRevisao === 0}
          onClick={() => generate({ endpoint: 'review:generate' })}
        >
          Revisar ({emRevisao})
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
