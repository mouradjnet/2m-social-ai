import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ContentBoard } from '@/components/content/ContentBoard'
import { GenerationStatus } from '@/components/strategy/GenerationStatus'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Shell } from '@/components/ui/Shell'
import { useGeneration } from '@/hooks/useGeneration'
import { api, download } from '@/lib/api'
import { groupByStatus } from '@/lib/groupByStatus'
import type { Content, Strategy } from '@/lib/types'

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
  // Vazio = distribuir pelos pesos (o default). Escolher um pilar serve para cobrir
  // um buraco: a distribuicao por peso nunca sorteia um pilar leve (15% de 5 pecas
  // da 0,75, que vira zero) e ele fica zerado para sempre.
  const [pillar, setPillar] = useState('')

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

  // Mesma queryKey da StrategyPage: o cache e compartilhado, nao ha requisicao a mais.
  const strategies = useQuery({
    queryKey: ['strategies', projectId],
    queryFn: () => api<{ data: Strategy[] }>(`/projects/${projectId}/strategies`),
  })

  const move = useMutation({
    mutationFn: ({ id, status }: { id: number; status: Content['status'] }) =>
      api(`/contents/${id}`, { method: 'PATCH', body: JSON.stringify({ status }) }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['contents', projectId] }),
  })

  // O download nao passa pelo TanStack Query: nao ha dado para cachear, e sim um
  // arquivo que sai do navegador para o disco.
  const [exportando, setExportando] = useState(false)

  const exportar = async () => {
    setExportando(true)
    try {
      await download(`/projects/${projectId}/export`)
    } finally {
      setExportando(false)
    }
  }

  // A IA propoe, o humano aplica: o titulo so muda por este clique.
  const applySeo = useMutation({
    mutationFn: (id: number) => api(`/contents/${id}/seo:apply`, { method: 'POST' }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['contents', projectId] }),
  })

  // Arquivar deixou de ser ponto final. Sem `status` no corpo de proposito: quem
  // decide o destino e o servidor, que le de onde a peca saiu — a tela nao sabe.
  const unarchive = useMutation({
    mutationFn: (id: number) => api(`/contents/${id}/unarchive`, { method: 'POST' }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['contents', projectId] }),
  })

  if (contents.isPending) return <Shell>Carregando…</Shell>
  if (contents.isError) return <Shell>Projeto não encontrado.</Shell>

  const pieces = contents.data.data
  const generating = state.kind === 'starting' || state.kind === 'running'
  const aprovadas = pieces.filter((p) => p.status === 'approved').length
  // O que já passou pelo humano — é exatamente o que o zip leva.
  const prontas = pieces.filter((p) => p.status === 'approved' || p.status === 'scheduled').length
  const emRevisao = pieces.filter((p) => p.status === 'review').length
  const emProducao = pieces.filter((p) => p.status === 'production').length

  const schedule = () =>
    generate({ endpoint: 'schedule:generate', body: { starts_on: startsOn, days } })

  const pilares = strategies.data?.data.find((s) => s.status === 'active')?.pillars ?? []

  // Sem pilar escolhido, nao mandamos a chave: o servidor distribui pelos pesos.
  const gerarConteudo = () => generate(pillar === '' ? {} : { body: { pillar } })

  return (
    <Shell>
      <div className="flex items-center justify-between gap-4">
        <Link
          to={`/projects/${projectId}/strategy`}
          className="text-body-sm text-on-surface-variant hover:text-primary"
        >
          ← Estratégia
        </Link>

        <div className="flex gap-4">
          <Link
            to={`/projects/${projectId}/insights`}
            className="text-body-sm text-on-surface-variant hover:text-primary"
          >
            Ver insights →
          </Link>

          <Link
            to={`/projects/${projectId}/calendar`}
            className="text-body-sm text-on-surface-variant hover:text-primary"
          >
            Ver calendário →
          </Link>
        </div>
      </div>

      <div className="mt-4 flex items-center justify-between gap-4">
        <h1 className="text-display-lg text-on-surface">Conteúdo</h1>

        <div className="flex items-center gap-3">
          {pilares.length > 0 && (
            <select
              aria-label="Pilar do lote"
              className="border-outline text-body-sm text-on-surface rounded-input border bg-transparent px-3 py-2"
              value={pillar}
              onChange={(e) => setPillar(e.target.value)}
              disabled={generating}
            >
              <option value="">Todos os pilares</option>
              {pilares.map((p) => (
                <option key={p.name} value={p.name}>
                  {p.name}
                </option>
              ))}
            </select>
          )}

          <Button disabled={generating} onClick={gerarConteudo}>
            Gerar conteúdo
          </Button>
        </div>
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
          disabled={generating || emProducao === 0}
          onClick={() => generate({ endpoint: 'design:generate' })}
        >
          Gerar imagens ({emProducao})
        </Button>

        <Button
          variant="secondary"
          disabled={generating || emProducao === 0}
          onClick={() => generate({ endpoint: 'seo:generate' })}
        >
          Otimizar SEO ({emProducao})
        </Button>

        <Button
          variant="secondary"
          disabled={generating || emRevisao === 0}
          onClick={() => generate({ endpoint: 'review:generate' })}
        >
          Revisar ({emRevisao})
        </Button>

        {/*
          A ENTREGA. Sem API das redes, o zip é como o conteúdo sai daqui — antes o
          cliente copiava peça por peça da tela.
        */}
        <Button
          variant="secondary"
          disabled={exportando || prontas === 0}
          onClick={exportar}
        >
          {exportando ? 'Preparando…' : `Exportar (${prontas})`}
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
            pending={move.isPending || applySeo.isPending || unarchive.isPending}
            onMove={(id, status) => move.mutate({ id, status })}
            onApplySeo={(id) => applySeo.mutate(id)}
            onUnarchive={(id) => unarchive.mutate(id)}
            // A reescrita é por PEÇA, não por projeto — daí o caminho inteiro. É a
            // mesma execução assíncrona dos outros agentes: o ?run= e o polling.
            onRewrite={(id) => generate({ path: `/contents/${id}/rewrite:generate` })}
          />
        )}
      </div>
    </Shell>
  )
}
