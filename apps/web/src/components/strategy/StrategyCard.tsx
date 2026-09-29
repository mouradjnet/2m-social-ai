import { Pillars } from '@/components/strategy/Pillars'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import type { ContentFormat, Strategy, StrategyGuidelines } from '@/lib/types'

interface Props {
  strategy: Strategy
  pending: boolean
  /** Ha uma geracao em voo. Um segundo clique criaria outra execucao, e o
   *  Budget so checa antes de enfileirar — cobraria duas vezes. */
  generating: boolean
  onApprove: () => void
  onArchive: () => void
  onRegenerate: () => void
}

const CHIPS: Record<Strategy['status'], string> = {
  draft: 'Rascunho',
  active: 'Ativa',
  archived: 'Arquivada',
}

export function StrategyCard({
  strategy,
  pending,
  generating,
  onApprove,
  onArchive,
  onRegenerate,
}: Props) {
  return (
    <Card>
      <div className="flex items-start justify-between gap-4">
        <h2 className="text-headline-lg font-display text-on-surface">{strategy.title}</h2>
        <span className="text-label-sm bg-surface-container text-on-surface-variant shrink-0 rounded-full px-3 py-1">
          {CHIPS[strategy.status]}
        </span>
      </div>

      {strategy.summary && (
        <p className="text-body-lg text-on-surface-variant mt-4">{strategy.summary}</p>
      )}

      {strategy.editorial_line && (
        <>
          <h3 className="text-label-md text-on-surface mt-8">Linha editorial</h3>
          <p className="text-body-md text-on-surface-variant mt-2">{strategy.editorial_line}</p>
        </>
      )}

      <h3 className="text-label-md text-on-surface mt-8">Pilares de conteúdo</h3>
      <Pillars pillars={strategy.pillars} />

      {strategy.guidelines && <Diretrizes g={strategy.guidelines} />}

      <div className="mt-8 flex gap-2">
        {strategy.status === 'draft' ? (
          <>
            <Button disabled={pending} onClick={onApprove}>
              Aprovar estratégia
            </Button>
            <Button variant="secondary" disabled={pending} onClick={onArchive}>
              Descartar
            </Button>
          </>
        ) : (
          <Button variant="secondary" disabled={generating} onClick={onRegenerate}>
            Gerar nova
          </Button>
        )}
      </div>
    </Card>
  )
}

const FORMATOS: Partial<Record<ContentFormat, string>> = {
  post: 'Feed',
  carousel: 'Carrossel',
  reel: 'Reels',
  story: 'Stories',
}

/** CP-03: o que o responsável confere antes de aprovar e planejar. */
function Diretrizes({ g }: { g: StrategyGuidelines }) {
  return (
    <>
      <h3 className="text-label-md text-on-surface mt-8">Objetivos</h3>
      <ul className="text-body-md text-on-surface-variant mt-2 list-disc pl-5">
        {g.objectives.map((o) => (
          <li key={o}>{o}</li>
        ))}
      </ul>

      <h3 className="text-label-md text-on-surface mt-8">Temas</h3>
      <p className="text-body-md text-on-surface-variant mt-2">{g.themes.join(' · ')}</p>

      <h3 className="text-label-md text-on-surface mt-8">Formatos e frequência</h3>
      <p className="text-body-md text-on-surface-variant mt-2">
        {g.formats.map((f) => FORMATOS[f] ?? f).join(', ')} — {g.weekly_frequency}{' '}
        {g.weekly_frequency === 1 ? 'publicação' : 'publicações'} por semana
      </p>

      <h3 className="text-label-md text-on-surface mt-8">Distribuição do conteúdo</h3>
      <p className="text-body-md text-on-surface-variant mt-2">
        Educativo {g.content_mix.educational}% · Institucional {g.content_mix.institutional}% ·
        Comercial {g.content_mix.commercial}%
      </p>
      {g.content_mix.commercial === 0 && (
        <p className="text-body-sm text-on-surface-variant mt-1">
          Sem conteúdo de venda: não há produto ou serviço confirmado no Perfil da Marca.
        </p>
      )}
    </>
  )
}
