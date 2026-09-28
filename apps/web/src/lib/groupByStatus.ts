import type { Content, ContentStatus } from '@/lib/types'

/**
 * As colunas visiveis do quadro. `published` ganhou coluna quando o sistema passou a
 * publicar no Instagram (ADR-13): antes, uma peca publicada sumia do quadro.
 */
export const COLUMNS = [
  'idea',
  'production',
  'review',
  'approved',
  'scheduled',
  'published',
  'archived',
] as const

export type Column = (typeof COLUMNS)[number]

export type Grouped = Record<Column, Content[]>

/** Distribui as pecas nas colunas visiveis, preservando a ordem de entrada. */
export function groupByStatus(contents: Content[]): Grouped {
  const groups = {} as Grouped
  for (const col of COLUMNS) {
    groups[col] = []
  }

  for (const content of contents) {
    if (isColumn(content.status)) {
      groups[content.status].push(content)
    }
  }

  return groups
}

function isColumn(status: ContentStatus): status is Column {
  return (COLUMNS as readonly string[]).includes(status)
}
