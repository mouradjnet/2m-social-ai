import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { HttpResponse, http } from 'msw'
import { expect, test, vi } from 'vitest'
import { server } from '@/test/server'
import { renderWithProviders } from '@/test/utils'
import type { Asset, Content } from '@/lib/types'
import { PublicationEditor } from './PublicationEditor'

const ROUTE = { path: '/projects/:projectId/content', entry: '/projects/1/content' }

const foto: Asset = {
  id: 9,
  project_id: 1,
  original_name: 'foto.jpg',
  mime: 'image/jpeg',
  size_bytes: 1000,
  width: 1080,
  height: 1080,
  url: 'https://x.test/storage/media/foto.jpg',
  created_at: '2026-09-28T12:00:00Z',
}

function peca(overrides: Partial<Content> = {}): Content {
  return {
    id: 1,
    project_id: 1,
    title: 'Ciclo',
    caption: 'Legenda',
    cta: 'Agende',
    hashtags: ['#saude'],
    format: 'post',
    channel: 'instagram',
    status: 'review',
    scheduled_for: null,
    image_prompt: null,
    latest_review: null,
    latest_seo: null,
    source: 'ai',
    origin_ai_run_id: null,
    updated_at: '2026-09-28T12:00:00Z',
    latest_text_revision: null,
    image: null,
    ...overrides,
  }
}

function servidor() {
  server.use(
    http.get('/api/v1/projects/1/assets', () => HttpResponse.json({ data: [foto] })),
    http.get('/api/v1/projects/1/instagram', () =>
      HttpResponse.json({ data: { username: '2msaudefeminina', status: 'active', expires_in_days: 50 } }),
    ),
  )
}

test('a previa acompanha o texto e a imagem escolhida', async () => {
  servidor()
  renderWithProviders(<PublicationEditor projectId="1" content={peca()} onClose={() => {}} />, ROUTE)
  const user = userEvent.setup()

  const previa = await screen.findByRole('figure', { name: 'Prévia do post' })
  expect(previa).toHaveTextContent('Sem imagem. O Instagram não publica post sem imagem.')

  await user.click(await screen.findByRole('button', { name: 'foto.jpg' }))
  expect(screen.getByRole('img', { name: 'Imagem do post' })).toHaveAttribute('src', foto.url)

  await user.clear(screen.getByLabelText('Legenda'))
  await user.type(screen.getByLabelText('Legenda'), 'Texto novo')
  expect(previa).toHaveTextContent('Texto novo')
  expect(await screen.findByText('@2msaudefeminina')).toBeInTheDocument()
})

test('salvar manda o texto e troca a imagem', async () => {
  servidor()
  let texto: unknown = null
  let imagem: unknown = null
  server.use(
    http.patch('/api/v1/contents/1/draft', async ({ request }) => {
      texto = await request.json()
      return HttpResponse.json({ data: peca() })
    }),
    http.put('/api/v1/contents/1/image', async ({ request }) => {
      imagem = await request.json()
      return HttpResponse.json({ data: peca() })
    }),
  )
  const onClose = vi.fn()

  renderWithProviders(<PublicationEditor projectId="1" content={peca()} onClose={onClose} />, ROUTE)
  const user = userEvent.setup()

  await user.click(await screen.findByRole('button', { name: 'foto.jpg' }))
  await user.clear(screen.getByLabelText(/Hashtags/))
  await user.type(screen.getByLabelText(/Hashtags/), 'saude, bem_estar')
  await user.click(screen.getByRole('button', { name: 'Salvar' }))

  await vi.waitFor(() => expect(onClose).toHaveBeenCalled())
  expect(texto).toEqual({ title: 'Ciclo', caption: 'Legenda', cta: 'Agende', hashtags: ['#saude', '#bem_estar'] })
  expect(imagem).toEqual({ asset_id: 9 })
})

test('peca aprovada fica somente leitura, mostra quem aprovou e agenda', async () => {
  servidor()
  let agendado: unknown = null
  server.use(
    http.post('/api/v1/contents/1/schedule', async ({ request }) => {
      agendado = await request.json()
      return HttpResponse.json({ data: peca({ status: 'scheduled' }) })
    }),
  )

  renderWithProviders(
    <PublicationEditor
      projectId="1"
      content={peca({
        status: 'approved',
        approver: { id: 2, name: 'Ana Revisora' },
        approved_at: '2026-09-27T12:00:00Z',
      })}
      onClose={() => {}}
    />,
    ROUTE,
  )
  const user = userEvent.setup()

  expect(await screen.findByText(/Aprovada por Ana Revisora/)).toBeInTheDocument()
  expect(screen.getByLabelText('Legenda')).toBeDisabled()
  expect(screen.queryByRole('button', { name: 'Salvar' })).not.toBeInTheDocument()

  await user.type(screen.getByLabelText(/Publicar em/), '2026-10-01T08:30')
  await user.click(screen.getByRole('button', { name: 'Agendar' }))

  await vi.waitFor(() => expect(agendado).toEqual({ scheduled_for: '2026-10-01T08:30' }))
})
