import { Pillars } from '@/components/strategy/Pillars'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import type { Strategy } from '@/lib/types'

interface Props {
  strategy: Strategy
  pending: boolean
  onApprove: () => void
  onArchive: () => void
  onRegenerate: () => void
}

const CHIPS: Record<Strategy['status'], string> = {
  draft: 'Rascunho',
  active: 'Ativa',
  archived: 'Arquivada',
}

export function StrategyCard({ strategy, pending, onApprove, onArchive, onRegenerate }: Props) {
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
          <Button variant="secondary" onClick={onRegenerate}>
            Gerar nova
          </Button>
        )}
      </div>
    </Card>
  )
}
