import { expect, test } from 'vitest'
import type { Content } from '@/lib/types'
import { COLUMNS, groupByStatus } from './groupByStatus'

function content(id: number, status: Content['status']): Content {
  return {
    id,
    project_id: 1,
    title: `Peca ${id}`,
    caption: 'c',
    cta: 'x',
    hashtags: ['#a'],
    format: 'post',
    channel: 'instagram',
    status,
    source: 'ai',
    origin_ai_run_id: 7,
  }
}

test('agrupa as pecas nas colunas por status', () => {
  const groups = groupByStatus([
    content(1, 'idea'),
    content(2, 'idea'),
    content(3, 'review'),
  ])

  expect(groups.idea).toHaveLength(2)
  expect(groups.review).toHaveLength(1)
  expect(groups.production).toEqual([])
  expect(groups.approved).toEqual([])
  expect(groups.archived).toEqual([])
})

test('as colunas visiveis sao as cinco do fluxo + arquivado, nesta ordem', () => {
  expect(COLUMNS).toEqual(['idea', 'production', 'review', 'approved', 'archived'])
})

test('um status oculto (published) nao aparece em nenhuma coluna', () => {
  const groups = groupByStatus([content(1, 'published')])

  for (const col of COLUMNS) {
    expect(groups[col]).toEqual([])
  }
})
