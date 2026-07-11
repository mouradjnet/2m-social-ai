import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { expect, test, vi } from 'vitest'
import type { Content } from '@/lib/types'
import { ContentCard } from './ContentCard'

function content(overrides: Partial<Content> = {}): Content {
  return {
    id: 1,
    project_id: 1,
    title: 'Vistoria completa',
    caption: 'Antes de qualquer veículo entrar no pátio, ele passa por uma vistoria.',
    cta: 'Chame no WhatsApp',
    hashtags: ['#2FAutoShop', '#OrigemVerificada'],
    format: 'reel',
    channel: 'instagram',
    status: 'idea',
    source: 'ai',
    origin_ai_run_id: 7,
    ...overrides,
  }
}

const noop = { onAdvance: vi.fn(), onBack: vi.fn(), onArchive: vi.fn(), pending: false }

test('mostra formato, canal, titulo e o chip Gerado por IA', () => {
  render(<ContentCard content={content()} {...noop} />)

  expect(screen.getByText(/reel/i)).toBeInTheDocument()
  expect(screen.getByText(/instagram/i)).toBeInTheDocument()
  expect(screen.getByText('Vistoria completa')).toBeInTheDocument()
  expect(screen.getByText(/gerado por ia/i)).toBeInTheDocument()
})

test('peca manual nao mostra o chip de IA', () => {
  render(<ContentCard content={content({ source: 'manual' })} {...noop} />)

  expect(screen.queryByText(/gerado por ia/i)).not.toBeInTheDocument()
})

test('em idea, Voltar esta desabilitado e Avancar habilitado', () => {
  render(<ContentCard content={content({ status: 'idea' })} {...noop} />)

  expect(screen.getByRole('button', { name: /voltar/i })).toBeDisabled()
  expect(screen.getByRole('button', { name: /avançar/i })).toBeEnabled()
})

test('em approved, Avancar esta desabilitado', () => {
  render(<ContentCard content={content({ status: 'approved' })} {...noop} />)

  expect(screen.getByRole('button', { name: /avançar/i })).toBeDisabled()
})

test('em archived, os tres botoes estao desabilitados', () => {
  render(<ContentCard content={content({ status: 'archived' })} {...noop} />)

  expect(screen.getByRole('button', { name: /voltar/i })).toBeDisabled()
  expect(screen.getByRole('button', { name: /avançar/i })).toBeDisabled()
  expect(screen.getByRole('button', { name: /arquivar/i })).toBeDisabled()
})

test('Avancar chama onAdvance', async () => {
  const user = userEvent.setup()
  const onAdvance = vi.fn()
  render(<ContentCard content={content()} {...noop} onAdvance={onAdvance} />)

  await user.click(screen.getByRole('button', { name: /avançar/i }))
  expect(onAdvance).toHaveBeenCalledOnce()
})
