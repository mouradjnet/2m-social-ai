import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Card } from '@/components/ui/Card'
import { Shell } from '@/components/ui/Shell'
import { useCurrentWorkspace } from '@/hooks/useCurrentWorkspace'
import { api } from '@/lib/api'
import { podeConvidar } from '@/lib/roles'
import type { Usage } from '@/lib/types'

const AGENTES: Record<string, string> = {
  strategist: 'Estratégia',
  copywriter: 'Redação',
  social_media: 'Agendamento',
  reviewer: 'Revisão',
  designer: 'Direção de arte',
  seo: 'SEO',
  analytics: 'Insights',
  rewriter: 'Reescrita',
  image: 'Imagens',
  planner: 'Planejamento',
  repurposer: 'Reaproveitamento',
  results: 'Leitura de resultados',
}

/** Centavos de dolar -> "US$ 0,19". */
const dolares = (cents: number) =>
  `US$ ${(cents / 100).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`

/**
 * Quanto a IA custou no mes, de quanto, e onde. So leitura: o teto e do operador
 * (`php artisan workspace:budget`), nao de quem administra o workspace.
 */
export function UsagePage() {
  const { me, workspace } = useCurrentWorkspace()
  const admin = workspace !== undefined && podeConvidar(workspace.role)

  const usage = useQuery({
    queryKey: ['usage', workspace?.id],
    queryFn: () => api<{ data: Usage }>(`/workspaces/${workspace!.id}/usage`),
    enabled: admin,
  })

  if (me.isPending) return <Shell>Carregando…</Shell>

  if (!workspace || !admin) {
    return (
      <Shell>
        <p className="text-body-md text-on-surface-variant">Só administradores veem o consumo de IA.</p>
        <Link to="/" className="text-label-md text-primary mt-4 inline-block">
          Voltar aos projetos
        </Link>
      </Shell>
    )
  }

  const u = usage.data?.data
  const pct = u && u.limit_cents > 0 ? Math.min(100, Math.round((u.spent_cents / u.limit_cents) * 100)) : 0

  return (
    <Shell>
      <Link to="/" className="text-body-sm text-on-surface-variant hover:text-primary">
        ← Projetos
      </Link>
      <h1 className="text-display-lg text-on-surface mt-4">Consumo de IA</h1>

      {usage.isPending || !u ? (
        <p className="text-body-sm text-on-surface-variant mt-4">Carregando…</p>
      ) : (
        <>
          <Card className="mt-6">
            <p className="text-body-sm text-on-surface-variant">Mês {u.month}</p>
            <p className="text-headline-md font-display text-on-surface mt-1" aria-label="Gasto do mês">
              {dolares(u.spent_cents)} de {dolares(u.limit_cents)}
            </p>
            <div
              className="bg-surface-container mt-3 h-2 w-full overflow-hidden rounded-full"
              role="progressbar"
              aria-valuemin={0}
              aria-valuemax={100}
              aria-valuenow={pct}
              aria-label="Parte do teto já usada"
            >
              <div className={pct >= 90 ? 'bg-error h-full' : 'bg-primary h-full'} style={{ width: `${pct}%` }} />
            </div>
            <p className="text-body-sm text-on-surface-variant mt-3">
              {pct >= 100
                ? 'O teto do mês foi atingido: novas gerações ficam bloqueadas até o mês virar.'
                : `${pct}% do teto usado. Ao atingir o teto, novas gerações ficam bloqueadas até o mês virar.`}{' '}
              {u.limit_source === 'workspace' ? 'Teto definido para este espaço.' : 'Teto padrão.'}
            </p>
          </Card>

          <div className="mt-6 grid gap-6 md:grid-cols-2">
            <Card>
              <h2 className="text-label-md text-on-surface">Por agente</h2>
              <Tabela
                vazio="Nenhuma geração neste mês."
                linhas={u.by_agent.map((l) => ({ nome: AGENTES[l.agent] ?? l.agent, runs: l.runs, cents: l.cost_cents }))}
              />
            </Card>
            <Card>
              <h2 className="text-label-md text-on-surface">Por projeto</h2>
              <Tabela
                vazio="Nenhuma geração neste mês."
                linhas={u.by_project.map((l) => ({ nome: l.name ?? 'Sem projeto', runs: l.runs, cents: l.cost_cents }))}
              />
            </Card>
          </div>
        </>
      )}
    </Shell>
  )
}

function Tabela({ linhas, vazio }: { linhas: { nome: string; runs: number; cents: number }[]; vazio: string }) {
  if (linhas.length === 0) return <p className="text-body-sm text-on-surface-variant mt-3">{vazio}</p>

  return (
    <table className="text-body-sm mt-3 w-full">
      <thead>
        <tr className="text-on-surface-variant text-left">
          <th className="py-1 font-normal">Nome</th>
          <th className="py-1 text-right font-normal">Gerações</th>
          <th className="py-1 text-right font-normal">Custo</th>
        </tr>
      </thead>
      <tbody>
        {linhas.map((l) => (
          <tr key={l.nome} className="border-outline-variant border-t">
            <td className="py-1.5 text-on-surface">{l.nome}</td>
            <td className="py-1.5 text-right text-on-surface">{l.runs}</td>
            <td className="py-1.5 text-right text-on-surface">{dolares(l.cents)}</td>
          </tr>
        ))}
      </tbody>
    </table>
  )
}
