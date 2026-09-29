import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { HttpResponse, http } from 'msw'
import { expect, test } from 'vitest'
import { server } from '@/test/server'
import { renderWithProviders } from '@/test/utils'
import type { Results } from '@/lib/types'
import { ResultsPage } from './ResultsPage'

const ROUTE = { path: '/projects/:projectId/results', entry: '/projects/1/results' }

function resultados(overrides: Partial<Results> = {}): Results {
  return {
    days: 30,
    published: 3,
    measured: 2,
    totals: { reach: 2000, views: 3100, likes: 90, comments: 12, saved: 40, shares: 8, total_interactions: 120 },
    engagement_rate: 6,
    by_pillar: [{ name: 'Prevenção', posts: 1, avg_reach: 1200, avg_interactions: 96, engagement_rate: 8 }],
    by_format: [{ name: 'carousel', posts: 1, avg_reach: 1200, avg_interactions: 96, engagement_rate: 8 }],
    posts: [
      {
        publication_id: 1, content_id: 10, title: 'Papanicolau', pillar: 'Prevenção', format: 'carousel',
        published_at: '2026-09-25T22:00:00Z', permalink: 'https://www.instagram.com/p/ABC/', state: 'measured',
        metrics: { reach: 1200, likes: 60, comments: 8, saved: 40 }, error: null, collected_at: '2026-09-28T09:40:00Z',
      },
      {
        publication_id: 2, content_id: 11, title: 'Recém-publicado', pillar: 'Educação', format: 'post',
        published_at: '2026-09-29T11:00:00Z', permalink: null, state: 'pending', metrics: null, error: null, collected_at: null,
      },
    ],
    ...overrides,
  }
}

test('mostra so numeros da Meta e diz qual post ainda nao foi medido', async () => {
  server.use(
    http.get('/api/v1/projects/1/results', () =>
      HttpResponse.json({ data: resultados(), account: { username: '2msaudefeminina', insights_enabled: true } }),
    ),
  )

  renderWithProviders(<ResultsPage />, ROUTE)

  expect(await screen.findByText('2.000')).toBeInTheDocument()
  expect(screen.getByText('6%')).toBeInTheDocument()
  expect(screen.getByText('2 de 3')).toBeInTheDocument()
  expect(screen.getByText(/Números medidos pelo Instagram em @2msaudefeminina/)).toBeInTheDocument()

  const medido = screen.getByRole('link', { name: 'Papanicolau' }).closest('tr')!
  expect(within(medido).getByText('1.200')).toBeInTheDocument()
  // Metrica que a Meta nao devolveu aparece como traco, nunca como zero.
  expect(within(medido).getByText('—')).toBeInTheDocument()

  const pendente = screen.getByText('Recém-publicado').closest('tr')!
  expect(within(pendente).getByText('Aguardando a Meta medir')).toBeInTheDocument()
})

test('conta sem permissao de metricas pede reconexao, e o periodo muda a consulta', async () => {
  const pedidos: string[] = []
  server.use(
    http.get('/api/v1/projects/1/results', ({ request }) => {
      pedidos.push(new URL(request.url).search)
      return HttpResponse.json({
        data: resultados({ measured: 0, published: 1, posts: [], engagement_rate: null }),
        account: { username: '2msaudefeminina', insights_enabled: false },
      })
    }),
  )

  renderWithProviders(<ResultsPage />, ROUTE)

  expect(await screen.findByRole('status')).toHaveTextContent('conectada sem a permissão de métricas')
  expect(screen.getByText('—')).toBeInTheDocument()

  await userEvent.setup().selectOptions(screen.getByLabelText('Período'), '90')
  expect(await screen.findByText('Os posts ainda não foram medidos. A coleta roda uma vez por dia e a Meta atrasa até 48 h.')).toBeInTheDocument()
  expect(pedidos).toEqual(['?days=30', '?days=90'])
})
