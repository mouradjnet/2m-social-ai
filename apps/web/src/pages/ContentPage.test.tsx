import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { HttpResponse, http } from 'msw'
import { afterEach, beforeEach, expect, test, vi } from 'vitest'
import { server } from '@/test/server'
import { renderWithProviders } from '@/test/utils'
import type { AiRun, Content } from '@/lib/types'
import { ContentPage } from './ContentPage'

const ROUTE = { path: '/projects/:projectId/content', entry: '/projects/1/content' }

function content(id: number, status: Content['status'], scheduledFor: string | null = null): Content {
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
    scheduled_for: scheduledFor,
    image_prompt: null,
    latest_review: null,
    latest_seo: null,
    source: 'ai',
    origin_ai_run_id: 7,
    updated_at: '2026-07-13T09:00:00Z',
    latest_text_revision: null,
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

  // O texto nomeia o AGENTE do run, nao a tela: esta pagina dispara cinco
  // agentes pelo mesmo hook, e ja disse "Gerando sua estrategia" para todos.
  // A assercao e no role=status, nao na pagina: o link "← Estrategia" e do menu.
  // O role=status aparece JA no estado "starting" (o run ainda nao existe), com a
  // frase generica. A frase do agente so chega quando o GET /ai-runs/42 resolve —
  // afirmar o texto no instante em que o elemento aparece e uma corrida, e ela
  // perdia ~1 vez em 5. Espera-se o ESTADO, nao o instante.
  const status = await screen.findByRole('status')
  await waitFor(() => expect(status).toHaveTextContent(/escrevendo suas peças/i))
  expect(status).not.toHaveTextContent(/estratégia/i)
})

test('escolher um pilar manda o pilar no corpo do POST', async () => {
  const user = setup()
  let recebido: unknown = null

  server.use(
    http.get('/api/v1/projects/1/contents', () => HttpResponse.json({ data: [] })),
    http.get('/api/v1/projects/1/strategies', () =>
      HttpResponse.json({
        data: [
          {
            id: 1,
            status: 'active',
            title: 'E',
            summary: null,
            editorial_line: null,
            ai_run_id: null,
            workspace_id: 1,
            project_id: 1,
            pillars: [
              { name: 'Educação', weight: 60, description: 'd' },
              { name: 'Por dentro do pátio', weight: 15, description: 'd' },
            ],
          },
        ],
      }),
    ),
    http.post('/api/v1/projects/1/copy:generate', async ({ request }) => {
      recebido = await request.json()
      return HttpResponse.json({ ai_run_id: 42 }, { status: 202 })
    }),
    http.get('/api/v1/ai-runs/42', () => HttpResponse.json(run({ status: 'running' }))),
  )

  renderWithProviders(<ContentPage />, ROUTE)

  // O pilar leve e o que a distribuicao por peso nunca sorteia — e o motivo do seletor.
  await user.selectOptions(
    await screen.findByRole('combobox', { name: /pilar do lote/i }),
    'Por dentro do pátio',
  )
  await user.click(screen.getByRole('button', { name: /gerar conteúdo/i }))

  await waitFor(() => expect(recebido).toEqual({ pillar: 'Por dentro do pátio' }))
})

test('sem pilar escolhido, o POST vai sem corpo: o servidor distribui pelos pesos', async () => {
  const user = setup()
  let recebido: string | null = null

  server.use(
    http.get('/api/v1/projects/1/contents', () => HttpResponse.json({ data: [] })),
    http.post('/api/v1/projects/1/copy:generate', async ({ request }) => {
      recebido = await request.text()
      return HttpResponse.json({ ai_run_id: 42 }, { status: 202 })
    }),
    http.get('/api/v1/ai-runs/42', () => HttpResponse.json(run({ status: 'running' }))),
  )

  renderWithProviders(<ContentPage />, ROUTE)

  await user.click(await screen.findByRole('button', { name: /gerar conteúdo/i }))

  await waitFor(() => expect(recebido).toBe(''))
})

test('revisar: o estado de geração fala do reviewer, não do copywriter', async () => {
  const user = setup()

  server.use(
    http.get('/api/v1/projects/1/contents', () =>
      HttpResponse.json({ data: [content(1, 'review')] }),
    ),
    http.post('/api/v1/projects/1/review:generate', () =>
      HttpResponse.json({ ai_run_id: 42 }, { status: 202 }),
    ),
    http.get('/api/v1/ai-runs/42', () =>
      HttpResponse.json(run({ status: 'running', agent: 'reviewer' })),
    ),
  )

  renderWithProviders(<ContentPage />, ROUTE)

  await user.click(await screen.findByRole('button', { name: /revisar/i }))

  expect(await screen.findByText(/revisando suas peças/i)).toBeInTheDocument()
  expect(screen.queryByText(/escrevendo suas peças/i)).not.toBeInTheDocument()
})

test('agendar: manda a janela para schedule:generate, não para copy:generate', async () => {
  const user = setup()
  let recebido: unknown = null

  server.use(
    http.get('/api/v1/projects/1/contents', () =>
      HttpResponse.json({ data: [content(1, 'approved')] }),
    ),
    http.post('/api/v1/projects/1/schedule:generate', async ({ request }) => {
      recebido = await request.json()
      return HttpResponse.json({ ai_run_id: 42 }, { status: 202 })
    }),
    http.get('/api/v1/ai-runs/42', () => HttpResponse.json(run({ status: 'running' }))),
    // Sem handler de copy:generate: se a tela chamar o endpoint errado, o MSW estoura.
  )

  renderWithProviders(<ContentPage />, ROUTE)

  await user.click(await screen.findByRole('button', { name: /agendar aprovadas/i }))

  await waitFor(() => expect(recebido).toMatchObject({ days: 14 }))
  expect((recebido as { starts_on: string }).starts_on).toMatch(/^\d{4}-\d{2}-\d{2}$/)
})

test('gerar imagens: manda POST para design:generate', async () => {
  const user = setup()
  let chamado = false

  server.use(
    http.get('/api/v1/projects/1/contents', () =>
      HttpResponse.json({ data: [content(1, 'production')] }),
    ),
    http.post('/api/v1/projects/1/design:generate', () => {
      chamado = true
      return HttpResponse.json({ ai_run_id: 42 }, { status: 202 })
    }),
    http.get('/api/v1/ai-runs/42', () => HttpResponse.json(run({ status: 'running' }))),
    // Sem handler dos outros endpoints: se a tela chamar o errado, o MSW estoura.
  )

  renderWithProviders(<ContentPage />, ROUTE)

  await user.click(await screen.findByRole('button', { name: /gerar imagens \(1\)/i }))

  await waitFor(() => expect(chamado).toBe(true))
})

test('sem peça em produção o botão de gerar imagens fica desabilitado', async () => {
  server.use(
    http.get('/api/v1/projects/1/contents', () => HttpResponse.json({ data: [content(1, 'idea')] })),
  )

  renderWithProviders(<ContentPage />, ROUTE)

  expect(await screen.findByRole('button', { name: /gerar imagens \(0\)/i })).toBeDisabled()
})

test('otimizar SEO: manda POST para seo:generate', async () => {
  const user = setup()
  let chamado = false

  server.use(
    http.get('/api/v1/projects/1/contents', () =>
      HttpResponse.json({ data: [content(1, 'production')] }),
    ),
    http.post('/api/v1/projects/1/seo:generate', () => {
      chamado = true
      return HttpResponse.json({ ai_run_id: 42 }, { status: 202 })
    }),
    http.get('/api/v1/ai-runs/42', () => HttpResponse.json(run({ status: 'running' }))),
    // Sem handler dos outros endpoints: se a tela chamar o errado, o MSW estoura.
  )

  renderWithProviders(<ContentPage />, ROUTE)

  await user.click(await screen.findByRole('button', { name: /otimizar seo \(1\)/i }))

  await waitFor(() => expect(chamado).toBe(true))
})

test('aplicar SEO: manda POST para seo:apply da peça', async () => {
  const user = setup()
  let chamado = false

  const comSeo = {
    ...content(1, 'production'),
    latest_seo: {
      id: 1,
      title: 'Titulo otimizado',
      keywords: ['busca'],
      hashtags: ['#tag'],
      applied_at: null,
      created_at: new Date().toISOString(),
    },
  }

  server.use(
    http.get('/api/v1/projects/1/contents', () => HttpResponse.json({ data: [comSeo] })),
    http.post('/api/v1/contents/1/seo:apply', () => {
      chamado = true
      return HttpResponse.json({ data: comSeo })
    }),
  )

  renderWithProviders(<ContentPage />, ROUTE)

  await user.click(await screen.findByRole('button', { name: /aplicar seo/i }))

  await waitFor(() => expect(chamado).toBe(true))
})

test('revisar: manda POST para review:generate', async () => {
  const user = setup()
  let chamado = false

  server.use(
    http.get('/api/v1/projects/1/contents', () =>
      HttpResponse.json({ data: [content(1, 'review')] }),
    ),
    http.post('/api/v1/projects/1/review:generate', () => {
      chamado = true
      return HttpResponse.json({ ai_run_id: 42 }, { status: 202 })
    }),
    http.get('/api/v1/ai-runs/42', () => HttpResponse.json(run({ status: 'running' }))),
    // Sem handler dos outros endpoints: se a tela chamar o errado, o MSW estoura.
  )

  renderWithProviders(<ContentPage />, ROUTE)

  await user.click(await screen.findByRole('button', { name: /revisar \(1\)/i }))

  await waitFor(() => expect(chamado).toBe(true))
})

test('sem peça em revisão o botão de revisar fica desabilitado', async () => {
  server.use(
    http.get('/api/v1/projects/1/contents', () => HttpResponse.json({ data: [content(1, 'idea')] })),
  )

  renderWithProviders(<ContentPage />, ROUTE)

  expect(await screen.findByRole('button', { name: /revisar \(0\)/i })).toBeDisabled()
})

test('sem peça aprovada o botão de agendar fica desabilitado', async () => {
  server.use(
    http.get('/api/v1/projects/1/contents', () => HttpResponse.json({ data: [content(1, 'idea')] })),
  )

  renderWithProviders(<ContentPage />, ROUTE)

  expect(await screen.findByRole('button', { name: /agendar aprovadas \(0\)/i })).toBeDisabled()
})

test('a peça agendada cai na coluna Agendado e mostra a data', async () => {
  server.use(
    http.get('/api/v1/projects/1/contents', () =>
      HttpResponse.json({ data: [content(1, 'scheduled', '2026-08-03T10:00:00-03:00')] }),
    ),
  )

  renderWithProviders(<ContentPage />, ROUTE)

  expect(await screen.findByText('Agendado (1)')).toBeInTheDocument()
  expect(screen.getByText(/03\/08 às 10:00/)).toBeInTheDocument()
})

test('desagendar uma peça faz PATCH para approved', async () => {
  const user = setup()
  let recebido: unknown = null

  server.use(
    http.get('/api/v1/projects/1/contents', () =>
      HttpResponse.json({ data: [content(1, 'scheduled', '2026-08-03T10:00:00-03:00')] }),
    ),
    http.patch('/api/v1/contents/1', async ({ request }) => {
      recebido = await request.json()
      return HttpResponse.json({ data: content(1, 'approved') })
    }),
  )

  renderWithProviders(<ContentPage />, ROUTE)

  await user.click(await screen.findByRole('button', { name: /voltar/i }))

  await waitFor(() => expect(recebido).toEqual({ status: 'approved' }))
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

test('reescrever manda o POST na PECA reprovada, nao no projeto', async () => {
  const user = setup()
  let chamada: string | null = null

  const reprovada: Content = {
    ...content(9, 'review'),
    latest_review: {
      id: 1,
      verdict: 'fail',
      summary: 'Depoimento fabricado.',
      violations: [
        { rule: 'Depoimento fabricado', excerpt: 'O Ricardo', suggestion: 'Use um caso real.' },
      ],
      created_at: '2026-07-13T10:00:00Z',
    },
  }

  server.use(
    http.get('/api/v1/projects/1/contents', () => HttpResponse.json({ data: [reprovada] })),
    http.post('/api/v1/contents/9/rewrite:generate', ({ request }) => {
      chamada = new URL(request.url).pathname
      return HttpResponse.json({ ai_run_id: 51 }, { status: 202 })
    }),
    http.get('/api/v1/ai-runs/51', () => HttpResponse.json(run({ status: 'running', agent: 'rewriter' }))),
  )

  renderWithProviders(<ContentPage />, ROUTE)

  await user.click(await screen.findByRole('button', { name: /reescrever com ia/i }))

  // O MSW so responde ao caminho declarado: se o POST fosse no projeto, nao haveria
  // handler e a requisicao estouraria.
  await waitFor(() => expect(chamada).toBe('/api/v1/contents/9/rewrite:generate'))

  const status = await screen.findByRole('status')
  await waitFor(() => expect(status).toHaveTextContent(/reescrevendo a peça reprovada/i))
})

test('exportar: sem peca pronta o botao fica desabilitado', async () => {
  server.use(
    http.get('/api/v1/projects/1/contents', () =>
      HttpResponse.json({ data: [content(1, 'idea'), content(2, 'review')] }),
    ),
  )

  renderWithProviders(<ContentPage />, ROUTE)

  expect(await screen.findByRole('button', { name: /exportar \(0\)/i })).toBeDisabled()
})

/**
 * O download NAO pode ser um <a href>: a rota exige o token no header, e link de
 * navegador nao manda header. Busca-se o blob e simula-se o clique.
 */
test('exportar baixa o zip com o nome que o servidor mandou', async () => {
  const user = setup()

  server.use(
    http.get('/api/v1/projects/1/contents', () =>
      HttpResponse.json({ data: [content(1, 'approved'), content(2, 'scheduled')] }),
    ),
    http.get('/api/v1/projects/1/export', () =>
      HttpResponse.arrayBuffer(new TextEncoder().encode('PK-zip-falso').buffer, {
        headers: {
          'Content-Type': 'application/zip',
          'Content-Disposition': 'attachment; filename="2f-autoshop-conteudo-2026-07-13.zip"',
        },
      }),
    ),
  )

  const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})

  // So os dois metodos: trocar o objeto URL inteiro quebra o `new URL(...)` que o MSW
  // usa por dentro — e a requisicao nunca chega ao handler.
  URL.createObjectURL = vi.fn(() => 'blob:zip')
  URL.revokeObjectURL = vi.fn()

  renderWithProviders(<ContentPage />, ROUTE)

  // As duas peças prontas (aprovada + agendada) sao exatamente o que o zip leva.
  await user.click(await screen.findByRole('button', { name: /exportar \(2\)/i }))

  await waitFor(() => expect(click).toHaveBeenCalled())

  const link = click.mock.instances[0] as HTMLAnchorElement
  expect(link.download).toBe('2f-autoshop-conteudo-2026-07-13.zip')

  click.mockRestore()
})
