import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { HttpResponse, http } from 'msw'
import { expect, test } from 'vitest'
import { server } from '@/test/server'
import { renderWithProviders } from '@/test/utils'
import type { Invitation, Me, WorkspaceSummary } from '@/lib/types'
import { TeamPage } from './TeamPage'

const ROUTE = { path: '/equipe', entry: '/equipe' }

function me(role: WorkspaceSummary['role']): Me {
  return {
    id: 1,
    name: 'Djair',
    email: 'djair@example.com',
    workspaces: [{ id: 7, name: '2M Negócios', slug: '2m', role }],
  }
}

const pendente: Invitation = {
  id: 3,
  email: 'ana@example.com',
  role: 'editor',
  token: 'tok-ana',
  expires_at: '2026-09-25T12:00:00Z',
}

function servidor(role: WorkspaceSummary['role'], convites: Invitation[] = []) {
  server.use(
    http.get('/api/v1/me', () => HttpResponse.json(me(role))),
    http.get('/api/v1/workspaces/7/invitations', () => HttpResponse.json({ data: convites })),
  )
}

test('lista os convites pendentes com o link de cada um', async () => {
  servidor('admin', [pendente])

  renderWithProviders(<TeamPage />, ROUTE)

  const linha = (await screen.findByText('ana@example.com')).closest('li')!
  expect(within(linha).getByText('Editor')).toBeInTheDocument()
  expect(within(linha).getByText(`${window.location.origin}/convite/tok-ana`)).toBeInTheDocument()
  expect(within(linha).getByRole('button', { name: /copiar link/i })).toBeInTheDocument()
})

test('convidar manda email e papel e mostra o link do convite novo', async () => {
  let enviado: unknown = null
  servidor('admin')
  server.use(
    http.post('/api/v1/workspaces/7/invitations', async ({ request }) => {
      enviado = await request.json()
      return HttpResponse.json(
        { data: { ...pendente, email: 'bia@example.com', role: 'reviewer', token: 'tok-bia' } },
        { status: 201 },
      )
    }),
  )

  renderWithProviders(<TeamPage />, ROUTE)
  const user = userEvent.setup()

  await user.type(await screen.findByLabelText(/e-mail/i), 'bia@example.com')
  await user.selectOptions(screen.getByLabelText(/papel/i), 'reviewer')
  await user.click(screen.getByRole('button', { name: /gerar convite/i }))

  expect(await screen.findByText(`${window.location.origin}/convite/tok-bia`)).toBeInTheDocument()
  expect(enviado).toEqual({ email: 'bia@example.com', role: 'reviewer' })
})

/** O backend recusa papel acima do de quem convida; a tela nem oferece. */
test('admin nao ve a opcao de convidar dono', async () => {
  servidor('admin')

  renderWithProviders(<TeamPage />, ROUTE)

  const papel = await screen.findByLabelText(/papel/i)
  const opcoes = within(papel)
    .getAllByRole('option')
    .map((o) => (o as HTMLOptionElement).value)

  expect(opcoes).toEqual(['admin', 'editor', 'reviewer', 'viewer'])
})

test('dono pode convidar qualquer papel', async () => {
  servidor('owner')

  renderWithProviders(<TeamPage />, ROUTE)

  const papel = await screen.findByLabelText(/papel/i)
  expect(within(papel).getAllByRole('option')).toHaveLength(5)
})

test('recusa do servidor aparece na tela', async () => {
  servidor('admin')
  server.use(
    http.post('/api/v1/workspaces/7/invitations', () =>
      HttpResponse.json({ message: 'Esta pessoa ja e membro deste workspace.' }, { status: 422 }),
    ),
  )

  renderWithProviders(<TeamPage />, ROUTE)
  const user = userEvent.setup()

  await user.type(await screen.findByLabelText(/e-mail/i), 'djair@example.com')
  await user.click(screen.getByRole('button', { name: /gerar convite/i }))

  expect(await screen.findByText('Esta pessoa ja e membro deste workspace.')).toBeInTheDocument()
})

/** Quem nao administra nao convida — e a rota de listagem devolveria 403. */
test('editor ve aviso e nenhuma chamada de convites e feita', async () => {
  server.use(http.get('/api/v1/me', () => HttpResponse.json(me('editor'))))

  renderWithProviders(<TeamPage />, ROUTE)

  expect(await screen.findByText(/só administradores convidam/i)).toBeInTheDocument()
  expect(screen.queryByLabelText(/e-mail/i)).not.toBeInTheDocument()
})
