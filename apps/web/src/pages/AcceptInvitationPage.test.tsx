import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { HttpResponse, http } from 'msw'
import { expect, test } from 'vitest'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { server } from '@/test/server'
import { setToken } from '@/lib/api'
import { AcceptInvitationPage } from './AcceptInvitationPage'

/** Onde o usuario foi parar: caminho + query, lidos do router. */
function Destino({ nome }: { nome: string }) {
  const { pathname, search } = useLocation()
  return <p data-testid="destino">{`${nome} ${pathname}${search}`}</p>
}

function renderizar() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={['/convite/tok-ana']}>
        <Routes>
          <Route path="/convite/:token" element={<AcceptInvitationPage />} />
          <Route path="/login" element={<Destino nome="login" />} />
          <Route path="/" element={<Destino nome="home" />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

test('sem login, manda para o login e volta ao convite depois', async () => {
  renderizar()

  expect(await screen.findByTestId('destino')).toHaveTextContent(
    'login /login?next=%2Fconvite%2Ftok-ana',
  )
})

test('aceitar chama a API com o token e leva aos projetos', async () => {
  setToken('t')
  let chamado = false
  server.use(
    http.post('/api/v1/invitations/tok-ana:accept', () => {
      chamado = true
      return HttpResponse.json({ data: { workspace_id: 7 } }, { status: 201 })
    }),
  )

  renderizar()
  await userEvent.click(await screen.findByRole('button', { name: /aceitar convite/i }))

  expect(await screen.findByTestId('destino')).toHaveTextContent('home /')
  expect(chamado).toBe(true)
})

test('convite vencido mostra a mensagem do servidor', async () => {
  setToken('t')
  server.use(
    http.post('/api/v1/invitations/tok-ana:accept', () =>
      HttpResponse.json({ message: 'Convite invalido ou expirado.' }, { status: 422 }),
    ),
  )

  renderizar()
  await userEvent.click(await screen.findByRole('button', { name: /aceitar convite/i }))

  expect(await screen.findByText('Convite invalido ou expirado.')).toBeInTheDocument()
})

/** Logado com outra conta: o caminho e sair e entrar com a convidada, voltando aqui. */
test('email errado oferece trocar de conta e voltar ao convite', async () => {
  setToken('t')
  server.use(
    http.post('/api/v1/invitations/tok-ana:accept', () =>
      HttpResponse.json(
        { message: 'Este convite e para outro email. Entre com a conta convidada.' },
        { status: 403 },
      ),
    ),
  )

  renderizar()
  const user = userEvent.setup()
  await user.click(await screen.findByRole('button', { name: /aceitar convite/i }))

  expect(await screen.findByText(/para outro email/i)).toBeInTheDocument()

  await user.click(screen.getByRole('button', { name: /entrar com outra conta/i }))

  expect(await screen.findByTestId('destino')).toHaveTextContent(
    'login /login?next=%2Fconvite%2Ftok-ana',
  )
  expect(localStorage.getItem('2m.token')).toBeNull()
})
