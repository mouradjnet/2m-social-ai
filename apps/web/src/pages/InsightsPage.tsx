import { useQuery } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { GenerationStatus } from '@/components/strategy/GenerationStatus'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Shell } from '@/components/ui/Shell'
import { useGeneration } from '@/hooks/useGeneration'
import { api } from '@/lib/api'
import { cn } from '@/lib/cn'
import type { AnalyticsResponse, PillarAdherence } from '@/lib/types'

/** +25 pp / −25 pp. O sinal e a informacao: entregou mais ou menos do que pediu. */
function desvio(pp: number): string {
  if (pp === 0) return 'no alvo'

  return `${pp > 0 ? '+' : '−'}${Math.abs(pp)} pp`
}

function Numero({ label, value }: { label: string; value: number | string }) {
  return (
    <div>
      <p className="text-display-lg text-on-surface">{value}</p>
      <p className="text-label-sm text-on-surface-variant">{label}</p>
    </div>
  )
}

function Pilar({ pilar }: { pilar: PillarAdherence }) {
  return (
    <li>
      <div className="flex items-baseline justify-between gap-2">
        <span className="text-label-md text-on-surface">{pilar.nome}</span>
        <span
          className={cn(
            'text-label-sm',
            pilar.desvio === 0 ? 'text-on-surface-variant' : 'text-error',
          )}
        >
          {desvio(pilar.desvio)}
        </span>
      </div>

      <p className="text-body-sm text-on-surface-variant">
        pedido {pilar.peso_pedido}% · entregue {pilar.peso_real}% ({pilar.pecas})
      </p>
    </li>
  )
}

export function InsightsPage() {
  const { projectId } = useParams()

  const { state, generate, retry, dismiss } = useGeneration({
    projectId: projectId!,
    endpoint: 'analytics:generate',
    invalidateKey: ['analytics', projectId],
  })

  const analytics = useQuery({
    queryKey: ['analytics', projectId],
    queryFn: () => api<AnalyticsResponse>(`/projects/${projectId}/analytics`),
  })

  if (analytics.isPending) return <Shell>Carregando…</Shell>
  if (analytics.isError) return <Shell>Projeto não encontrado.</Shell>

  const { data: report, metrics } = analytics.data
  const generating = state.kind === 'starting' || state.kind === 'running'

  return (
    <Shell>
      <Link
        to={`/projects/${projectId}/content`}
        className="text-body-sm text-on-surface-variant hover:text-primary"
      >
        ← Conteúdo
      </Link>

      <div className="mt-4 flex items-center justify-between gap-4">
        <h1 className="text-display-lg text-on-surface">Insights</h1>
        <Button disabled={generating} onClick={() => generate()}>
          Gerar relatório
        </Button>
      </div>

      <GenerationStatus state={state} onRetry={retry} onDismiss={dismiss} />

      {/* Os numeros existem antes de a IA opinar sobre eles. */}
      <div className="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Card>
          <Numero label="peças no total" value={metrics.volume.total} />
        </Card>
        <Card>
          <Numero label="agendadas em 30 dias" value={metrics.cadencia.agendadas_30_dias} />
        </Card>
        <Card>
          <Numero label="dias sem peça (maior lacuna)" value={metrics.cadencia.maior_lacuna_dias} />
        </Card>
        <Card>
          <Numero
            label="reprovadas na revisão"
            value={`${metrics.qualidade.reprovadas}/${metrics.qualidade.revisadas}`}
          />
        </Card>
      </div>

      {metrics.aderencia && (
        <Card className="mt-4">
          <h2 className="text-label-md text-on-surface">Aderência à estratégia</h2>

          <ul className="mt-3 flex flex-col gap-3">
            {metrics.aderencia.pilares.map((pilar) => (
              <Pilar key={pilar.nome} pilar={pilar} />
            ))}
          </ul>

          {/* Peca antiga nao tem pilar: contamos, nao escondemos. */}
          {metrics.aderencia.sem_pilar > 0 && (
            <p className="text-body-sm text-on-surface-variant mt-3">
              {metrics.aderencia.sem_pilar} peça
              {metrics.aderencia.sem_pilar > 1 ? 's' : ''} sem pilar registrado — fora deste cálculo.
            </p>
          )}
        </Card>
      )}

      {report === null ? (
        <p className="text-body-lg text-on-surface-variant mt-8">
          Ainda não há leitura destes números. Gere o relatório para saber o que este calendário
          está contando.
        </p>
      ) : (
        <div className="mt-8">
          <Card>
            <div className="flex items-center gap-4">
              <p className="text-display-lg text-primary">{report.score}</p>
              <div>
                <p className="text-label-sm text-on-surface-variant">Pontuação do calendário</p>
                <p className="text-body-md text-on-surface">{report.summary}</p>
              </div>
            </div>
          </Card>

          <ul className="mt-4 flex flex-col gap-3">
            {report.insights.map((insight) => (
              <li key={insight.title}>
                <Card>
                  <h3 className="text-label-md text-on-surface">{insight.title}</h3>
                  <p className="text-body-sm text-on-surface-variant mt-1">{insight.detail}</p>
                  {/* Diagnostico sem proximo passo nao serve para nada. */}
                  <p className="text-body-sm text-primary mt-2">→ {insight.action}</p>
                </Card>
              </li>
            ))}
          </ul>
        </div>
      )}
    </Shell>
  )
}
