import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { HttpResponse, http } from 'msw'
import { afterEach, beforeEach, expect, test, vi } from 'vitest'
import { server } from '@/test/server'
import { renderWithProviders } from '@/test/utils'
import type { Content } from '@/lib/types'
import { CalendarPage } from './CalendarPage'

const ROUTE = { path: '/projects/:projectId/calendar', entry: '/projects/1/calendar' }

/** O mes visivel e o mes de hoje: as pecas do teste precisam cair nele. */
const HOJE = new Date(2026, 7, 5, 9, 0)

function isoNoMes(dia: number, hora = 10): string {
  return new Date(2026, 7, dia, hora, 0).toISOString()
}

function content(id: number, scheduledFor: string | null, status: Content['status']): Content {
  return {
    id,
    project_id: 1,
    title: `Peca ${id}`,
    caption: 'Legenda',
    cta: 'CTA',
    hashtags: [],
    format: 'reel',
    channel: 'instagram',
    status,
    scheduled_for: scheduledFor,
    image_prompt: null,
    latest_review: null,
    source: 'ai',
    origin_ai_run_id: null,
  }
}

beforeEach(() => {
  vi.useFakeTimers({ shouldAdvanceTime: true })
  vi.setSystemTime(HOJE)
})

afterEach(() => {
  vi.useRealTimers()
})

function setup() {
  return userEvent.setup({ advanceTimers: vi.advanceTimersByTime })
}

test('a peça agendada aparece no dia dela', async () => {
  server.use(
    http.get('/api/v1/projects/1/contents', () =>
      HttpResponse.json({ data: [content(1, isoNoMes(7), 'scheduled')] }),
    ),
  )

  renderWithProviders(<CalendarPage />, ROUTE)

  expect(await screen.findByText('agosto de 2026')).toBeInTheDocument()
  expect(await screen.findByRole('button', { name: /Peca 1/ })).toBeInTheDocument()
})

test('peça sem data não aparece no calendário', async () => {
  server.use(
    http.get('/api/v1/projects/1/contents', () =>
      HttpResponse.json({ data: [content(1, null, 'idea')] }),
    ),
  )

  renderWithProviders(<CalendarPage />, ROUTE)

  expect(await screen.findByText(/nenhuma peça agendada/i)).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: /Peca 1/ })).not.toBeInTheDocument()
})

test('navegar para o mês anterior esconde a peça de agosto', async () => {
  const user = setup()

  server.use(
    http.get('/api/v1/projects/1/contents', () =>
      HttpResponse.json({ data: [content(1, isoNoMes(7), 'scheduled')] }),
    ),
  )

  renderWithProviders(<CalendarPage />, ROUTE)

  await user.click(await screen.findByRole('button', { name: /mês anterior/i }))

  expect(await screen.findByText('julho de 2026')).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: /Peca 1/ })).not.toBeInTheDocument()
})

test('remarcar manda PATCH com a nova data e sem status', async () => {
  const user = setup()
  let recebido: unknown = null

  server.use(
    http.get('/api/v1/projects/1/contents', () =>
      HttpResponse.json({ data: [content(1, isoNoMes(7), 'scheduled')] }),
    ),
    http.patch('/api/v1/contents/1', async ({ request }) => {
      recebido = await request.json()
      return HttpResponse.json({ data: content(1, isoNoMes(12), 'scheduled') })
    }),
  )

  renderWithProviders(<CalendarPage />, ROUTE)

  await user.click(await screen.findByRole('button', { name: /Peca 1/ }))

  const campo = await screen.findByLabelText(/nova data/i)
  await user.clear(campo)
  await user.type(campo, '2026-08-12T18:30')
  await user.click(screen.getByRole('button', { name: /^salvar$/i }))

  await waitFor(() => expect(recebido).toEqual({ scheduled_for: '2026-08-12T18:30' }))
})

test('desagendar pelo calendário manda PATCH de status', async () => {
  const user = setup()
  let recebido: unknown = null

  server.use(
    http.get('/api/v1/projects/1/contents', () =>
      HttpResponse.json({ data: [content(1, isoNoMes(7), 'scheduled')] }),
    ),
    http.patch('/api/v1/contents/1', async ({ request }) => {
      recebido = await request.json()
      return HttpResponse.json({ data: content(1, null, 'approved') })
    }),
  )

  renderWithProviders(<CalendarPage />, ROUTE)

  await user.click(await screen.findByRole('button', { name: /Peca 1/ }))
  await user.click(await screen.findByRole('button', { name: /desagendar/i }))

  await waitFor(() => expect(recebido).toEqual({ status: 'approved' }))
})
