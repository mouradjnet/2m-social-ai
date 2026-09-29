import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { HttpResponse, http } from 'msw'
import { expect, test, vi } from 'vitest'
import { server } from '@/test/server'
import { renderWithProviders } from '@/test/utils'
import type { Asset } from '@/lib/types'
import { LibraryPage } from './LibraryPage'

const ROUTE = { path: '/projects/:projectId/library', entry: '/projects/1/library' }

const capa: Asset = {
  id: 9,
  project_id: 1,
  original_name: 'capa.jpg',
  mime: 'image/jpeg',
  size_bytes: 204800,
  width: 1080,
  height: 1350,
  url: 'https://x.test/storage/media/uuid.jpg',
  contents_count: 2,
  created_at: '2026-09-28T12:00:00Z',
}

test('lista as imagens com dimensoes, tamanho e uso', async () => {
  server.use(http.get('/api/v1/projects/1/assets', () => HttpResponse.json({ data: [capa] })))

  renderWithProviders(<LibraryPage />, ROUTE)

  expect(await screen.findByRole('img', { name: 'capa.jpg' })).toHaveAttribute('src', capa.url)
  expect(screen.getByText('1080×1350 · 200 KB · em 2 peças')).toBeInTheDocument()
})

test('sobe a imagem como multipart e mostra o motivo da recusa', async () => {
  // O corpo nao e lido: FormData com File no jsdom trava o `request.formData()` do msw.
  // O Content-Type com boundary ja prova que foi multipart montado pelo navegador.
  let tipo: string | null = null
  server.use(
    http.get('/api/v1/projects/1/assets', () => HttpResponse.json({ data: [] })),
    http.post('/api/v1/projects/1/assets', async ({ request }) => {
      tipo = request.headers.get('content-type')
      return HttpResponse.json(
        {
          message: 'A imagem tem 200 px de largura; o Instagram exige pelo menos 320.',
          errors: { file: ['A imagem tem 200 px de largura; o Instagram exige pelo menos 320.'] },
        },
        { status: 422 },
      )
    }),
  )

  renderWithProviders(<LibraryPage />, ROUTE)
  const user = userEvent.setup()
  await screen.findByText('Nenhuma imagem ainda.')

  await user.upload(screen.getByLabelText('Arquivo de imagem'), new File(['x'], 'mini.jpg', { type: 'image/jpeg' }))

  expect(await screen.findByRole('alert')).toHaveTextContent('o Instagram exige pelo menos 320')
  expect(tipo).toMatch(/^multipart\/form-data; boundary=/)
})

test('remover pede confirmacao e mostra o conflito do servidor', async () => {
  let removeu = 0
  server.use(
    http.get('/api/v1/projects/1/assets', () => HttpResponse.json({ data: [capa] })),
    http.delete('/api/v1/assets/9', () => {
      removeu++
      return HttpResponse.json(
        { message: 'Esta imagem está numa peça aprovada. Troque a imagem da peça antes de remover.' },
        { status: 409 },
      )
    }),
  )

  renderWithProviders(<LibraryPage />, ROUTE)
  const user = userEvent.setup()

  await user.click(await screen.findByRole('button', { name: 'Remover' }))
  expect(removeu).toBe(0)

  await user.click(screen.getByRole('button', { name: 'Confirmar remoção' }))
  await vi.waitFor(() => expect(removeu).toBe(1))
  expect(await screen.findByRole('alert')).toHaveTextContent('peça aprovada')
})

test('video de Reel aparece como video, com a duracao', async () => {
  const reel: Asset = {
    ...capa,
    id: 10,
    original_name: 'reel.mp4',
    mime: 'video/mp4',
    type: 'video',
    duration_ms: 42_500,
    width: 1080,
    height: 1920,
    url: 'https://x.test/storage/media/uuid.mp4',
    contents_count: 0,
  }
  server.use(http.get('/api/v1/projects/1/assets', () => HttpResponse.json({ data: [reel] })))

  const { container } = renderWithProviders(<LibraryPage />, ROUTE)

  expect(await screen.findByText(/🎬 0:43 · 1080×1920/)).toBeInTheDocument()
  expect(container.querySelector('video')).toHaveAttribute('src', reel.url)
  expect(screen.queryByRole('img')).not.toBeInTheDocument()
})
