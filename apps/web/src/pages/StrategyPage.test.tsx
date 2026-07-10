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

test('recusa mostra a mensagem e NÃO oferece tentar de novo', async () => {
  server.use(
    strategies(),
    http.get('/api/v1/ai-runs/42', () =>
      HttpResponse.json(
        run({
          status: 'failed',
          error: 'O modelo recusou esta requisicao.',
          error_code: 'refused',
        }),
      ),
    ),
  )

  renderWithProviders(<StrategyPage />, {
    path: ROUTE.path,
    entry: '/projects/1/strategy?run=42',
  })

  expect(await screen.findByText('O modelo recusou esta requisicao.')).toBeInTheDocument()

  // A assercao que justifica o error_code: insistir aqui e dano.
  expect(screen.queryByRole('button', { name: /tentar de novo/i })).not.toBeInTheDocument()
  expect(screen.getByRole('button', { name: /dispensar/i })).toBeInTheDocument()
})

test('falha transiente oferece tentar de novo, e o clique dispara um POST novo', async () => {
  const user = setup()
  let posts = 0

  server.use(
    strategies(),
    http.post('/api/v1/projects/1/strategies:generate', () => {
      posts++

      return HttpResponse.json({ ai_run_id: 99 }, { status: 202 })
    }),
    http.get('/api/v1/ai-runs/42', () =>
      HttpResponse.json(
        run({
          status: 'failed',
          error: 'Falha ao gerar. Tente novamente em alguns instantes.',
          error_code: 'provider_failed',
        }),
      ),
    ),
    http.get('/api/v1/ai-runs/99', () => HttpResponse.json(run({ id: 99, status: 'running' }))),
  )

  renderWithProviders(<StrategyPage />, {
    path: ROUTE.path,
    entry: '/projects/1/strategy?run=42',
  })

  await user.click(await screen.findByRole('button', { name: /tentar de novo/i }))

  await waitFor(() => expect(posts).toBe(1))
  expect(await screen.findByText(/gerando/i)).toBeInTheDocument()
})

test('402 mostra o orçamento e não inicia polling', async () => {
  const user = setup()

  server.use(
    strategies(),
    http.post('/api/v1/projects/1/strategies:generate', () =>
      HttpResponse.json(
        {
          message: 'Orcamento mensal de IA esgotado para este espaco de trabalho.',
          spent_cents: 5000,
          limit_cents: 5000,
        },
        { status: 402 },
      ),
    ),
    // Nao registramos /ai-runs/*: se a tela fizer polling, o MSW estoura com
    // onUnhandledRequest: 'error' e o teste quebra. E o ponto.
  )

  renderWithProviders(<StrategyPage />, ROUTE)

  await user.click(await screen.findByRole('button', { name: /gerar estratégia/i }))

  expect(await screen.findByText(/orçamento mensal de ia esgotado/i)).toBeInTheDocument()
  expect(screen.getByText(/US\$ 50\.00 de US\$ 50\.00/)).toBeInTheDocument()
  expect(currentSearch()).toBe('')
  expect(screen.queryByText(/gerando/i)).not.toBeInTheDocument()
})
