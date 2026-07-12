import { expect, test } from 'vitest'
import type { Content } from '@/lib/types'
import { buildMonth, sameLocalDay, shiftMonth } from './calendarGrid'

function scheduled(id: number, iso: string): Content {
  return {
    id,
    project_id: 1,
    title: `Peca ${id}`,
    caption: 'c',
    cta: 'x',
    hashtags: [],
    format: 'post',
    channel: 'instagram',
    status: 'scheduled',
    scheduled_for: iso,
    image_prompt: null,
    latest_review: null,
    source: 'ai',
    origin_ai_run_id: null,
  }
}

// Agosto de 2026 comeca num sabado e tem 31 dias.
const AGOSTO = new Date(2026, 7, 1)

test('o mes vira semanas de 7 dias, comecando no domingo', () => {
  const semanas = buildMonth(AGOSTO, [])

  for (const semana of semanas) {
    expect(semana).toHaveLength(7)
  }

  expect(semanas[0][0].date.getDay()).toBe(0)
})

test('as bordas sao preenchidas com os dias vizinhos, marcados como de fora', () => {
  const semanas = buildMonth(AGOSTO, [])
  const primeiro = semanas[0]

  // 01/08/2026 e sabado: os 6 primeiros dias da grade vem de julho.
  expect(primeiro[0].inMonth).toBe(false)
  expect(primeiro[0].date.getMonth()).toBe(6)
  expect(primeiro[6].inMonth).toBe(true)
  expect(primeiro[6].date.getDate()).toBe(1)
})

test('todo dia do mes aparece exatamente uma vez', () => {
  const dias = buildMonth(AGOSTO, [])
    .flat()
    .filter((d) => d.inMonth)
    .map((d) => d.date.getDate())

  expect(dias).toHaveLength(31)
  expect(new Set(dias).size).toBe(31)
})

test('a peca cai no dia em que foi agendada', () => {
  const peca = scheduled(1, '2026-08-07T18:30:00-03:00')
  const dia7 = buildMonth(AGOSTO, [peca])
    .flat()
    .find((d) => d.inMonth && d.date.getDate() === 7)

  expect(dia7?.contents).toHaveLength(1)
  expect(dia7?.contents[0].id).toBe(1)
})

test('peca sem data nao entra em dia nenhum', () => {
  const semData = { ...scheduled(1, '2026-08-07T18:30:00-03:00'), scheduled_for: null }
  const total = buildMonth(AGOSTO, [semData])
    .flat()
    .reduce((n, d) => n + d.contents.length, 0)

  expect(total).toBe(0)
})

test('peca de outro mes nao aparece na grade de agosto', () => {
  const setembro = scheduled(1, '2026-09-10T10:00:00-03:00')
  const dia = buildMonth(AGOSTO, [setembro])
    .flat()
    .find((d) => d.contents.length > 0)

  expect(dia).toBeUndefined()
})

test('shiftMonth anda de mes sem estourar o ano', () => {
  expect(shiftMonth(new Date(2026, 11, 1), 1).getMonth()).toBe(0)
  expect(shiftMonth(new Date(2026, 11, 1), 1).getFullYear()).toBe(2027)
  expect(shiftMonth(new Date(2026, 0, 1), -1).getMonth()).toBe(11)
  expect(shiftMonth(new Date(2026, 0, 1), -1).getFullYear()).toBe(2025)
})

/** O 31/01 + 1 mes nao pode virar 03/03: comparar sempre o primeiro do mes. */
test('shiftMonth a partir do dia 31 nao pula um mes', () => {
  expect(shiftMonth(new Date(2026, 0, 31), 1).getMonth()).toBe(1)
})

test('sameLocalDay compara o dia local, nao o instante', () => {
  expect(sameLocalDay(new Date(2026, 7, 7, 23, 59), new Date(2026, 7, 7, 0, 1))).toBe(true)
  expect(sameLocalDay(new Date(2026, 7, 7), new Date(2026, 7, 8))).toBe(false)
})
