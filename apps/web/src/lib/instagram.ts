import type { ActivityEntry, Content, PublicationStatus } from '@/lib/types'

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

/** Uma linha do registro de atividade, em portugues. Acao desconhecida sai crua. */
export function describeActivity(entry: ActivityEntry): string {
  const quem = entry.user?.name ?? 'Alguém'
  const { meta } = entry

  switch (entry.action) {
    case 'instagram.connected':
      return `${quem} conectou @${meta.username}`
    case 'instagram.disconnected':
      return `${quem} desconectou @${meta.username}`
    case 'publication.resolved':
      return `${quem} decidiu que "${meta.content_title}" ${meta.outcome === 'published' ? 'está no ar' : 'não saiu'}`
    case 'asset.deleted':
      return `${quem} removeu a imagem ${meta.original_name ?? ''}`.trimEnd()
    default:
      return `${quem}: ${entry.action}`
  }
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
