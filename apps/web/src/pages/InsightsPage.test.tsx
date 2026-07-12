import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { HttpResponse, http } from 'msw'
import { afterEach, beforeEach, expect, test, vi } from 'vitest'
import { server } from '@/test/server'
import { renderWithProviders } from '@/test/utils'
import type { AnalyticsResponse, ProjectMetrics } from '@/lib/types'
import { InsightsPage } from './InsightsPage'

const ROUTE = { path: '/projects/:projectId/insights', entry: '/projects/1/insights' }

const metrics: ProjectMetrics = {
  volume: { total: 5, por_status: { idea: 3, approved: 2 } },
  aderencia: {
    pilares: [
      { nome: 'Educação', peso_pedido: 50, peso_real: 75, pecas: 3, desvio: 25 },
      { nome: 'Prova social', peso_pedido: 50, peso_real: 25, pecas: 1, desvio: -25 },
    ],
    pecas_com_pilar: 4,
    sem_pilar: 1,
  },
  cadencia: { agendadas_30_dias: 3, dias_com_peca: 3, maior_lacuna_dias: 7 },
  qualidade: {
    revisadas: 2,
    aprovadas: 1,
    reprovadas: 1,
    violacoes: 2,
    regras_mais_violadas: [{ regra: 'tom de voz', vezes: 2 }],
  },
  mix: { por_canal: { instagram: 4, blog: 1 }, por_formato: { post: 5 } },
}

const response = (data: AnalyticsResponse['data']): AnalyticsResponse => ({ data, metrics })

beforeEach(() => {
  vi.useFakeTimers({ shouldAdvanceTime: true })
})

afterEach(() => {
  vi.useRealTimers()
})

function setup() {
  return userEvent.setup({ advanceTimers: vi.advanceTimersByTime })
}

test('sem relatório, mostra os números e o convite a gerar', async () => {
  server.use(
    http.get('/api/v1/projects/1/analytics', () => HttpResponse.json(response(null))),
  )

  renderWithProviders(<InsightsPage />, ROUTE)

  // Os numeros existem antes de a IA opinar sobre eles.
  expect(await screen.findByText('5')).toBeInTheDocument()
  expect(screen.getByText(/ainda não há leitura/i)).toBeInTheDocument()
  expect(screen.getByRole('button', { name: /gerar relatório/i })).toBeEnabled()
})

test('mostra a pontuação, o resumo e os insights com a ação', async () => {
  server.use(
    http.get('/api/v1/projects/1/analytics', () =>
      HttpResponse.json(
        response({
          id: 1,
          score: 72,
          summary: 'O calendário está concentrado em um pilar.',
          insights: [
            {
              title: 'Educação domina',
              detail: 'Três de cada quatro peças saem do mesmo pilar.',
              action: 'Gerar duas peças de Prova social.',
            },
          ],
          metrics,
          created_at: new Date().toISOString(),
        }),
      ),
    ),
  )

  renderWithProviders(<InsightsPage />, ROUTE)

  expect(await screen.findByText('72')).toBeInTheDocument()
  expect(screen.getByText(/concentrado em um pilar/i)).toBeInTheDocument()
  expect(screen.getByText('Educação domina')).toBeInTheDocument()
  expect(screen.getByText(/gerar duas peças de prova social/i)).toBeInTheDocument()
})

test('a aderência mostra o desvio de cada pilar', async () => {
  server.use(
    http.get('/api/v1/projects/1/analytics', () => HttpResponse.json(response(null))),
  )

  renderWithProviders(<InsightsPage />, ROUTE)

  expect(await screen.findByText('Educação')).toBeInTheDocument()
  // Pedido 50%, real 75% -> +25 pp.
  expect(screen.getByText(/\+25 pp/)).toBeInTheDocument()
  expect(screen.getByText(/−25 pp|-25 pp/)).toBeInTheDocument()
  // Peca sem pilar e contada, nao escondida.
  expect(screen.getByText(/1 peça sem pilar/i)).toBeInTheDocument()
})

test('gerar relatório dispara POST para analytics:generate', async () => {
  const user = setup()
  let chamado = false

  server.use(
    http.get('/api/v1/projects/1/analytics', () => HttpResponse.json(response(null))),
    http.post('/api/v1/projects/1/analytics:generate', () => {
      chamado = true
      return HttpResponse.json({ ai_run_id: 42 }, { status: 202 })
    }),
    http.get('/api/v1/ai-runs/42', () =>
      HttpResponse.json({
        id: 42,
        agent: 'analytics',
        status: 'running',
        output: null,
        error: null,
        error_code: null,
        cost_cents: null,
        latency_ms: null,
        created_at: new Date().toISOString(),
      }),
    ),
  )

  renderWithProviders(<InsightsPage />, ROUTE)

  await user.click(await screen.findByRole('button', { name: /gerar relatório/i }))

  await waitFor(() => expect(chamado).toBe(true))
})
