import type { Content, PublicationStatus } from '@/lib/types'

/** Os limites da Meta para a legenda. O servidor confere de novo antes de publicar. */
export const CAPTION_MAX = 2200
export const HASHTAGS_MAX = 30

/**
 * A legenda como vai ao ar: texto, CTA e hashtags, separados por linha em branco.
 * Espelha `Domain\Publishing\Caption::compose` — a previa mostra o que sera publicado.
 */
export function composeCaption(content: Pick<Content, 'caption' | 'cta' | 'hashtags'>): string {
  const hashtags = content.hashtags
    .map((tag) => `#${tag.trim().replace(/^#+/, '')}`)
    .filter((tag) => tag !== '#')
    .join(' ')

  return [content.caption?.trim() ?? '', content.cta?.trim() ?? '', hashtags]
    .filter(Boolean)
    .join('\n\n')
}

/** "#saude bem_estar, #rotina" -> ['#saude', '#bem_estar', '#rotina'] */
export function parseHashtags(texto: string): string[] {
  return texto
    .split(/[\s,]+/)
    .map((t) => t.trim())
    .filter(Boolean)
    .map((t) => `#${t.replace(/^#+/, '')}`)
}

export const PUBLICATION_LABELS: Record<PublicationStatus, string> = {
  pending: 'Na fila',
  publishing: 'Publicando',
  published: 'Publicado',
  failed: 'Falhou',
  unknown: 'Aguardando confirmação',
  cancelled: 'Cancelado',
}

/** Classes do chip. Sucesso em verde, falha em vermelho, o resto neutro. */
export function publicationTone(status: PublicationStatus): string {
  if (status === 'published') return 'bg-secondary-container text-secondary'
  if (status === 'failed') return 'bg-error-container text-error'
  if (status === 'unknown') return 'bg-tertiary-container text-on-surface'

  return 'bg-surface-container text-on-surface-variant'
}

/** dd/mm/aaaa às HH:MM, no fuso de quem le. */
export function formatDateTime(iso: string): string {
  const d = new Date(iso)

  return `${d.toLocaleDateString('pt-BR')} às ${d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })}`
}
