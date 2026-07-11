import type { Content, ContentStatus } from '@/lib/types'

/**
 * As colunas visiveis do quadro. `published` fica de fora: exige publicar, que nao
 * existe. Uma peca nesse estado simplesmente nao aparece em nenhuma coluna.
 */
export const COLUMNS = [
  'idea',
  'production',
  'review',
  'approved',
  'scheduled',
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
