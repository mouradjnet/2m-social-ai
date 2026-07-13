import { useState } from 'react'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { cn } from '@/lib/cn'
import { copyToClipboard } from '@/lib/copyToClipboard'
import type { Content, ContentStatus } from '@/lib/types'

interface Props {
  content: Content
  pending: boolean
  onAdvance: () => void
  onBack: () => void
  onArchive: () => void
  onApplySeo: () => void
  onRewrite: () => void
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

export function ContentCard({
  content,
  pending,
  onAdvance,
  onBack,
  onArchive,
  onApplySeo,
  onRewrite,
}: Props) {
  const [copiado, setCopiado] = useState(false)
  const review = content.latest_review

  // O veredito fala do TEXTO que existia quando ele foi escrito. A reescrita troca o
  // texto no lugar, então uma violação já corrigida continuaria no card — acusando um
  // erro que não está mais lá.
  //
  // Não serve olhar `updated_at`: ele muda quando a peça anda no fluxo ou é arquivada.
  // Arquivar uma peça reprovada apagaria a violação do card — o oposto do que quem vai
  // corrigir precisa ver. A pergunta é se o TEXTO mudou, e quem responde isso é a
  // última revisão COM `changes` (transição de status grava `changes` nulo).
  const textoMudouEm = content.latest_text_revision?.created_at ?? null

  const reviewVelha =
    review !== null && textoMudouEm !== null && new Date(review.created_at) < new Date(textoMudouEm)

  const podeReescrever = review?.verdict === 'fail' && !reviewVelha
  const seo = content.latest_seo
  const i = FLOW.indexOf(content.status)

  const copiar = async () => {
    if (await copyToClipboard(content.image_prompt ?? '')) {
      setCopiado(true)
      setTimeout(() => setCopiado(false), 2000)
    }
  }
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

      {seo && (
        <div className="border-outline-variant mt-3 rounded border p-2">
          <p className="text-label-sm text-on-surface-variant">
            {seo.applied_at ? 'SEO aplicado' : 'Sugestão de SEO'}
          </p>

          <p className="text-body-sm text-on-surface mt-1">{seo.title}</p>

          {seo.keywords.length > 0 && (
            <p className="text-body-sm text-on-surface-variant mt-1">
              🔎 {seo.keywords.join(', ')}
            </p>
          )}

          {seo.hashtags.length > 0 && (
            <p className="text-body-sm text-primary mt-1">{seo.hashtags.join(' ')}</p>
          )}

          {/* Aplicada, a sugestao JA e a peca: nao ha o que aplicar de novo. */}
          {!seo.applied_at && (
            <Button
              size="sm"
              variant="secondary"
              className="mt-2"
              disabled={pending}
              onClick={onApplySeo}
            >
              Aplicar SEO
            </Button>
          )}
        </div>
      )}

      {content.image_prompt && (
        <div className="border-outline-variant mt-3 rounded border p-2">
          <p className="text-body-sm text-on-surface-variant italic">{content.image_prompt}</p>

          <Button size="sm" variant="ghost" className="mt-1" onClick={copiar}>
            {copiado ? '✓ Copiado' : 'Copiar prompt'}
          </Button>
        </div>
      )}

      {review && (
        <div className="mt-3">
          <span
            className={cn(
              'text-label-sm inline-block rounded-full px-2 py-0.5',
              review.verdict === 'pass'
                ? 'bg-secondary-container text-secondary'
                : 'bg-error-container text-error',
            )}
          >
            {reviewVelha
              ? '↻ Texto reescrito'
              : review.verdict === 'pass'
                ? '✓ Sem violações'
                : `⚠ ${review.violations.length} ${review.violations.length === 1 ? 'violação' : 'violações'}`}
          </span>

          {reviewVelha ? (
            <p className="text-body-sm text-on-surface-variant mt-2">
              O texto mudou depois desta revisão. Mande revisar de novo para saber se o
              defeito saiu.
            </p>
          ) : (
            <>
              {/* Quem vai corrigir precisa ver o que esta errado sem clicar. */}
              <ul className="mt-2 flex flex-col gap-1">
                {review.violations.map((violation) => (
                  <li
                    key={violation.rule + violation.excerpt}
                    className="text-body-sm text-on-surface"
                  >
                    <span className="text-on-surface-variant">{violation.rule}:</span>{' '}
                    {violation.suggestion}
                  </li>
                ))}
              </ul>

              {/* O revisor deixa de ser um juiz que so condena. */}
              {podeReescrever && (
                <Button
                  size="sm"
                  variant="secondary"
                  className="mt-3"
                  disabled={pending}
                  onClick={onRewrite}
                >
                  Reescrever com IA
                </Button>
              )}
            </>
          )}
        </div>
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
