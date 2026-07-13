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
    latest_seo: null,
    source: 'ai',
    origin_ai_run_id: 7,
    updated_at: '2026-07-13T10:00:00Z',
    latest_text_revision: null,
    ...overrides,
  }
}

const noop = {
  onAdvance: vi.fn(),
  onBack: vi.fn(),
  onArchive: vi.fn(),
  onApplySeo: vi.fn(),
  onRewrite: vi.fn(),
  pending: false,
}

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

const seo = {
  id: 1,
  title: 'Titulo que responde a uma busca',
  keywords: ['dentista', 'clareamento'],
  hashtags: ['#odonto'],
  applied_at: null,
  created_at: new Date().toISOString(),
}

test('sugestao de SEO mostra titulo, keywords e o botao aplicar', async () => {
  const user = userEvent.setup()
  const onApplySeo = vi.fn()

  render(<ContentCard content={content({ latest_seo: seo })} {...noop} onApplySeo={onApplySeo} />)

  expect(screen.getByText(/sugestão de seo/i)).toBeInTheDocument()
  expect(screen.getByText(seo.title)).toBeInTheDocument()
  expect(screen.getByText(/dentista, clareamento/)).toBeInTheDocument()

  await user.click(screen.getByRole('button', { name: /aplicar seo/i }))
  expect(onApplySeo).toHaveBeenCalledOnce()
})

test('sugestao ja aplicada nao oferece aplicar de novo', () => {
  const aplicada = { ...seo, applied_at: new Date().toISOString() }

  render(<ContentCard content={content({ latest_seo: aplicada })} {...noop} />)

  expect(screen.getByText(/seo aplicado/i)).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: /aplicar seo/i })).not.toBeInTheDocument()
})

test('peca reprovada oferece reescrever com IA', async () => {
  const user = userEvent.setup()
  const onRewrite = vi.fn()
  const review = {
    id: 1,
    verdict: 'fail' as const,
    summary: 'Depoimento fabricado.',
    violations: [
      { rule: 'Depoimento fabricado', excerpt: 'O Ricardo', suggestion: 'Use um caso real.' },
    ],
    created_at: new Date().toISOString(),
  }

  render(<ContentCard content={content({ latest_review: review })} {...noop} onRewrite={onRewrite} />)

  await user.click(screen.getByRole('button', { name: /reescrever com ia/i }))

  expect(onRewrite).toHaveBeenCalledTimes(1)
})

test('peca aprovada nao oferece reescrever', () => {
  const review = {
    id: 1,
    verdict: 'pass' as const,
    summary: 'Coerente.',
    violations: [],
    created_at: new Date().toISOString(),
  }

  render(<ContentCard content={content({ latest_review: review })} {...noop} />)

  expect(screen.queryByRole('button', { name: /reescrever com ia/i })).not.toBeInTheDocument()
})

/**
 * O veredito fala do texto que existia quando ele foi escrito. A reescrita troca o
 * texto NO LUGAR: sem isto, o card seguiria acusando uma violacao ja corrigida — e
 * ainda ofereceria reescrever de novo o que acabou de ser reescrito.
 */
test('revisao anterior a reescrita vira aviso, nao violacao', () => {
  const review = {
    id: 1,
    verdict: 'fail' as const,
    summary: 'Depoimento fabricado.',
    violations: [
      { rule: 'Depoimento fabricado', excerpt: 'O Ricardo', suggestion: 'Use um caso real.' },
    ],
    created_at: '2026-07-13T10:00:00Z',
  }

  // O TEXTO mudou depois da revisao: foi reescrita.
  const peca = content({
    latest_review: review,
    latest_text_revision: { id: 3, created_at: '2026-07-13T11:00:00Z' },
  })

  render(<ContentCard content={peca} {...noop} />)

  expect(screen.getByText(/texto reescrito/i)).toBeInTheDocument()
  expect(screen.getByText(/mande revisar de novo/i)).toBeInTheDocument()

  // A violacao velha some, e o botao nao reaparece.
  expect(screen.queryByText(/1 violação/i)).not.toBeInTheDocument()
  expect(screen.queryByText(/use um caso real/i)).not.toBeInTheDocument()
  expect(screen.queryByRole('button', { name: /reescrever com ia/i })).not.toBeInTheDocument()
})

/**
 * O bug que a heuristica ingenua tinha: `updated_at` muda quando a peca ANDA no fluxo
 * ou e arquivada. Arquivar uma peca reprovada apagaria a violacao do card — o oposto
 * do que quem vai corrigir precisa ver. So a troca de TEXTO envelhece um veredito.
 */
test('arquivar a peca nao apaga a violacao do card', () => {
  const review = {
    id: 1,
    verdict: 'fail' as const,
    summary: 'Depoimento fabricado.',
    violations: [
      { rule: 'Depoimento fabricado', excerpt: 'O Ricardo', suggestion: 'Use um caso real.' },
    ],
    created_at: '2026-07-12T15:26:00Z',
  }

  // Arquivada 14 minutos DEPOIS da revisao (o caso real da peca 10 em producao),
  // mas o texto nunca mudou.
  const peca = content({
    latest_review: review,
    status: 'archived',
    updated_at: '2026-07-12T15:40:00Z',
    latest_text_revision: null,
  })

  render(<ContentCard content={peca} {...noop} />)

  expect(screen.getByText(/1 violação/i)).toBeInTheDocument()
  expect(screen.getByText(/use um caso real/i)).toBeInTheDocument()
  expect(screen.queryByText(/texto reescrito/i)).not.toBeInTheDocument()
})
