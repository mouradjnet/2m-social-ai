import { screen } from '@testing-library/react'
import { HttpResponse, http } from 'msw'
import { expect, test } from 'vitest'
import { server } from '@/test/server'
import { renderWithProviders } from '@/test/utils'
import type { Me, WorkspaceSummary } from '@/lib/types'
import { ProjectsPage } from './ProjectsPage'

const ROUTE = { path: '/', entry: '/' }

function servidor(role: WorkspaceSummary['role']) {
  const me: Me = {
    id: 1,
    name: 'Ana',
    email: 'ana@example.com',
    workspaces: [{ id: 7, name: '2M Negócios', slug: '2m', role }],
  }

  server.use(
    http.get('/api/v1/me', () => HttpResponse.json(me)),
    http.get('/api/v1/workspaces/7/projects', () => HttpResponse.json({ data: [] })),
  )
}

test('mostra o papel em portugues', async () => {
  servidor('reviewer')

  renderWithProviders(<ProjectsPage />, ROUTE)

  expect(await screen.findByText('2M Negócios · você é Revisor')).toBeInTheDocument()
})

/** A rota de criar projeto exige editor: oferecer o form a quem nao pode e mentir. */
test('revisor nao ve o formulario de novo projeto', async () => {
  servidor('reviewer')

  renderWithProviders(<ProjectsPage />, ROUTE)

  expect(await screen.findByText('Nenhum projeto ainda.')).toBeInTheDocument()
  expect(screen.queryByText('Novo projeto')).not.toBeInTheDocument()
  expect(screen.queryByRole('link', { name: 'Equipe' })).not.toBeInTheDocument()
})

test('leitor tambem nao ve o formulario', async () => {
  servidor('viewer')

  renderWithProviders(<ProjectsPage />, ROUTE)

  expect(await screen.findByText(/você é Leitor/)).toBeInTheDocument()
  expect(screen.queryByText('Novo projeto')).not.toBeInTheDocument()
})

test('editor ve o formulario, mas nao a equipe', async () => {
  servidor('editor')

  renderWithProviders(<ProjectsPage />, ROUTE)

  expect(await screen.findByText('Novo projeto')).toBeInTheDocument()
  expect(await screen.findByText('Nenhum projeto ainda. Crie o primeiro acima.')).toBeInTheDocument()
  expect(screen.queryByRole('link', { name: 'Equipe' })).not.toBeInTheDocument()
})

test('dono ve o formulario e a equipe', async () => {
  servidor('owner')

  renderWithProviders(<ProjectsPage />, ROUTE)

  expect(await screen.findByText(/você é Dono/)).toBeInTheDocument()
  expect(screen.getByText('Novo projeto')).toBeInTheDocument()
  expect(screen.getByRole('link', { name: 'Equipe' })).toBeInTheDocument()
})
