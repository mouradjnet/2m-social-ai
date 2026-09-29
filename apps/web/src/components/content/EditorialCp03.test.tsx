import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { HttpResponse, http } from 'msw'
import { expect, test, vi } from 'vitest'
import { StrategyCard } from '@/components/strategy/StrategyCard'
import { server } from '@/test/server'
import { renderWithProviders } from '@/test/utils'
import type { Content, Strategy, WeekPlan } from '@/lib/types'
import { ContentCard } from './ContentCard'
import { WeekPlanPanel } from './WeekPlanPanel'

// CP-03: a interface editorial — formato, roteiro (nao midia), estado e diretrizes.

function peca(overrides: Partial<Content> = {}): Content {
  return {
    id: 1,
    project_id: 1,
    title: 'Autocuidado em 5 minutos',
    caption: 'Pequenos hábitos que cabem na rotina.',
    cta: 'Salve para consultar depois',
    hashtags: ['#autocuidado'],
    format: 'post',
    channel: 'instagram',
    status: 'idea',
    scheduled_for: null,
    image_prompt: null,
    latest_review: null,
    latest_seo: null,
    source: 'ai',
    origin_ai_run_id: 7,
    updated_at: '2026-09-30T10:00:00Z',
    latest_text_revision: null,
    ...overrides,
  }
}

const noop = {
  onAdvance: vi.fn(),
  onBack: vi.fn(),
  onArchive: vi.fn(),
  onUnarchive: vi.fn(),
  onApplySeo: vi.fn(),
  onRewrite: vi.fn(),
  pending: false,
}

test('carrossel mostra os slides do roteiro e avisa que não é a arte final', async () => {
  render(
    <ContentCard
      {...noop}
      content={peca({
        format: 'carousel',
        structure: {
          visual: 'Fundo rosa claro, tipografia grande.',
          slides: [
            { heading: 'Você dorme bem?', body: 'Capa com a pergunta.' },
            { heading: 'Rotina de sono', body: 'Horário fixo para deitar.' },
            { heading: 'Para lembrar', body: 'Salve este post.' },
          ],
        },
      })}
    />,
  )

  expect(screen.getByText(/Carrossel · instagram/)).toBeInTheDocument()
  await userEvent.setup().click(screen.getByText(/Roteiro do carrossel/))
  expect(screen.getByText(/não é a arte nem o vídeo final/i)).toBeInTheDocument()
  expect(screen.getByText('Rotina de sono')).toBeInTheDocument()
  expect(screen.getByText(/Fundo rosa claro/)).toBeInTheDocument()
})

test('Reels mostra gancho, cenas e orientação de produção; Stories mostra as telas', async () => {
  const user = userEvent.setup()
  const { unmount } = render(
    <ContentCard
      {...noop}
      content={peca({
        format: 'reel',
        structure: {
          visual: 'Luz natural.',
          hook: 'Um hábito de 1 minuto que muda a noite.',
          scenes: [
            { description: 'Rosto em close', on_screen_text: 'Antes de dormir', narration: 'Conte o hábito.' },
            { description: 'Mãos com o celular', on_screen_text: 'Modo noturno', narration: 'Feche com o convite.' },
          ],
          production_notes: 'Vertical 9:16, 20 s.',
        },
      })}
    />,
  )

  expect(screen.getByText(/Reels · instagram/)).toBeInTheDocument()
  await user.click(screen.getByText(/Roteiro do Reels/))
  expect(screen.getByText(/Um hábito de 1 minuto/)).toBeInTheDocument()
  expect(screen.getByText(/Rosto em close/)).toBeInTheDocument()
  expect(screen.getByText(/Vertical 9:16/)).toBeInTheDocument()
  unmount()

  render(
    <ContentCard
      {...noop}
      content={peca({
        format: 'story',
        structure: {
          visual: 'Fundo liso.',
          screens: [
            { text: 'Qual seu momento de autocuidado?', visual: 'v', interaction: 'enquete' },
            { text: 'Conta nos comentários', visual: 'v', interaction: '' },
          ],
        },
      })}
    />,
  )

  expect(screen.getByText(/Stories · instagram/)).toBeInTheDocument()
  await user.click(screen.getByText(/Roteiro do Stories/))
  expect(screen.getByText(/Qual seu momento de autocuidado\?/)).toBeInTheDocument()
  expect(screen.getByText(/interação: enquete/)).toBeInTheDocument()
})

test('peça sem roteiro (manual ou antiga) não mostra bloco de roteiro', () => {
  render(<ContentCard {...noop} content={peca({ structure: null })} />)

  expect(screen.getByText(/Feed · instagram/)).toBeInTheDocument()
  expect(screen.queryByText(/Roteiro/)).not.toBeInTheDocument()
})

test('o estado editorial aparece na revisão', () => {
  const { rerender } = render(
    <ContentCard {...noop} content={peca({ status: 'review', editorial_state: 'ready_for_approval' })} />,
  )
  expect(screen.getByText('Estado editorial: Pronta para aprovação')).toBeInTheDocument()

  rerender(<ContentCard {...noop} content={peca({ status: 'review', editorial_state: 'needs_revision' })} />)
  expect(screen.getByText('Estado editorial: Precisa de ajuste')).toBeInTheDocument()

  // Fora da revisão o status já diz tudo: sem linha extra.
  rerender(<ContentCard {...noop} content={peca({ status: 'idea', editorial_state: 'draft' })} />)
  expect(screen.queryByText(/Estado editorial/)).not.toBeInTheDocument()
})

function estrategia(overrides: Partial<Strategy> = {}): Strategy {
  return {
    id: 1,
    workspace_id: 1,
    project_id: 1,
    title: 'Piloto',
    summary: 'Resumo',
    editorial_line: 'Linha',
    pillars: [{ name: 'Autocuidado', weight: 100, description: 'd' }],
    status: 'draft',
    ai_run_id: null,
    guidelines: {
      objectives: ['Fazer a audiência crescer', 'Fortalecer a marca'],
      themes: ['Sono', 'Pele'],
      formats: ['post', 'reel'],
      weekly_frequency: 3,
      content_mix: { educational: 70, institutional: 30, commercial: 0 },
    },
    ...overrides,
  }
}

test('a estratégia mostra objetivos, formatos, frequência e distribuição antes de aprovar', () => {
  const props = { pending: false, generating: false, onApprove: vi.fn(), onArchive: vi.fn(), onRegenerate: vi.fn() }
  render(<StrategyCard strategy={estrategia()} {...props} />)

  expect(screen.getByText('Fazer a audiência crescer')).toBeInTheDocument()
  expect(screen.getByText(/Feed, Reels — 3 publicações por semana/)).toBeInTheDocument()
  expect(screen.getByText(/Educativo 70% · Institucional 30% ·/)).toBeInTheDocument()
  expect(screen.getByText(/não há produto ou serviço confirmado/)).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'Aprovar estratégia' })).toBeInTheDocument()
})

test('estratégia antiga, sem diretrizes, continua aparecendo como antes', () => {
  const props = { pending: false, generating: false, onApprove: vi.fn(), onArchive: vi.fn(), onRegenerate: vi.fn() }
  render(<StrategyCard strategy={estrategia({ guidelines: null })} {...props} />)

  expect(screen.getByText('Pilares de conteúdo')).toBeInTheDocument()
  expect(screen.queryByText('Objetivos')).not.toBeInTheDocument()
})

test('o plano da semana mostra objetivo e CTA de cada horário', async () => {
  const plano: WeekPlan = {
    id: 4,
    period_start: '2026-10-05',
    period_end: '2026-10-11',
    posts_count: 1,
    contents_count: 0,
    created_at: '2026-09-29T12:00:00Z',
    distribution: {
      summary: 's',
      slots: [
        {
          date: '2026-10-06', time: '19:00', pillar: 'Autocuidado', format: 'reel', channel: 'instagram',
          theme: 'Sono e bem-estar', objective: 'Engajar', cta: 'Comente seu hábito', rationale: 'r',
        },
      ],
    },
  }
  server.use(http.get('/api/v1/projects/1/week-plan', () => HttpResponse.json({ data: plano })))

  renderWithProviders(
    <WeekPlanPanel projectId="1" generating={false} onPlan={() => {}} onWrite={() => {}} />,
    { path: '/projects/:projectId/content', entry: '/projects/1/content' },
  )

  const item = (await screen.findByText('Sono e bem-estar')).closest('li')!
  expect(within(item).getByText(/Engajar/)).toBeInTheDocument()
  expect(within(item).getByText(/Comente seu hábito/)).toBeInTheDocument()
})

test('o número de peças vem da frequência da estratégia, e a pessoa pode mudar', async () => {
  server.use(http.get('/api/v1/projects/1/week-plan', () => HttpResponse.json({ data: null })))
  const onPlan = vi.fn()
  const user = userEvent.setup()

  renderWithProviders(
    <WeekPlanPanel projectId="1" generating={false} onPlan={onPlan} onWrite={() => {}} suggestedPosts={5} />,
    { path: '/projects/:projectId/content', entry: '/projects/1/content' },
  )

  const pecas = screen.getByRole('spinbutton', { name: 'Peças' })
  expect(pecas).toHaveValue(5)
  expect(screen.getByText('A estratégia recomenda 5 publicações por semana.')).toBeInTheDocument()

  await user.click(screen.getByRole('button', { name: 'Planejar semana com IA' }))
  expect(onPlan).toHaveBeenLastCalledWith(expect.objectContaining({ posts: 5 }))

  await user.clear(pecas)
  await user.type(pecas, '2')
  await user.click(screen.getByRole('button', { name: 'Planejar semana com IA' }))
  expect(onPlan).toHaveBeenLastCalledWith(expect.objectContaining({ posts: 2 }))
})
