import { useState } from 'react'
import { Button } from '@/components/ui/Button'
import { PublicationBadge } from '@/components/instagram/PublicationBadge'
import { Card } from '@/components/ui/Card'
import { cn } from '@/lib/cn'
import { copyToClipboard } from '@/lib/copyToClipboard'
import { StructurePreview } from '@/components/content/StructurePreview'
import type { Content, ContentFormat, ContentStatus, EditorialState } from '@/lib/types'

interface Props {
  content: Content
  pending: boolean
  onAdvance: () => void
  onBack: () => void
  onArchive: () => void
  onUnarchive: () => void
  onApplySeo: () => void
  onRewrite: () => void
  /** Abre o editor de publicacao (texto, imagem, previa, agendar). */
  onEdit?: () => void
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

/** CP-03: o formato em portugues, para identificar a peca de relance. */
const FORMATOS: Record<ContentFormat, string> = {
  post: 'Feed',
  carousel: 'Carrossel',
  reel: 'Reels',
  story: 'Stories',
  video: 'Vídeo',
  article: 'Artigo',
  thread: 'Thread',
}

/** CP-03: so os estados que o status sozinho nao conta (os da coluna Revisao). */
const ESTADOS: Partial<Record<EditorialState, string>> = {
  in_review: 'Aguardando revisão',
  needs_revision: 'Precisa de ajuste',
  pending_approval: 'Aguardando aprovação humana',
  rejected: 'Rejeitada',
  publishing: 'Publicando',
  failed: 'Falha na publicação',
  cancelled: 'Publicação cancelada',
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
  onUnarchive,
  onApplySeo,
  onRewrite,
  onEdit,
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
  const isPublished = content.status === 'published'
  // De agendado so da para desagendar (volta a Aprovado) ou arquivar. Avancar e o
  // sistema publicar na hora (ADR-13); agendar e pelo editor ou pelo agente.
  const canBack = isScheduled || (i > 0 && !isArchived && !isPublished)
  const canAdvance = i >= 0 && i < FLOW.length - 1 && !isArchived

  return (
    <Card>
      <div className="flex items-center justify-between gap-2">
        <span className="text-label-sm text-on-surface-variant">
          {FORMATOS[content.format]} · {content.channel}
        </span>
        <span className="text-label-sm bg-surface-container text-on-surface-variant shrink-0 rounded-full px-2 py-0.5">
          {CHIPS[content.status]}
        </span>
      </div>

      {content.image && (
        <img
          src={content.image.url}
          alt=""
          className="mt-2 aspect-square w-full rounded object-cover"
          loading="lazy"
        />
      )}

      <h3 className="text-label-md text-on-surface mt-2">{content.title}</h3>

      {content.caption && (
        <p className="text-body-sm text-on-surface-variant mt-1 line-clamp-3">{content.caption}</p>
      )}

      {content.cta && <p className="text-body-sm text-on-surface mt-2">CTA: {content.cta}</p>}

      {content.hashtags.length > 0 && (
        <p className="text-body-sm text-primary mt-2">{content.hashtags.join(' ')}</p>
      )}

      {content.editorial_state && ESTADOS[content.editorial_state] && (
        <p className="text-label-sm text-on-surface mt-2">
          Estado editorial: {ESTADOS[content.editorial_state]}
        </p>
      )}

      {content.structure && (
        <StructurePreview format={content.format} structure={content.structure} />
      )}

      {content.scheduled_for && (
        <p className="text-label-sm text-on-surface mt-2">📅 {formatWhen(content.scheduled_for)}</p>
      )}

      {/* O horario que o plano da semana sugeriu. Sugestao: agendar e outro gesto. */}
      {!content.scheduled_for && content.planned_for && (
        <p className="text-label-sm text-on-surface-variant mt-2">🗓 Plano: {formatWhen(content.planned_for)}</p>
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

      {content.approver && (
        <p className="text-label-sm text-secondary mt-3">✓ Aprovada por {content.approver.name}</p>
      )}

      {content.latest_publication && (
        <div className="mt-2">
          <PublicationBadge publication={content.latest_publication} />
        </div>
      )}

      {content.source === 'ai' && (
        <span className="text-label-sm bg-secondary-container text-secondary mt-3 inline-block rounded-full px-2 py-0.5">
          Gerado por IA
        </span>
      )}

      {content.repurposed_from_id && (
        <span className="text-label-sm bg-surface-container text-on-surface-variant mt-3 ml-1 inline-block rounded-full px-2 py-0.5">
          ↻ Reaproveitada
        </span>
      )}

      {/*
        * Arquivada, os tres botoes abaixo ficavam TODOS desabilitados: um cartao com
        * tres botoes mortos e nenhuma saida — e o rewriter conserta peca arquivada,
        * entao o texto novo ficava preso aqui. Um botao vivo no lugar de tres mortos.
        */}
      <div className="mt-4 flex flex-wrap gap-2">
        {onEdit && !isArchived && (
          <Button size="sm" variant="secondary" disabled={pending} onClick={onEdit}>
            {['idea', 'production', 'review'].includes(content.status) ? 'Editar' : 'Abrir'}
          </Button>
        )}
        {isArchived ? (
          <Button size="sm" variant="secondary" disabled={pending} onClick={onUnarchive}>
            Desarquivar
          </Button>
        ) : (
          <>
            <Button size="sm" variant="secondary" disabled={pending || !canBack} onClick={onBack}>
              ← Voltar
            </Button>
            <Button size="sm" disabled={pending || !canAdvance} onClick={onAdvance}>
              Avançar →
            </Button>
            <Button size="sm" variant="ghost" disabled={pending} onClick={onArchive}>
              Arquivar
            </Button>
          </>
        )}
      </div>
    </Card>
  )
}
