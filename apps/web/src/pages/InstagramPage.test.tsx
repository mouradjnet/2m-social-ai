import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { HttpResponse, http } from 'msw'
import { expect, test, vi } from 'vitest'
import { server } from '@/test/server'
import { renderWithProviders } from '@/test/utils'
import type { ActivityEntry, InstagramAccount, Publication } from '@/lib/types'
import { navegar } from '@/lib/navegar'
import { InstagramPage } from './InstagramPage'

const ROUTE = { path: '/projects/:projectId/instagram', entry: '/projects/1/instagram' }

function conta(overrides: Partial<InstagramAccount> = {}): InstagramAccount {
  return {
    id: 5,
    project_id: 1,
    ig_user_id: '1784',
    username: '2msaudefeminina',
    account_type: 'BUSINESS',
    status: 'active',
    token_expires_at: '2026-11-20T12:00:00Z',
    expires_in_days: 50,
    last_error: null,
    connected_at: '2026-09-28T12:00:00Z',
    connector: { id: 1, name: 'Djair' },
    ...overrides,
  }
}

function publicacao(overrides: Partial<Publication>): Publication {
  return {
    id: 1,
    content_id: 10,
    caption: 'Legenda',
    image_url: 'https://x.test/a.jpg',
    account_username: '2msaudefeminina',
    approved_by: 2,
    approved_at: '2026-09-27T12:00:00Z',
    scheduled_for: '2026-09-28T11:00:00Z',
    status: 'published',
    media_id: 'midia-1',
    permalink: 'https://www.instagram.com/p/ABC/',
    published_at: '2026-09-28T11:00:05Z',
    attempts: 1,
    next_attempt_at: null,
    error_kind: null,
    last_error: null,
    content: { id: 10, title: 'Ciclo menstrual', status: 'published' },
    approver: { id: 2, name: 'Ana Revisora' },
    ...overrides,
  }
}

function servidor(account: InstagramAccount | null, publicacoes: Publication[] = [], atividade: ActivityEntry[] = []) {
  server.use(
    http.get('/api/v1/projects/1/instagram', () => HttpResponse.json({ data: account })),
    http.get('/api/v1/projects/1/publications', () => HttpResponse.json({ data: publicacoes })),
    http.get('/api/v1/projects/1/activity', () => HttpResponse.json({ data: atividade })),
  )
}

test('sem conta, conectar leva o navegador ao consentimento da Meta', async () => {
  servidor(null)
  server.use(
    http.post('/api/v1/projects/1/instagram:connect', () =>
      HttpResponse.json({ authorize_url: 'https://www.instagram.com/oauth/authorize?state=abc' }),
    ),
  )
  const para = vi.spyOn(navegar, 'para').mockImplementation(() => {})

  renderWithProviders(<InstagramPage />, ROUTE)
  await userEvent.setup().click(await screen.findByRole('button', { name: 'Conectar Instagram' }))

  await vi.waitFor(() => expect(para).toHaveBeenCalledWith('https://www.instagram.com/oauth/authorize?state=abc'))
})

test('quem nao administra ve o motivo da recusa', async () => {
  servidor(null)
  server.use(
    http.post('/api/v1/projects/1/instagram:connect', () =>
      HttpResponse.json(
        { message: 'Só quem administra o espaço de trabalho conecta ou desconecta o Instagram.' },
        { status: 403 },
      ),
    ),
  )

  renderWithProviders(<InstagramPage />, ROUTE)
  await userEvent.setup().click(await screen.findByRole('button', { name: 'Conectar Instagram' }))

  expect(await screen.findByRole('alert')).toHaveTextContent('Só quem administra')
})

test('mostra a conta conectada e o resultado que voltou do callback', async () => {
  servidor(conta())

  renderWithProviders(<InstagramPage />, {
    ...ROUTE,
    entry: '/projects/1/instagram?instagram=conectado&motivo=%402msaudefeminina',
  })

  expect(await screen.findByRole('heading', { name: '@2msaudefeminina' })).toBeInTheDocument()
  expect(screen.getByRole('status')).toHaveTextContent('Conta @2msaudefeminina conectada.')
  expect(screen.getByText(/vence em 50 dias/)).toBeInTheDocument()
})

test('desconectar pede confirmacao antes', async () => {
  let desconectou = false
  servidor(conta())
  server.use(
    http.delete('/api/v1/projects/1/instagram', () => {
      desconectou = true
      return new HttpResponse(null, { status: 204 })
    }),
  )

  renderWithProviders(<InstagramPage />, ROUTE)
  const user = userEvent.setup()

  await user.click(await screen.findByRole('button', { name: 'Desconectar' }))
  expect(desconectou).toBe(false)

  await user.click(screen.getByRole('button', { name: 'Confirmar desconexão' }))
  await vi.waitFor(() => expect(desconectou).toBe(true))
})

test('historico mostra indicadores, link do post e o erro da falha', async () => {
  servidor(conta(), [
    publicacao({ id: 2 }),
    publicacao({
      id: 3,
      content_id: 11,
      status: 'failed',
      media_id: null,
      permalink: null,
      last_error: 'Proporção não suportada.',
      content: { id: 11, title: 'Menopausa', status: 'scheduled' },
    }),
  ])

  renderWithProviders(<InstagramPage />, ROUTE)

  expect(await screen.findByLabelText('Indicadores')).toHaveTextContent('1 publicadas · 1 com falha')

  const publicado = screen.getByText('Ciclo menstrual').closest('li')!
  expect(within(publicado).getByRole('link')).toHaveAttribute('href', 'https://www.instagram.com/p/ABC/')
  expect(within(publicado).getByText(/aprovada por Ana Revisora/)).toBeInTheDocument()

  const falhou = screen.getByText('Menopausa').closest('li')!
  expect(within(falhou).getByText('Proporção não suportada.')).toBeInTheDocument()
  expect(within(falhou).getByRole('button', { name: 'Tentar de novo agora' })).toBeInTheDocument()
})

test('tentar de novo chama a rota da publicacao que falhou', async () => {
  let chamou = 0
  servidor(conta(), [publicacao({ id: 3, status: 'failed', permalink: null, last_error: 'x' })])
  server.use(
    http.post('/api/v1/publications/3/retry', () => {
      chamou++
      return HttpResponse.json({ data: publicacao({ id: 4 }) }, { status: 201 })
    }),
  )

  renderWithProviders(<InstagramPage />, ROUTE)
  await userEvent.setup().click(await screen.findByRole('button', { name: 'Tentar de novo agora' }))

  await vi.waitFor(() => expect(chamou).toBe(1))
})

test('resultado desconhecido parado pede a decisao humana, sem "tentar de novo"', async () => {
  let decisao: unknown = null
  servidor(conta(), [
    publicacao({
      id: 7,
      status: 'unknown',
      permalink: null,
      next_attempt_at: null,
      last_error: 'Não foi possível confirmar com a Meta se o post saiu. Confira o perfil e decida.',
    }),
  ])
  server.use(
    http.post('/api/v1/publications/7/resolve', async ({ request }) => {
      decisao = await request.json()
      return HttpResponse.json({ data: publicacao({ id: 7 }) })
    }),
  )

  renderWithProviders(<InstagramPage />, ROUTE)

  expect(await screen.findByText(/Confira o perfil e decida/)).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'Tentar de novo agora' })).not.toBeInTheDocument()

  await userEvent.setup().click(screen.getByRole('button', { name: 'Está no ar' }))
  await vi.waitFor(() => expect(decisao).toEqual({ outcome: 'published' }))
})

test('atividade mostra quem fez cada gesto, em portugues', async () => {
  const gesto = (id: number, action: string, meta: Record<string, string>): ActivityEntry => ({
    id,
    action,
    subject_type: 'X',
    subject_id: 1,
    meta,
    created_at: '2026-09-29T12:00:00Z',
    user: { id: 1, name: 'Djair' },
  })
  servidor(conta(), [], [
    gesto(3, 'publication.resolved', { outcome: 'published', content_title: 'Menopausa' }),
    gesto(2, 'asset.deleted', { original_name: 'capa.jpg' }),
    gesto(1, 'instagram.disconnected', { username: 'conta_de_teste' }),
  ])

  renderWithProviders(<InstagramPage />, ROUTE)

  const secao = await screen.findByRole('region', { name: 'Atividade' })
  expect(within(secao).getByText(/Djair decidiu que "Menopausa" está no ar/)).toBeInTheDocument()
  expect(within(secao).getByText(/Djair removeu a imagem capa.jpg/)).toBeInTheDocument()
  expect(within(secao).getByText(/Djair desconectou @conta_de_teste/)).toBeInTheDocument()
})
