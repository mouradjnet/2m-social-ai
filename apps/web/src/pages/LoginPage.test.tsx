import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { HttpResponse, http } from 'msw'
import { expect, test } from 'vitest'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { server } from '@/test/server'
import { LoginPage } from './LoginPage'

function Destino() {
  const { pathname } = useLocation()
  return <p data-testid="destino">{pathname}</p>
}

async function entrar(url: string) {
  server.use(http.post('/api/v1/auth/login', () => HttpResponse.json({ token: 't' })))

  render(
    <MemoryRouter initialEntries={[url]}>
      <Routes>
        <Route path="/login" element={<LoginPage />} />
        <Route path="*" element={<Destino />} />
      </Routes>
    </MemoryRouter>,
  )

  const user = userEvent.setup()
  await user.click(screen.getByRole('button', { name: /já tenho conta/i }))
  await user.type(screen.getByLabelText(/e-mail/i), 'ana@example.com')
  await user.type(screen.getByLabelText(/senha/i), 'senha-bem-longa')
  await user.click(screen.getByRole('button', { name: /^entrar$/i }))

  return screen.findByTestId('destino')
}

test('sem next, vai para os projetos', async () => {
  expect(await entrar('/login')).toHaveTextContent(/^\/$/)
})

test('com next interno, volta para onde estava (o convite)', async () => {
  expect(await entrar('/login?next=%2Fconvite%2Ftok-ana')).toHaveTextContent('/convite/tok-ana')
})

/** `//site.com` e caminho relativo ao protocolo: o navegador sairia do app. */
test('next para outro site e ignorado', async () => {
  expect(await entrar('/login?next=%2F%2Fevil.example.com')).toHaveTextContent(/^\/$/)
})

test('next absoluto e ignorado', async () => {
  expect(await entrar('/login?next=https%3A%2F%2Fevil.example.com')).toHaveTextContent(/^\/$/)
})
