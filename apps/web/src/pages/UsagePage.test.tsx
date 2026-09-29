import { screen, within } from '@testing-library/react'
import { HttpResponse, http } from 'msw'
import { expect, test } from 'vitest'
import { server } from '@/test/server'
import { renderWithProviders } from '@/test/utils'
import type { Me, Usage, WorkspaceSummary } from '@/lib/types'
import { UsagePage } from './UsagePage'

const ROUTE = { path: '/consumo', entry: '/consumo' }

function me(role: WorkspaceSummary['role']): Me {
  return {
    id: 1,
    name: 'Djair',
    email: 'djair@example.com',
    workspaces: [{ id: 7, name: '2M Negócios', slug: '2m', role }],
  }
}

const usage: Usage = {
  month: '2026-09',
  spent_cents: 4600,
  limit_cents: 5000,
  limit_source: 'workspace',
  by_agent: [
    { agent: 'copywriter', runs: 12, cost_cents: 4000 },
    { agent: 'reviewer', runs: 3, cost_cents: 600 },
  ],
  by_project: [{ project_id: 6, name: '2M Saúde Feminina', runs: 15, cost_cents: 4600 }],
}

test('mostra o gasto contra o teto e onde foi gasto', async () => {
  server.use(
    http.get('/api/v1/me', () => HttpResponse.json(me('admin'))),
    http.get('/api/v1/workspaces/7/usage', () => HttpResponse.json({ data: usage })),
  )

  renderWithProviders(<UsagePage />, ROUTE)

  expect(await screen.findByLabelText('Gasto do mês')).toHaveTextContent('US$ 46,00 de US$ 50,00')
  // 92%: perto do teto, a barra muda de cor e o texto avisa o que acontece ao atingir.
  expect(screen.getByRole('progressbar')).toHaveAttribute('aria-valuenow', '92')
  expect(screen.getByText(/92% do teto usado/)).toHaveTextContent('Teto definido para este espaço.')

  const redacao = screen.getByText('Redação').closest('tr')!
  expect(within(redacao).getByText('12')).toBeInTheDocument()
  expect(within(redacao).getByText('US$ 40,00')).toBeInTheDocument()
  expect(screen.getByText('2M Saúde Feminina')).toBeInTheDocument()
})

test('editor nao ve o consumo e a tela nem pede', async () => {
  server.use(http.get('/api/v1/me', () => HttpResponse.json(me('editor'))))

  renderWithProviders(<UsagePage />, ROUTE)

  expect(await screen.findByText('Só administradores veem o consumo de IA.')).toBeInTheDocument()
})
