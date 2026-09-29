import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { HttpResponse, http } from 'msw'
import { expect, test, vi } from 'vitest'
import { server } from '@/test/server'
import { renderWithProviders } from '@/test/utils'
import type { WeekPlan } from '@/lib/types'
import { WeekPlanPanel } from './WeekPlanPanel'

const ROUTE = { path: '/projects/:projectId/content', entry: '/projects/1/content' }

function plano(overrides: Partial<WeekPlan> = {}): WeekPlan {
  return {
    id: 4,
    period_start: '2026-10-05',
    period_end: '2026-10-11',
    posts_count: 2,
    contents_count: 0,
    created_at: '2026-09-29T12:00:00Z',
    distribution: {
      summary: 'Semana focada em prevenção.',
      slots: [
        { date: '2026-10-06', time: '19:00', pillar: 'Prevenção e exames', format: 'carousel', channel: 'instagram', theme: 'Quando fazer o papanicolau', rationale: 'Pilar atrasado.' },
        { date: '2026-10-08', time: '12:30', pillar: 'Educação em saúde', format: 'post', channel: 'instagram', theme: 'Fases do ciclo', rationale: 'Horário de almoço.' },
      ],
    },
    ...overrides,
  }
}

test('mostra os horarios do plano e manda escrever as pecas dele', async () => {
  server.use(http.get('/api/v1/projects/1/week-plan', () => HttpResponse.json({ data: plano() })))
  const onWrite = vi.fn()

  renderWithProviders(<WeekPlanPanel projectId="1" generating={false} onPlan={() => {}} onWrite={onWrite} />, ROUTE)

  const primeiro = (await screen.findByText('Quando fazer o papanicolau')).closest('li')!
  // 06/10/2026 e uma terca: o dia da semana sai da data local, sem fuso do navegador.
  expect(within(primeiro).getByText('ter 06/10 · 19:00 · Carrossel · Prevenção e exames')).toBeInTheDocument()
  expect(screen.getByText('Semana focada em prevenção.')).toBeInTheDocument()

  await userEvent.setup().click(screen.getByRole('button', { name: 'Escrever as peças do plano (2)' }))
  expect(onWrite).toHaveBeenCalledWith(4)
})

test('plano ja escrito nao oferece escrever de novo', async () => {
  server.use(http.get('/api/v1/projects/1/week-plan', () => HttpResponse.json({ data: plano({ contents_count: 2 }) })))

  renderWithProviders(<WeekPlanPanel projectId="1" generating={false} onPlan={() => {}} onWrite={() => {}} />, ROUTE)

  expect(await screen.findByText(/As 2 peças deste plano já foram escritas/)).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: /Escrever as peças/ })).not.toBeInTheDocument()
})
