import { ContentCard } from '@/components/content/ContentCard'
import { COLUMNS, type Column } from '@/lib/groupByStatus'
import type { Content } from '@/lib/types'

interface Props {
  groups: Record<Column, Content[]>
  pending: boolean
  onMove: (id: number, status: Content['status']) => void
  onApplySeo: (id: number) => void
  onRewrite: (id: number) => void
  onUnarchive: (id: number) => void
}

const TITLES: Record<Column, string> = {
  idea: 'Ideia',
  production: 'Produção',
  review: 'Revisão',
  approved: 'Aprovado',
  scheduled: 'Agendado',
  archived: 'Arquivado',
}

// A ordem do fluxo, para calcular o proximo/anterior status ao mover. `scheduled`
// nao esta aqui: quem agenda e o agente. Voltar de agendado e caso a parte.
const FLOW: Content['status'][] = ['idea', 'production', 'review', 'approved']

export function ContentBoard({
  groups,
  pending,
  onMove,
  onApplySeo,
  onRewrite,
  onUnarchive,
}: Props) {
  return (
    /*
     * Seis colunas nao cabem: 6x256 + gaps = ~1400px contra os 1344px uteis do
     * Shell (90rem - px-12). Rolar e legitimo — o que nao pode e a ultima coluna
     * sair fatiada pela borda do container, sem respiro, parecendo defeito.
     *
     * O `-mx-12 px-12` sangra o padding do Shell: a area de rolagem vai de borda a
     * borda da janela, e o padding vira o respiro do primeiro e do ultimo cartao.
     * `scroll-px-12` alinha o snap com esse respiro.
     */
    <div className="-mx-12 flex snap-x gap-4 overflow-x-auto scroll-px-12 px-12 pb-2">
      {COLUMNS.map((col) => (
        <section key={col} className="w-64 shrink-0 snap-start">
          <h2 className="text-label-md text-on-surface">
            {TITLES[col]} ({groups[col].length})
          </h2>

          <div className="mt-3 flex flex-col gap-3">
            {groups[col].map((content) => {
              const i = FLOW.indexOf(content.status)
              // Desagendar e o "voltar" de uma peca agendada: ela nao esta no FLOW.
              const back = content.status === 'scheduled' ? 'approved' : FLOW[i - 1]

              return (
                <ContentCard
                  key={content.id}
                  content={content}
                  pending={pending}
                  onAdvance={() => i >= 0 && i < FLOW.length - 1 && onMove(content.id, FLOW[i + 1])}
                  onBack={() => back && onMove(content.id, back)}
                  onArchive={() => onMove(content.id, 'archived')}
                  // Sem status: o destino e do servidor, que sabe de onde ela saiu.
                  onUnarchive={() => onUnarchive(content.id)}
                  onApplySeo={() => onApplySeo(content.id)}
                  onRewrite={() => onRewrite(content.id)}
                />
              )
            })}
          </div>
        </section>
      ))}
    </div>
  )
}
