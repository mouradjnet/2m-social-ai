import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { HttpResponse, http } from 'msw'
import { expect, test } from 'vitest'
import { server } from '@/test/server'
import { renderWithProviders } from '@/test/utils'
import type { BrandProfileCompletion, BrandProfileResponse } from '@/lib/types'
import { BrandProfilePage } from './BrandProfilePage'

const ROUTE = { path: '/projects/:projectId/brand-profile', entry: '/projects/1/brand-profile' }

function completion(percent: number): BrandProfileCompletion {
  return {
    percent,
    steps: [
      { id: 'identity', complete: true, required: true },
      { id: 'audience', complete: false, required: true },
      { id: 'positioning', complete: false, required: true },
      { id: 'offer', complete: false, required: true },
      { id: 'vocabulary', complete: false, required: false },
      { id: 'social', complete: false, required: false },
    ],
  }
}

function profile(overrides: Partial<BrandProfileResponse['data']> = {}): BrandProfileResponse {
  return {
    data: {
      id: 1,
      project_id: 1,
      brand_name: '2F AutoShop',
      description: null,
      audience: null,
      persona: null,
      tone_of_voice: null,
      differentiators: null,
      products: ['Carros', 'Motos'],
      services: null,
      competitors: null,
      required_words: null,
      forbidden_words: null,
      website: null,
      instagram: null,
      linkedin: null,
      ...overrides,
    } as BrandProfileResponse['data'],
    completion: completion(25),
  }
}

test('o percent vem do servidor e aparece no cabeçalho', async () => {
  server.use(http.get('/api/v1/projects/1/brand-profile', () => HttpResponse.json(profile())))

  renderWithProviders(<BrandProfilePage />, ROUTE)

  expect(await screen.findByText(/25% completo/i)).toBeInTheDocument()
})

test('o passo Oferta renderiza um array como linhas no textarea', async () => {
  const user = userEvent.setup()
  server.use(http.get('/api/v1/projects/1/brand-profile', () => HttpResponse.json(profile())))

  renderWithProviders(<BrandProfilePage />, ROUTE)

  await user.click(await screen.findByRole('button', { name: /oferta/i }))

  const produtos = screen.getByRole('textbox', { name: /produtos/i })
  expect(produtos).toHaveValue('Carros\nMotos')
})

test('ao salvar o passo Oferta, o campo de lista vai como array', async () => {
  const user = userEvent.setup()
  let recebido: unknown = null

  server.use(
    http.get('/api/v1/projects/1/brand-profile', () => HttpResponse.json(profile())),
    http.patch('/api/v1/projects/1/brand-profile', async ({ request }) => {
      recebido = await request.json()
      return HttpResponse.json(profile())
    }),
  )

  renderWithProviders(<BrandProfilePage />, ROUTE)

  await user.click(await screen.findByRole('button', { name: /oferta/i }))

  const servicos = screen.getByRole('textbox', { name: /serviços/i })
  await user.clear(servicos)
  await user.type(servicos, 'Compra Segura\nFinanciamento')

  await user.click(screen.getByRole('button', { name: /salvar|próximo/i }))

  await waitFor(() => expect(recebido).not.toBeNull())
  expect(recebido).toMatchObject({
    products: ['Carros', 'Motos'],
    services: ['Compra Segura', 'Financiamento'],
  })
})

test('o Stepper marca os passos opcionais', async () => {
  server.use(http.get('/api/v1/projects/1/brand-profile', () => HttpResponse.json(profile())))

  renderWithProviders(<BrandProfilePage />, ROUTE)

  const nav = await screen.findByRole('navigation', { name: /progresso/i })
  expect(within(nav).getByText(/vocabulário e concorrência/i)).toBeInTheDocument()
  const opcionais = within(nav).getAllByText(/opcional/i)
  expect(opcionais).toHaveLength(2)
})

test('os dois passos opcionais têm dicas diferentes', async () => {
  const user = userEvent.setup()
  server.use(http.get('/api/v1/projects/1/brand-profile', () => HttpResponse.json(profile())))

  renderWithProviders(<BrandProfilePage />, ROUTE)

  // Navegar pelo Stepper (nav), nao pelo botao "Próximo" do formulario — que
  // tambem carrega o titulo do proximo passo e casaria com o mesmo nome.
  const nav = await screen.findByRole('navigation', { name: /progresso/i })

  await user.click(within(nav).getByRole('button', { name: /vocabulário e concorrência/i }))
  expect(screen.getByText(/influencia o texto gerado/i)).toBeInTheDocument()

  await user.click(within(nav).getByRole('button', { name: /links sociais/i }))
  expect(screen.getByText(/não afeta a estratégia/i)).toBeInTheDocument()
})

function projeto(timezone: string) {
  return { data: { id: 1, name: '2F AutoShop', timezone } }
}

test('o fuso do projeto aparece selecionado', async () => {
  server.use(
    http.get('/api/v1/projects/1/brand-profile', () => HttpResponse.json(profile())),
    http.get('/api/v1/projects/1', () => HttpResponse.json(projeto('America/Manaus'))),
  )

  renderWithProviders(<BrandProfilePage />, ROUTE)

  const select = await screen.findByRole('combobox', { name: /fuso em que esta marca publica/i })
  expect(select).toHaveValue('America/Manaus')
})

test('trocar o fuso manda o PATCH e confirma na tela', async () => {
  const user = userEvent.setup()
  let recebido: unknown = null

  server.use(
    http.get('/api/v1/projects/1/brand-profile', () => HttpResponse.json(profile())),
    http.get('/api/v1/projects/1', () => HttpResponse.json(projeto('America/Sao_Paulo'))),
    http.patch('/api/v1/projects/1', async ({ request }) => {
      recebido = await request.json()
      return HttpResponse.json(projeto('Europe/Lisbon'))
    }),
  )

  renderWithProviders(<BrandProfilePage />, ROUTE)

  const select = await screen.findByRole('combobox', { name: /fuso em que esta marca publica/i })
  await user.selectOptions(select, 'Europe/Lisbon')

  await waitFor(() => expect(recebido).toEqual({ timezone: 'Europe/Lisbon' }))

  // A tela reflete o que o SERVIDOR devolveu, nao o que foi clicado.
  await waitFor(() => expect(select).toHaveValue('Europe/Lisbon'))
  expect(await screen.findByText(/fuso atualizado/i)).toBeInTheDocument()
})

/**
 * Um fuso que a API tem e a lista curada nao: precisa aparecer, senao a tela
 * silenciosamente PERDERIA o valor que alguem escolheu por fora.
 */
test('fuso fora da lista curada continua visivel', async () => {
  server.use(
    http.get('/api/v1/projects/1/brand-profile', () => HttpResponse.json(profile())),
    http.get('/api/v1/projects/1', () => HttpResponse.json(projeto('Asia/Tokyo'))),
  )

  renderWithProviders(<BrandProfilePage />, ROUTE)

  const select = await screen.findByRole('combobox', { name: /fuso em que esta marca publica/i })
  expect(select).toHaveValue('Asia/Tokyo')
})
