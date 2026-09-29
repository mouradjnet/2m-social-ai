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

test('ao salvar o passo Vocabulário, concorrente vai como {name, url} e as palavras como array', async () => {
  const user = userEvent.setup()
  let recebido: unknown = null

  server.use(
    http.get('/api/v1/projects/1/brand-profile', () =>
      HttpResponse.json(profile({ competitors: [{ name: 'Rival', url: 'https://rival.com' }] })),
    ),
    http.patch('/api/v1/projects/1/brand-profile', async ({ request }) => {
      recebido = await request.json()
      return HttpResponse.json(profile())
    }),
  )

  renderWithProviders(<BrandProfilePage />, ROUTE)

  await user.click(await screen.findByRole('button', { name: /vocabulário/i }))

  const concorrentes = screen.getByRole('textbox', { name: /concorrentes/i })
  // O que veio do servidor aparece legivel, nao "[object Object]".
  expect(concorrentes).toHaveValue('Rival https://rival.com')
  await user.type(concorrentes, '{Enter}Outra Marca')
  await user.type(screen.getByRole('textbox', { name: /proibidas/i }), 'milagre')

  await user.click(screen.getByRole('button', { name: /salvar|próximo/i }))

  await waitFor(() => expect(recebido).not.toBeNull())
  expect(recebido).toEqual({
    competitors: [
      { name: 'Rival', url: 'https://rival.com' },
      { name: 'Outra Marca', url: null },
    ],
    required_words: [],
    forbidden_words: ['milagre'],
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

// --- Perda silenciosa de dados (C1) ---------------------------------------------

function comPatch(resposta: () => Response) {
  let chamadas = 0
  server.use(
    http.get('/api/v1/projects/1/brand-profile', () => HttpResponse.json(profile())),
    http.get('/api/v1/projects/1', () => HttpResponse.json(projeto('America/Sao_Paulo'))),
    http.patch('/api/v1/projects/1/brand-profile', () => {
      chamadas++
      return resposta()
    }),
  )
  return () => chamadas
}

test('Pular com alteração não salva avisa e não descarta', async () => {
  const user = userEvent.setup()
  const chamadas = comPatch(() => HttpResponse.json(profile()))

  renderWithProviders(<BrandProfilePage />, ROUTE)

  const nome = await screen.findByRole('textbox', { name: /nome da marca/i })
  await user.clear(nome)
  await user.type(nome, '2M Saúde Feminina')
  await user.click(screen.getByRole('button', { name: /^pular$/i }))

  expect(screen.getByRole('alert')).toHaveTextContent(/alterações não salvas/i)
  // Continua no passo 1, com o texto digitado.
  expect(screen.getByRole('textbox', { name: /nome da marca/i })).toHaveValue('2M Saúde Feminina')
  expect(chamadas()).toBe(0)
})

test('descartar e pular avança sem salvar; salvar e continuar salva', async () => {
  const user = userEvent.setup()
  const chamadas = comPatch(() => HttpResponse.json(profile()))

  renderWithProviders(<BrandProfilePage />, ROUTE)

  await user.type(await screen.findByRole('textbox', { name: /nome da marca/i }), 'X')
  await user.click(screen.getByRole('button', { name: /^pular$/i }))
  await user.click(screen.getByRole('button', { name: /descartar e pular/i }))
  expect(screen.getByRole('textbox', { name: /^público/i })).toBeInTheDocument()
  expect(chamadas()).toBe(0)

  await user.type(screen.getByRole('textbox', { name: /^público/i }), 'Mulheres')
  await user.click(screen.getByRole('button', { name: /^pular$/i }))
  await user.click(screen.getByRole('button', { name: /salvar e continuar/i }))
  await waitFor(() => expect(chamadas()).toBe(1))
})

test('Pular sem alteração avança direto', async () => {
  const user = userEvent.setup()
  comPatch(() => HttpResponse.json(profile()))

  renderWithProviders(<BrandProfilePage />, ROUTE)

  await user.click(await screen.findByRole('button', { name: /^pular$/i }))
  expect(screen.getByRole('textbox', { name: /^público/i })).toBeInTheDocument()
  expect(screen.queryByRole('alert')).not.toBeInTheDocument()
})

test('trocar de passo pelo Stepper com alteração não salva também avisa', async () => {
  const user = userEvent.setup()
  comPatch(() => HttpResponse.json(profile()))

  renderWithProviders(<BrandProfilePage />, ROUTE)

  const nome = await screen.findByRole('textbox', { name: /nome da marca/i })
  await user.clear(nome)
  await user.type(nome, 'X')
  const nav = screen.getByRole('navigation', { name: /progresso/i })
  await user.click(within(nav).getByRole('button', { name: /links sociais/i }))

  expect(screen.getByRole('alert')).toHaveTextContent(/alterações não salvas/i)
  expect(screen.getByRole('textbox', { name: /nome da marca/i })).toHaveValue('X')
})

test('cor sem # é recusada na tela, com o formato certo, sem chamar a API', async () => {
  const user = userEvent.setup()
  const chamadas = comPatch(() => HttpResponse.json(profile()))

  renderWithProviders(<BrandProfilePage />, ROUTE)

  await user.type(await screen.findByRole('textbox', { name: /cores da marca/i }), 'b23a6f')
  await user.click(screen.getByRole('button', { name: /próximo/i }))

  expect(screen.getByRole('textbox', { name: /cores da marca/i })).toHaveAccessibleDescription(
    /hexadecimal com #/i,
  )
  expect(chamadas()).toBe(0)
})

test('Instagram como @usuario é recusado na tela com o formato certo', async () => {
  const user = userEvent.setup()
  const chamadas = comPatch(() => HttpResponse.json(profile()))

  renderWithProviders(<BrandProfilePage />, ROUTE)

  const nav = await screen.findByRole('navigation', { name: /progresso/i })
  await user.click(within(nav).getByRole('button', { name: /links sociais/i }))
  await user.type(screen.getByRole('textbox', { name: /instagram/i }), '@2msaudefeminina')
  await user.click(screen.getByRole('button', { name: /^salvar$/i }))

  expect(screen.getByRole('textbox', { name: /instagram/i })).toHaveAccessibleDescription(
    /começando com https/i,
  )
  expect(chamadas()).toBe(0)
})

test('erro da API num item de lista aparece no campo, e a mensagem geral também', async () => {
  const user = userEvent.setup()
  const msg = 'O campo palavra proibida não pode ter mais de 60 caracteres.'
  comPatch(() =>
    HttpResponse.json({ message: msg, errors: { 'forbidden_words.0': [msg] } }, { status: 422 }),
  )

  renderWithProviders(<BrandProfilePage />, ROUTE)

  const nav = await screen.findByRole('navigation', { name: /progresso/i })
  await user.click(within(nav).getByRole('button', { name: /vocabulário/i }))
  await user.type(screen.getByRole('textbox', { name: /proibidas/i }), 'x')
  await user.click(screen.getByRole('button', { name: /próximo/i }))

  expect(await screen.findByRole('alert')).toHaveTextContent(/não foi salvo/i)
  expect(screen.getByRole('textbox', { name: /proibidas/i })).toHaveAccessibleDescription(
    /mais de 60 caracteres/i,
  )
  // Nao avancou: o texto digitado continua la.
  expect(screen.getByRole('textbox', { name: /proibidas/i })).toHaveValue('x')
})

test('falha ao salvar o fuso é avisada, não fica em silêncio', async () => {
  const user = userEvent.setup()
  server.use(
    http.get('/api/v1/projects/1/brand-profile', () => HttpResponse.json(profile())),
    http.get('/api/v1/projects/1', () => HttpResponse.json(projeto('America/Sao_Paulo'))),
    http.patch('/api/v1/projects/1', () =>
      HttpResponse.json({ message: 'Fuso inválido.' }, { status: 422 }),
    ),
  )

  renderWithProviders(<BrandProfilePage />, ROUTE)

  const select = await screen.findByRole('combobox', { name: /fuso em que esta marca publica/i })
  await user.selectOptions(select, 'Europe/Lisbon')

  expect(await screen.findByText(/não foi possível salvar o fuso/i)).toBeInTheDocument()
})
