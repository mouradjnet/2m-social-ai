import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { HttpResponse, http } from 'msw'
import { afterEach, beforeEach, expect, test, vi } from 'vitest'
import { server } from '@/test/server'
import { renderWithProviders } from '@/test/utils'
import type { AiRun, Content } from '@/lib/types'
import { ContentPage } from './ContentPage'

const ROUTE = { path: '/projects/:projectId/content', entry: '/projects/1/content' }

function content(id: number, status: Content['status']): Content {
  return {
    id,
    project_id: 1,
    title: `Peca ${id}`,
    caption: 'Legenda da peca.',
    cta: 'Chame no WhatsApp',
    hashtags: ['#marca'],
    format: 'post',
    channel: 'instagram',
    status,
    source: 'ai',
    origin_ai_run_id: 7,
  }
}

function run(overrides: Partial<AiRun>): AiRun {
  return {
    id: 42,
    agent: 'copywriter',
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

beforeEach(() => {
  vi.useFakeTimers({ shouldAdvanceTime: true })
})

afterEach(() => {
  vi.useRealTimers()
})

function setup() {
  return userEvent.setup({ advanceTimers: vi.advanceTimersByTime })
}

test('lista vazia mostra o botão gerar e a frase de vazio', async () => {
  server.use(http.get('/api/v1/projects/1/contents', () => HttpResponse.json({ data: [] })))

  renderWithProviders(<ContentPage />, ROUTE)

  expect(await screen.findByRole('button', { name: /gerar conteúdo/i })).toBeInTheDocument()
  expect(screen.getByText(/ainda não há conteúdo/i)).toBeInTheDocument()
})

test('agrupa as peças: 5 em Ideia, colunas seguintes vazias', async () => {
  server.use(
    http.get('/api/v1/projects/1/contents', () =>
      HttpResponse.json({ data: [1, 2, 3, 4, 5].map((i) => content(i, 'idea')) }),
    ),
  )

  renderWithProviders(<ContentPage />, ROUTE)

  expect(await screen.findByText('Ideia (5)')).toBeInTheDocument()
  expect(screen.getByText('Produção (0)')).toBeInTheDocument()
  expect(screen.getByText('Aprovado (0)')).toBeInTheDocument()
})

test('avançar uma peça faz PATCH com o próximo status', async () => {
  const user = setup()
  let recebido: unknown = null

  server.use(
    http.get('/api/v1/projects/1/contents', () => HttpResponse.json({ data: [content(1, 'idea')] })),
    http.patch('/api/v1/contents/1', async ({ request }) => {
      recebido = await request.json()
      return HttpResponse.json({ data: { ...content(1, 'production') } })
    }),
  )

  renderWithProviders(<ContentPage />, ROUTE)

  await user.click(await screen.findByRole('button', { name: /avançar/i }))

  await waitFor(() => expect(recebido).toEqual({ status: 'production' }))
})

test('o card de source=ai mostra o chip Gerado por IA', async () => {
  server.use(
    http.get('/api/v1/projects/1/contents', () => HttpResponse.json({ data: [content(1, 'idea')] })),
  )

  renderWithProviders(<ContentPage />, ROUTE)

  expect(await screen.findByText(/gerado por ia/i)).toBeInTheDocument()
})

test('gerar: clique dispara POST e mostra o estado de geração', async () => {
  const user = setup()

  server.use(
    http.get('/api/v1/projects/1/contents', () => HttpResponse.json({ data: [] })),
    http.post('/api/v1/projects/1/copy:generate', () =>
      HttpResponse.json({ ai_run_id: 42 }, { status: 202 }),
    ),
    http.get('/api/v1/ai-runs/42', () => HttpResponse.json(run({ status: 'running' }))),
  )

  renderWithProviders(<ContentPage />, ROUTE)

  await user.click(await screen.findByRole('button', { name: /gerar conteúdo/i }))

  expect(await screen.findByText(/gerando/i)).toBeInTheDocument()
})

test('422 sem estratégia ativa mostra a mensagem e não inicia polling', async () => {
  const user = setup()

  server.use(
    http.get('/api/v1/projects/1/contents', () => HttpResponse.json({ data: [] })),
    http.post('/api/v1/projects/1/copy:generate', () =>
      HttpResponse.json(
        { message: 'Aprove uma estrategia antes de gerar conteudo.' },
        { status: 422 },
      ),
    ),
    // Sem handler /ai-runs/*: se a tela fizer polling, o MSW estoura.
  )

  renderWithProviders(<ContentPage />, ROUTE)

  await user.click(await screen.findByRole('button', { name: /gerar conteúdo/i }))

  expect(await screen.findByText(/aprove uma estrategia/i)).toBeInTheDocument()
  expect(screen.queryByText(/gerando/i)).not.toBeInTheDocument()
})
