import { ContentCard } from '@/components/content/ContentCard'
import { COLUMNS, type Column } from '@/lib/groupByStatus'
import type { Content } from '@/lib/types'

interface Props {
  groups: Record<Column, Content[]>
  pending: boolean
  onMove: (id: number, status: Content['status']) => void
}

const TITLES: Record<Column, string> = {
  idea: 'Ideia',
  production: 'Produção',
  review: 'Revisão',
  approved: 'Aprovado',
  archived: 'Arquivado',
}

// A ordem do fluxo, para calcular o proximo/anterior status ao mover.
const FLOW: Content['status'][] = ['idea', 'production', 'review', 'approved']

export function ContentBoard({ groups, pending, onMove }: Props) {
  return (
    <div className="flex gap-4 overflow-x-auto">
      {COLUMNS.map((col) => (
        <section key={col} className="w-72 shrink-0">
          <h2 className="text-label-md text-on-surface">
            {TITLES[col]} ({groups[col].length})
          </h2>

          <div className="mt-3 flex flex-col gap-3">
            {groups[col].map((content) => {
              const i = FLOW.indexOf(content.status)

              return (
                <ContentCard
                  key={content.id}
                  content={content}
                  pending={pending}
                  onAdvance={() => i >= 0 && i < FLOW.length - 1 && onMove(content.id, FLOW[i + 1])}
                  onBack={() => i > 0 && onMove(content.id, FLOW[i - 1])}
                  onArchive={() => onMove(content.id, 'archived')}
                />
              )
            })}
          </div>
        </section>
      ))}
    </div>
  )
}
