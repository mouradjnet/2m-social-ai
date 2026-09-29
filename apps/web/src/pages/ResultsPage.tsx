import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import type { ReactNode } from 'react'
import { Link, useParams } from 'react-router-dom'
import { Card } from '@/components/ui/Card'
import { Shell } from '@/components/ui/Shell'
import { api } from '@/lib/api'
import { formatDateTime } from '@/lib/instagram'
import type { PostMetrics, ResultGroup, Results } from '@/lib/types'

const FORMATOS: Record<string, string> = { post: 'Post', carousel: 'Carrossel', reel: 'Reel' }

const COLUNAS: { key: keyof PostMetrics; label: string }[] = [
  { key: 'reach', label: 'Alcance' },
  { key: 'likes', label: 'Curtidas' },
  { key: 'comments', label: 'Coment.' },
  { key: 'saved', label: 'Salvos' },
  { key: 'shares', label: 'Compart.' },
]

const n = (v: number | undefined | null) => (v ?? 0).toLocaleString('pt-BR')
const pct = (v: number | null) => (v === null ? '—' : `${v.toLocaleString('pt-BR')}%`)

interface Resposta {
  data: Results
  account: { username: string; insights_enabled: boolean } | null
}

/**
 * O que a Meta mediu nos posts (Etapa 5). So numero que veio da rede: post que ainda
 * nao foi medido (atraso de ate 48 h) ou que a Meta nao mede aparece como tal, e nao
 * entra em soma nem media. Os indicadores do calendario ficam em Insights.
 */
export function ResultsPage() {
  const { projectId } = useParams()
  const [dias, setDias] = useState(30)

  const resultados = useQuery({
    queryKey: ['results', projectId, dias],
    queryFn: () => api<Resposta>(`/projects/${projectId}/results?days=${dias}`),
  })

  if (resultados.isPending) return <Shell>Carregando…</Shell>
  if (resultados.isError) return <Shell>Projeto não encontrado.</Shell>

  const { data: r, account } = resultados.data

  return (
    <Shell>
      <Link to={`/projects/${projectId}/content`} className="text-body-sm text-on-surface-variant hover:text-primary">
        ← Conteúdo
      </Link>

      <div className="mt-4 flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-display-lg text-on-surface">Resultados</h1>
          <p className="text-body-sm text-on-surface-variant mt-1">
            Números medidos pelo Instagram{account ? ` em @${account.username}` : ''}. A Meta atualiza com até 48 h de
            atraso. Os indicadores do calendário (volume, cadência, aderência) ficam em{' '}
            <Link to={`/projects/${projectId}/insights`} className="text-primary hover:underline">
              Insights
            </Link>
            .
          </p>
        </div>

        <select
          aria-label="Período"
          className="border-outline-variant text-body-sm text-on-surface rounded border px-2 py-1"
          value={dias}
          onChange={(e) => setDias(Number(e.target.value))}
        >
          <option value={7}>Últimos 7 dias</option>
          <option value={30}>Últimos 30 dias</option>
          <option value={90}>Últimos 90 dias</option>
        </select>
      </div>

      {account === null ? (
        <Aviso>Conecte a conta do Instagram na tela Instagram para medir os posts.</Aviso>
      ) : !account.insights_enabled ? (
        <Aviso>
          A conta foi conectada sem a permissão de métricas. Reconecte na tela{' '}
          <Link to={`/projects/${projectId}/instagram`} className="text-primary hover:underline">
            Instagram
          </Link>{' '}
          e mantenha marcada a permissão de insights.
        </Aviso>
      ) : null}

      <div className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Numero label="alcance somado" value={n(r.totals.reach)} />
        <Numero label="interações" value={n(r.totals.total_interactions)} />
        <Numero label="interações por alcance" value={pct(r.engagement_rate)} />
        <Numero label="posts medidos" value={`${r.measured} de ${r.published}`} />
      </div>

      {r.measured === 0 ? (
        <p className="text-body-md text-on-surface-variant mt-8">
          {r.published === 0
            ? 'Nenhum post publicado pelo sistema neste período.'
            : 'Os posts ainda não foram medidos. A coleta roda uma vez por dia e a Meta atrasa até 48 h.'}
        </p>
      ) : (
        <div className="mt-6 grid gap-6 md:grid-cols-2">
          <Grupo titulo="Por pilar" linhas={r.by_pillar} />
          <Grupo titulo="Por formato" linhas={r.by_format.map((g) => ({ ...g, name: FORMATOS[g.name] ?? g.name }))} />
        </div>
      )}

      {r.posts.length > 0 && (
        <Card className="mt-6 overflow-x-auto">
          <h2 className="text-label-md text-on-surface">Posts</h2>
          <table className="text-body-sm mt-3 w-full">
            <thead>
              <tr className="text-on-surface-variant text-left">
                <th className="py-1 font-normal">Post</th>
                {COLUNAS.map((c) => (
                  <th key={c.key} className="py-1 text-right font-normal">
                    {c.label}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {r.posts.map((p) => (
                <tr key={p.publication_id} className="border-outline-variant border-t align-top">
                  <td className="py-2 pr-3">
                    {p.permalink ? (
                      <a href={p.permalink} target="_blank" rel="noreferrer" className="text-on-surface hover:text-primary">
                        {p.title ?? `Post ${p.publication_id}`}
                      </a>
                    ) : (
                      <span className="text-on-surface">{p.title ?? `Post ${p.publication_id}`}</span>
                    )}
                    <span className="text-on-surface-variant block">
                      {[FORMATOS[p.format ?? ''] ?? p.format, p.pillar, p.published_at && formatDateTime(p.published_at)]
                        .filter(Boolean)
                        .join(' · ')}
                    </span>
                  </td>
                  {p.state === 'measured' ? (
                    COLUNAS.map((c) => (
                      <td key={c.key} className="text-on-surface py-2 text-right">
                        {p.metrics?.[c.key] === undefined ? '—' : n(p.metrics[c.key])}
                      </td>
                    ))
                  ) : (
                    <td colSpan={COLUNAS.length} className="text-on-surface-variant py-2 text-right">
                      {p.state === 'pending' ? 'Aguardando a Meta medir' : 'A Meta não mede este post'}
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
      )}
    </Shell>
  )
}

function Aviso({ children }: { children: ReactNode }) {
  return (
    <p role="status" className="bg-surface-container text-body-sm text-on-surface rounded-card mt-4 px-4 py-3">
      {children}
    </p>
  )
}

function Numero({ label, value }: { label: string; value: string }) {
  return (
    <Card>
      <p className="text-headline-md font-display text-on-surface">{value}</p>
      <p className="text-body-sm text-on-surface-variant mt-1">{label}</p>
    </Card>
  )
}

function Grupo({ titulo, linhas }: { titulo: string; linhas: ResultGroup[] }) {
  return (
    <Card>
      <h2 className="text-label-md text-on-surface">{titulo}</h2>
      <table className="text-body-sm mt-3 w-full">
        <thead>
          <tr className="text-on-surface-variant text-left">
            <th className="py-1 font-normal">Nome</th>
            <th className="py-1 text-right font-normal">Posts</th>
            <th className="py-1 text-right font-normal">Alcance médio</th>
            <th className="py-1 text-right font-normal">Interações/alcance</th>
          </tr>
        </thead>
        <tbody>
          {linhas.map((l) => (
            <tr key={l.name} className="border-outline-variant border-t">
              <td className="text-on-surface py-1.5">{l.name}</td>
              <td className="text-on-surface py-1.5 text-right">{l.posts}</td>
              <td className="text-on-surface py-1.5 text-right">{n(l.avg_reach)}</td>
              <td className="text-on-surface py-1.5 text-right">{pct(l.engagement_rate)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </Card>
  )
}
