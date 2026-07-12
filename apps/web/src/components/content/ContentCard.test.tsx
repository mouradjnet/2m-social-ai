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
    scheduled_for: null,
    image_prompt: null,
    latest_review: null,
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

test('peca reprovada mostra a contagem e as violacoes com a sugestao', () => {
  const review = {
    id: 1,
    verdict: 'fail' as const,
    summary: 'A legenda foge do tom.',
    violations: [
      { rule: 'tom de voz', excerpt: 'texto ofensor', suggestion: 'reescrever assim' },
      { rule: 'palavra proibida', excerpt: 'financiamento', suggestion: 'usar crédito' },
    ],
    created_at: new Date().toISOString(),
  }

  render(<ContentCard content={content({ latest_review: review })} {...noop} />)

  expect(screen.getByText(/2 violações/i)).toBeInTheDocument()
  // Quem vai corrigir precisa ver o que esta errado sem clicar.
  expect(screen.getByText(/tom de voz/i)).toBeInTheDocument()
  expect(screen.getByText(/reescrever assim/i)).toBeInTheDocument()
  expect(screen.getByText(/usar crédito/i)).toBeInTheDocument()
})

test('peca aprovada na revisao mostra o chip sem violacoes', () => {
  const review = {
    id: 1,
    verdict: 'pass' as const,
    summary: 'Coerente com a marca.',
    violations: [],
    created_at: new Date().toISOString(),
  }

  render(<ContentCard content={content({ latest_review: review })} {...noop} />)

  expect(screen.getByText(/sem violações/i)).toBeInTheDocument()
})

test('peca nunca revisada nao mostra veredito', () => {
  render(<ContentCard content={content()} {...noop} />)

  expect(screen.queryByText(/violações/i)).not.toBeInTheDocument()
})

test('peca com image_prompt mostra o prompt e copia para a area de transferencia', async () => {
  const user = userEvent.setup()
  const writeText = vi.fn().mockResolvedValue(undefined)
  vi.stubGlobal('navigator', { ...navigator, clipboard: { writeText } })

  const prompt = 'A wide, softly lit workshop scene in emerald tones.'
  render(<ContentCard content={content({ image_prompt: prompt })} {...noop} />)

  expect(screen.getByText(prompt)).toBeInTheDocument()

  await user.click(screen.getByRole('button', { name: /copiar/i }))

  expect(writeText).toHaveBeenCalledWith(prompt)
  expect(await screen.findByRole('button', { name: /copiado/i })).toBeInTheDocument()

  vi.unstubAllGlobals()
})

test('peca sem image_prompt nao mostra o botao copiar', () => {
  render(<ContentCard content={content()} {...noop} />)

  expect(screen.queryByRole('button', { name: /copiar/i })).not.toBeInTheDocument()
})
