import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import type { Content, ContentStatus } from '@/lib/types'

interface Props {
  content: Content
  pending: boolean
  onAdvance: () => void
  onBack: () => void
  onArchive: () => void
}

const CHIPS: Record<ContentStatus, string> = {
  idea: 'Ideia',
  production: 'Produção',
  review: 'Revisão',
  approved: 'Aprovado',
  scheduled: 'Agendado',
  published: 'Publicado',
  archived: 'Arquivado',
}

// A mesma ordem do FLOW do backend. No cliente e so para habilitar/desabilitar;
// o servidor valida de verdade (um botao habilitado errado vira 422, nao dano).
const FLOW: ContentStatus[] = ['idea', 'production', 'review', 'approved']

/** dd/mm às HH:MM — a data agendada e para ler de relance, nao para calcular. */
function formatWhen(iso: string): string {
  const d = new Date(iso)
  const dia = d.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' })
  const hora = d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })

  return `${dia} às ${hora}`
}

export function ContentCard({ content, pending, onAdvance, onBack, onArchive }: Props) {
  const i = FLOW.indexOf(content.status)
  const isArchived = content.status === 'archived'
  const isScheduled = content.status === 'scheduled'
  // De agendado so da para desagendar (volta a Aprovado) ou arquivar. Avancar seria
  // publicar, que nao existe; e agendar e do agente, nao deste botao.
  const canBack = isScheduled || (i > 0 && !isArchived)
  const canAdvance = i >= 0 && i < FLOW.length - 1 && !isArchived

  return (
    <Card>
      <div className="flex items-center justify-between gap-2">
        <span className="text-label-sm text-on-surface-variant">
          {content.format} · {content.channel}
        </span>
        <span className="text-label-sm bg-surface-container text-on-surface-variant shrink-0 rounded-full px-2 py-0.5">
          {CHIPS[content.status]}
        </span>
      </div>

      <h3 className="text-label-md text-on-surface mt-2">{content.title}</h3>

      {content.caption && (
        <p className="text-body-sm text-on-surface-variant mt-1 line-clamp-3">{content.caption}</p>
      )}

      {content.cta && <p className="text-body-sm text-on-surface mt-2">CTA: {content.cta}</p>}

      {content.hashtags.length > 0 && (
        <p className="text-body-sm text-primary mt-2">{content.hashtags.join(' ')}</p>
      )}

      {content.scheduled_for && (
        <p className="text-label-sm text-on-surface mt-2">📅 {formatWhen(content.scheduled_for)}</p>
      )}

      {content.source === 'ai' && (
        <span className="text-label-sm bg-secondary-container text-secondary mt-3 inline-block rounded-full px-2 py-0.5">
          Gerado por IA
        </span>
      )}

      <div className="mt-4 flex gap-2">
        <Button size="sm" variant="secondary" disabled={pending || !canBack} onClick={onBack}>
          ← Voltar
        </Button>
        <Button size="sm" disabled={pending || !canAdvance} onClick={onAdvance}>
          Avançar →
        </Button>
        <Button size="sm" variant="ghost" disabled={pending || isArchived} onClick={onArchive}>
          Arquivar
        </Button>
      </div>
    </Card>
  )
}
