import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { HttpResponse, http } from 'msw'
import { afterEach, beforeEach, expect, test, vi } from 'vitest'
import { server } from '@/test/server'
import { currentSearch, renderWithProviders } from '@/test/utils'
import type { AiRun, Strategy } from '@/lib/types'
import { StrategyPage } from './StrategyPage'

const ROUTE = { path: '/projects/:projectId/strategy', entry: '/projects/1/strategy' }

const ESTRATEGIA: Strategy = {
  id: 7,
  workspace_id: 1,
  project_id: 1,
  title: 'Estratégia editorial trimestral',
  summary: 'Posicionar a marca como referência técnica.',
  editorial_line: 'Falar de resultado antes de falar de produto.',
  pillars: [
    { name: 'Educação', weight: 40, description: 'd' },
    { name: 'Prova social', weight: 35, description: 'd' },
    { name: 'Bastidores', weight: 25, description: 'd' },
  ],
  status: 'draft',
  ai_run_id: 42,
}

function run(overrides: Partial<AiRun>): AiRun {
  return {
    id: 42,
    agent: 'strategist',
    status: 'running',
    output: null,
    error: null,
    error_code: null,
    cost_cents: null,
    latency_ms: null,
    created_at: new Date().toISOString(),
    ...overrides,
  }
}

function strategies(...data: Strategy[]) {
  return http.get('/api/v1/projects/1/strategies', () => HttpResponse.json({ data }))
}

beforeEach(() => {
  // shouldAdvanceTime: sem isso o findBy* da Testing Library nunca resolve,
  // porque ele tambem depende de timer.
  vi.useFakeTimers({ shouldAdvanceTime: true })
})

afterEach(() => {
  vi.useRealTimers()
})

function setup() {
  return userEvent.setup({ advanceTimers: vi.advanceTimersByTime })
}

test('projeto sem estratégia oferece gerar', async () => {
  server.use(strategies())

  renderWithProviders(<StrategyPage />, ROUTE)

  expect(await screen.findByRole('button', { name: /gerar estratégia/i })).toBeInTheDocument()
})

test('caminho feliz: gera, faz polling e mostra a estratégia', async () => {
  const user = setup()
  let geradas: Strategy[] = []
  let chamadasDoRun = 0

  server.use(
    http.get('/api/v1/projects/1/strategies', () => HttpResponse.json({ data: geradas })),
    http.post('/api/v1/projects/1/strategies:generate', () =>
      HttpResponse.json({ ai_run_id: 42 }, { status: 202 }),
    ),
    http.get('/api/v1/ai-runs/42', () => {
      chamadasDoRun++
      if (chamadasDoRun === 1) return HttpResponse.json(run({ status: 'running' }))
      geradas = [ESTRATEGIA]
      return HttpResponse.json(run({ status: 'succeeded' }))
    }),
  )

  renderWithProviders(<StrategyPage />, ROUTE)

  await user.click(await screen.findByRole('button', { name: /gerar estratégia/i }))

  expect(await screen.findByText(/gerando/i)).toBeInTheDocument()

  expect(await screen.findByText('Estratégia editorial trimestral')).toBeInTheDocument()
  expect(screen.getByText('Educação')).toBeInTheDocument()
  expect(screen.getByText('Rascunho')).toBeInTheDocument()

  // succeeded limpa o ?run: a execucao nao interessa mais.
  await waitFor(() => expect(currentSearch()).toBe(''))
})
