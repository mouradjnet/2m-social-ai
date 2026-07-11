import type { Content } from '@/lib/types'

export interface Day {
  date: Date
  /** Falso para os dias vizinhos que preenchem a borda da grade. */
  inMonth: boolean
  contents: Content[]
}

/** Mesmo dia no fuso do usuario. Comparar ISO em texto erraria na virada do dia. */
export function sameLocalDay(a: Date, b: Date): boolean {
  return (
    a.getFullYear() === b.getFullYear() &&
    a.getMonth() === b.getMonth() &&
    a.getDate() === b.getDate()
  )
}

/**
 * Anda `delta` meses. Sempre a partir do dia 1: `new Date(2026, 0, 31)` mais um mes
 * daria 03/03, porque fevereiro nao tem dia 31.
 */
export function shiftMonth(month: Date, delta: number): Date {
  return new Date(month.getFullYear(), month.getMonth() + delta, 1)
}

/**
 * A grade do mes: semanas de domingo a sabado, com os dias vizinhos preenchendo as
 * bordas. Cada dia carrega as pecas agendadas nele.
 */
export function buildMonth(month: Date, contents: Content[]): Day[][] {
  const first = new Date(month.getFullYear(), month.getMonth(), 1)
  const start = new Date(first)
  start.setDate(1 - first.getDay())

  const agendadas = contents
    .filter((c) => c.scheduled_for !== null)
    .map((c) => ({ content: c, when: new Date(c.scheduled_for as string) }))

  const weeks: Day[][] = []
  const cursor = new Date(start)

  // Enquanto o cursor nao tiver passado do mes: a ultima semana entra inteira.
  while (cursor.getMonth() === month.getMonth() || cursor < first) {
    const week: Day[] = []

    for (let i = 0; i < 7; i++) {
      const date = new Date(cursor)

      week.push({
        date,
        inMonth: date.getMonth() === month.getMonth(),
        contents: agendadas.filter((a) => sameLocalDay(a.when, date)).map((a) => a.content),
      })

      cursor.setDate(cursor.getDate() + 1)
    }

    weeks.push(week)
  }

  return weeks
}
