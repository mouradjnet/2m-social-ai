import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { HttpResponse, http } from 'msw'
import { expect, test } from 'vitest'
import { server } from '@/test/server'
import { renderWithProviders } from '@/test/utils'
import type { Content } from '@/lib/types'
import { ApprovalsPage } from './ApprovalsPage'

// CP-04: a Central de Aprovação. Toda decisão vai ao servidor com a versão vista.

const ROUTE = { path: '/projects/:projectId/aprovacoes', entry: '/projects/1/aprovacoes' }

function peca(overrides: Partial<Content> = {}): Content {
  return {
    id: 10,
    project_id: 1,
    title: 'Autocuidado em 5 minutos',
    caption: 'Pequenos hábitos que cabem na rotina.',
    cta: 'Salve para depois',
    hashtags: ['#autocuidado'],
    format: 'carousel',
    channel: 'instagram',
    status: 'review',
    scheduled_for: null,
    planned_for: '2026-10-01T22:00:00Z',
    image_prompt: null,
    latest_review: {
      id: 1,
      verdict: 'pass',
      summary: 'Tom adequado.',
      violations: [],
      created_at: '2026-09-30T10:00:00Z',
    },
    latest_seo: null,
    source: 'ai',
    origin_ai_run_id: 3,
    updated_at: '2026-09-30T10:00:00Z',
    latest_text_revision: null,
    editorial_state: 'pending_approval',
    version: 3,
    structure: { visual: 'Fundo rosa claro.', slides: [{ heading: 'Capa', body: 'x' }] },
    slides: [],
    ...overrides,
  }
}

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/

function cenario(pecas: Content[], papel: 'reviewer' | 'editor' | 'owner' = 'reviewer') {
  const chamadas: { url: string; body: unknown }[] = []
  let lista = pecas

  server.use(
    http.get('/api/v1/projects/1', () =>
      HttpResponse.json({ data: { id: 1, workspace_id: 7, name: '2M Saúde Feminina' } }),
    ),
    http.get('/api/v1/projects/1/contents', () => HttpResponse.json({ data: lista })),
    http.get('/api/v1/me', () =>
      HttpResponse.json({ id: 1, name: 'Ana', email: 'a@x.test', workspaces: [{ id: 7, name: 'W', slug: 'w', role: papel }] }),
    ),
  )

  return {
    chamadas,
    trocarLista: (nova: Content[]) => {
      lista = nova
    },
  }
}

test('mostra marca, formato, estado, versão, texto, data e a revisão da IA', async () => {
  cenario([peca(), peca({ id: 11, title: 'Já publicada', status: 'published', editorial_state: 'published' })])

  renderWithProviders(<ApprovalsPage />, ROUTE)

  const card = await screen.findByLabelText('Peça Autocuidado em 5 minutos')
  expect(within(card).getByText('Carrossel')).toBeInTheDocument()
  expect(within(card).getByText('Aguardando aprovação humana')).toBeInTheDocument()
  expect(within(card).getByText('Versão 3')).toBeInTheDocument()
  expect(within(card).getByText(/2M Saúde Feminina/)).toBeInTheDocument()
  expect(within(card).getByText(/Data sugerida:/)).toBeInTheDocument()
  expect(within(card).getByText(/Tom adequado/)).toBeInTheDocument()
  expect(within(card).getByText(/a decisão é sua/)).toBeInTheDocument()
  // Só o que espera decisão aparece.
  expect(screen.queryByText('Já publicada')).not.toBeInTheDocument()
})

test('aprovar pede confirmação e manda a versão da tela com uma request_key', async () => {
  const { chamadas, trocarLista } = cenario([peca()])
  server.use(
    http.post('/api/v1/contents/10/approve', async ({ request }) => {
      chamadas.push({ url: 'approve', body: await request.json() })
      trocarLista([])
      return HttpResponse.json({
        data: peca({ status: 'approved', editorial_state: 'approved' }),
        decision: { id: 1, decision: 'approved', version: 3, user: { id: 5, name: 'Dra. Ana' } },
        replayed: false,
      })
    }),
  )
  const user = userEvent.setup()

  renderWithProviders(<ApprovalsPage />, ROUTE)
  await user.click(await screen.findByRole('button', { name: 'Aprovar versão 3' }))

  // Nada vai ao servidor antes de confirmar.
  const dialogo = screen.getByRole('dialog', { name: 'Confirmar aprovação' })
  expect(dialogo).toHaveTextContent(/vale só para esta versão/)
  expect(chamadas).toEqual([])

  await user.click(within(dialogo).getByRole('button', { name: 'Confirmar aprovação' }))

  await waitFor(() => expect(chamadas).toHaveLength(1))
  const corpo = chamadas[0].body as { expected_version: number; request_key: string }
  expect(corpo.expected_version).toBe(3)
  expect(corpo.request_key).toMatch(UUID)
  // O resultado vem do servidor, e a lista se atualiza depois da resposta.
  expect(await screen.findByRole('status')).toHaveTextContent('Versão 3 de “Autocuidado em 5 minutos” aprovada por Dra. Ana.')
  expect(await screen.findByText('Nenhuma peça aguardando decisão.')).toBeInTheDocument()
})

test('cancelar a confirmação não chama o servidor', async () => {
  const { chamadas } = cenario([peca()])
  const user = userEvent.setup()

  renderWithProviders(<ApprovalsPage />, ROUTE)
  await user.click(await screen.findByRole('button', { name: 'Aprovar versão 3' }))
  await user.click(screen.getByRole('button', { name: 'Cancelar' }))

  expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  expect(chamadas).toEqual([])
})

test('replay da mesma chave mostra o resultado original', async () => {
  cenario([peca()])
  server.use(
    http.post('/api/v1/contents/10/approve', () =>
      HttpResponse.json({
        data: peca({ status: 'approved', editorial_state: 'approved' }),
        decision: { id: 1, decision: 'approved', version: 3, user: { id: 5, name: 'Dra. Ana' } },
        replayed: true,
      }),
    ),
  )
  const user = userEvent.setup()

  renderWithProviders(<ApprovalsPage />, ROUTE)
  await user.click(await screen.findByRole('button', { name: 'Aprovar versão 3' }))
  await user.click(screen.getByRole('button', { name: 'Confirmar aprovação' }))

  expect(await screen.findByRole('status')).toHaveTextContent(/já estava aprovada por Dra\. Ana.*nada novo foi gravado/)
})

test('quem não revisa vê a peça mas não os botões de decisão', async () => {
  cenario([peca()], 'editor')

  renderWithProviders(<ApprovalsPage />, ROUTE)

  expect(await screen.findByText(/Aguardando um revisor/)).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: /Aprovar versão/ })).not.toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'Rejeitar' })).not.toBeInTheDocument()
  expect(screen.getByRole('link', { name: 'Editar' })).toBeInTheDocument()
})

test('fora de pending_approval o botão Aprovar fica desabilitado e diz por quê', async () => {
  cenario([peca({ editorial_state: 'in_review', latest_review: null })])

  renderWithProviders(<ApprovalsPage />, ROUTE)

  expect(await screen.findByRole('button', { name: 'Aprovar versão 3' })).toBeDisabled()
  expect(screen.getByText(/a IA precisa revisar e aprovar esta versão/)).toBeInTheDocument()
})

test('o carrossel mostra todos os slides, na ordem', async () => {
  const slide = (id: number) => ({ id, url: `https://x.test/s${id}.jpg` }) as NonNullable<Content['slides']>[number]
  cenario([peca({ slides: [slide(21), slide(22), slide(23)] })])

  renderWithProviders(<ApprovalsPage />, ROUTE)

  const lista = await screen.findByLabelText('Slides do carrossel')
  const imagens = within(lista).getAllByRole('img')
  expect(imagens.map((i) => i.getAttribute('alt'))).toEqual(['Slide 1', 'Slide 2', 'Slide 3'])
  expect(imagens[2]).toHaveAttribute('src', 'https://x.test/s23.jpg')
})

test('versão desatualizada: o servidor recusa (409) e a tela avisa e recarrega a versão atual', async () => {
  const { trocarLista } = cenario([peca()])
  server.use(
    http.post('/api/v1/contents/10/approve', () => {
      trocarLista([peca({ version: 4 })])
      return HttpResponse.json({ message: 'A peça mudou (versão 4) desde que você a abriu (versão 3).', version: 4 }, { status: 409 })
    }),
  )

  const user = userEvent.setup()

  renderWithProviders(<ApprovalsPage />, ROUTE)
  await user.click(await screen.findByRole('button', { name: 'Aprovar versão 3' }))
  await user.click(screen.getByRole('button', { name: 'Confirmar aprovação' }))

  expect(await screen.findByRole('alert')).toHaveTextContent(/A peça mudou \(versão 4\).*confira antes de decidir/)
  expect(await screen.findByRole('button', { name: 'Aprovar versão 4' })).toBeInTheDocument()
})

test('rejeitar e pedir ajustes exigem motivo e o mandam junto com a versão', async () => {
  const { chamadas } = cenario([peca()])
  server.use(
    http.post('/api/v1/contents/10/reject', async ({ request }) => {
      chamadas.push({ url: 'reject', body: await request.json() })
      return HttpResponse.json({ data: peca({ status: 'archived', editorial_state: 'rejected' }) })
    }),
    http.post('/api/v1/contents/10/request-changes', async ({ request }) => {
      chamadas.push({ url: 'request-changes', body: await request.json() })
      return HttpResponse.json({ data: peca({ status: 'production', editorial_state: 'needs_revision' }) })
    }),
  )
  const user = userEvent.setup()

  renderWithProviders(<ApprovalsPage />, ROUTE)

  await user.click(await screen.findByRole('button', { name: 'Rejeitar' }))
  const confirmar = screen.getByRole('button', { name: 'Confirmar rejeição' })
  expect(confirmar).toBeDisabled()
  await user.type(screen.getByRole('textbox', { name: 'Motivo da rejeição' }), 'Promete resultado.')
  await user.click(confirmar)
  await waitFor(() => expect(chamadas[0]).toEqual({ url: 'reject', body: { version: 3, reason: 'Promete resultado.' } }))

  await user.click(await screen.findByRole('button', { name: 'Solicitar ajustes' }))
  await user.type(screen.getByRole('textbox', { name: 'O que precisa mudar' }), 'Troque o CTA.')
  await user.click(screen.getByRole('button', { name: 'Enviar pedido de ajuste' }))
  await waitFor(() =>
    expect(chamadas[1]).toEqual({ url: 'request-changes', body: { version: 3, reason: 'Troque o CTA.' } }),
  )
})

test('o histórico mostra quem decidiu, a versão e o motivo', async () => {
  cenario([peca()])
  server.use(
    http.get('/api/v1/contents/10/history', () =>
      HttpResponse.json({
        data: {
          content_id: 10,
          project_id: 1,
          version: 3,
          approval_valid: false,
          decisions: [
            {
              id: 1, decision: 'changes_requested', version: 2, reason: 'CTA fraco.', from_status: 'review',
              to_status: 'production', snapshot_hash: null, user: { id: 5, name: 'Dra. Ana' }, at: '2026-09-30T12:00:00Z',
            },
          ],
          revisions: [{ type: 'change', from_status: null, to_status: null, fields: ['cta'], user_id: 4, at: '2026-09-30T13:00:00Z' }],
        },
      }),
    ),
  )

  renderWithProviders(<ApprovalsPage />, ROUTE)
  await userEvent.setup().click(await screen.findByRole('button', { name: 'Histórico' }))

  const historico = await screen.findByLabelText('Histórico da peça')
  expect(within(historico).getByText(/sem aprovação válida para esta versão/)).toBeInTheDocument()
  expect(within(historico).getByText(/Dra\. Ana · Pediu ajustes a versão 2/)).toBeInTheDocument()
  expect(within(historico).getByText(/CTA fraco/)).toBeInTheDocument()
  expect(within(historico).getByText(/alterou cta/)).toBeInTheDocument()
})

test('peça reprovada pela IA oferece nova geração; aprovada pela IA não', async () => {
  cenario([
    peca(),
    peca({
      id: 12,
      title: 'Reprovada',
      editorial_state: 'needs_revision',
      latest_review: {
        id: 2, verdict: 'fail', summary: 'Promessa.',
        violations: [{ rule: 'Promessa', excerpt: 'cura', suggestion: 'remova' }], created_at: '2026-09-30T10:00:00Z',
      },
    }),
  ])

  renderWithProviders(<ApprovalsPage />, ROUTE)

  const reprovada = await screen.findByLabelText('Peça Reprovada')
  expect(within(reprovada).getByRole('button', { name: /Nova geração/ })).toBeInTheDocument()
  expect(within(reprovada).getByRole('link', { name: 'Editar' })).toHaveAttribute('href', '/projects/1/content?editar=12')

  const aprovadaIa = screen.getByLabelText('Peça Autocuidado em 5 minutos')
  expect(within(aprovadaIa).queryByRole('button', { name: /Nova geração/ })).not.toBeInTheDocument()
})
