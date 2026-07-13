import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { Button } from '@/components/ui/Button'
import { Card, CardDescription, CardTitle } from '@/components/ui/Card'
import { Input } from '@/components/ui/Input'
import { Shell } from '@/components/ui/Shell'
import { Stepper, type Step } from '@/components/ui/Stepper'
import { Textarea } from '@/components/ui/Textarea'
import { ApiError, api } from '@/lib/api'
import { fromLines, toLines } from '@/lib/listField'
import type { BrandProfileResponse } from '@/lib/types'

/** So os campos que o wizard edita — `id` e `project_id` ficam de fora. */
type EditableField =
  | 'brand_name'
  | 'description'
  | 'audience'
  | 'persona'
  | 'tone_of_voice'
  | 'differentiators'
  | 'products'
  | 'services'
  | 'competitors'
  | 'required_words'
  | 'forbidden_words'
  | 'colors'
  | 'website'
  | 'instagram'
  | 'linkedin'

type Field = {
  name: EditableField
  label: string
  kind: 'text' | 'textarea' | 'list'
  placeholder?: string
  hint?: string
}

const STEPS: Array<Step & { fields: Field[]; description?: string }> = [
  {
    id: 'identity',
    label: 'Passo 1',
    title: 'Identidade',
    fields: [
      { name: 'brand_name', label: 'Nome da marca', kind: 'text', placeholder: 'ex: Acme Corp' },
      {
        name: 'description',
        label: 'Descrição curta',
        kind: 'textarea',
        placeholder: 'Descreva sua marca em algumas frases…',
      },
      {
        // Opcional: nao entra no `percent`. Quem le e o designer, no prompt de imagem.
        name: 'colors',
        label: 'Cores da marca',
        kind: 'list',
        hint: 'Um hex por linha, ex: #006c49',
      },
    ],
  },
  {
    id: 'audience',
    label: 'Passo 2',
    title: 'Público-Alvo',
    fields: [
      { name: 'audience', label: 'Público', kind: 'textarea' },
      { name: 'persona', label: 'Persona', kind: 'textarea' },
    ],
  },
  {
    id: 'positioning',
    label: 'Passo 3',
    title: 'Posicionamento de Mercado',
    fields: [
      { name: 'tone_of_voice', label: 'Tom de voz', kind: 'textarea' },
      { name: 'differentiators', label: 'Diferenciais', kind: 'textarea' },
    ],
  },
  {
    id: 'offer',
    label: 'Passo 4',
    title: 'Oferta',
    fields: [
      { name: 'products', label: 'Produtos', kind: 'list', hint: 'Um por linha' },
      { name: 'services', label: 'Serviços', kind: 'list', hint: 'Um por linha' },
    ],
  },
  {
    id: 'vocabulary',
    label: 'Passo 5',
    title: 'Vocabulário e Concorrência',
    description: 'Influencia o texto gerado. Palavras proibidas nunca aparecerão nas peças.',
    fields: [
      { name: 'competitors', label: 'Concorrentes', kind: 'list', hint: 'Um por linha' },
      { name: 'required_words', label: 'Palavras obrigatórias', kind: 'list', hint: 'Uma por linha' },
      { name: 'forbidden_words', label: 'Palavras proibidas', kind: 'list', hint: 'Uma por linha' },
    ],
  },
  {
    id: 'social',
    label: 'Passo 6',
    title: 'Links Sociais',
    description: 'Não afeta a estratégia. Usado na exportação.',
    fields: [
      { name: 'website', label: 'Site', kind: 'text', hint: 'Precisa começar com https://' },
      { name: 'instagram', label: 'Instagram', kind: 'text' },
      { name: 'linkedin', label: 'LinkedIn', kind: 'text' },
    ],
  },
]

/**
 * Fusos oferecidos. Não é a lista da IANA inteira (600+): é a lista que um cliente
 * desta agência realmente usa. O valor atual do projeto entra mesmo se não estiver
 * aqui — a tela não pode apagar um fuso que alguém escolheu pela API.
 */
const FUSOS = [
  'America/Sao_Paulo',
  'America/Manaus',
  'America/Belem',
  'America/Fortaleza',
  'America/Cuiaba',
  'America/Rio_Branco',
  'America/New_York',
  'Europe/Lisbon',
  'Europe/Madrid',
]

type Projeto = { data: { id: number; name: string; timezone: string } }

export function BrandProfilePage() {
  const { projectId } = useParams()
  const queryClient = useQueryClient()
  const [currentId, setCurrentId] = useState('identity')

  const projectKey = ['project', projectId]
  const project = useQuery({
    queryKey: projectKey,
    queryFn: () => api<Projeto>(`/projects/${projectId}`),
  })

  const salvarFuso = useMutation({
    mutationFn: (timezone: string) =>
      api<Projeto>(`/projects/${projectId}`, {
        method: 'PATCH',
        body: JSON.stringify({ timezone }),
      }),
    onSuccess: (response) => queryClient.setQueryData(projectKey, response),
  })

  const queryKey = ['brand-profile', projectId]
  const profile = useQuery({
    queryKey,
    queryFn: () => api<BrandProfileResponse>(`/projects/${projectId}/brand-profile`),
  })

  const save = useMutation({
    mutationFn: (payload: Partial<Record<EditableField, string | string[]>>) =>
      api<BrandProfileResponse>(`/projects/${projectId}/brand-profile`, {
        method: 'PATCH',
        body: JSON.stringify(payload),
      }),
    onSuccess: (response) => queryClient.setQueryData(queryKey, response),
  })

  if (profile.isPending) return <Shell>Carregando…</Shell>
  if (profile.isError) return <Shell>Projeto não encontrado.</Shell>

  const { data, completion } = profile.data
  const step = STEPS.find((s) => s.id === currentId)!
  const stepIndex = STEPS.indexOf(step)
  const nextStep = STEPS[stepIndex + 1]

  const completedIds = completion.steps.filter((s) => s.complete).map((s) => s.id)
  const optionalIds = completion.steps.filter((s) => !s.required).map((s) => s.id)

  const error = save.error instanceof ApiError ? save.error : null

  function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)

    // Envia apenas os campos deste passo: o PATCH e um merge parcial.
    // Campos `list` viram array de strings; o resto vai como texto.
    const payload = Object.fromEntries(
      step.fields.map((field) => {
        const raw = String(form.get(field.name) ?? '')
        return [field.name, field.kind === 'list' ? fromLines(raw) : raw]
      }),
    ) as Partial<Record<EditableField, string | string[]>>

    save.mutate(payload, {
      onSuccess: () => {
        if (nextStep) setCurrentId(nextStep.id)
      },
    })
  }

  return (
    <Shell>
      <Link to="/" className="text-body-sm text-on-surface-variant hover:text-primary">
        ← Projetos
      </Link>

      <h1 className="text-display-lg text-on-surface mt-4">Vamos definir sua marca.</h1>
      <p className="text-body-lg text-on-surface-variant mt-4 max-w-2xl">
        Sou seu Estrategista de IA. Vamos trabalhar juntos para estabelecer a
        identidade principal da sua marca. Perfil {completion.percent}% completo.
      </p>

      <Link
        to={`/projects/${projectId}/strategy`}
        className="text-body-sm text-primary mt-4 inline-block hover:underline"
      >
        Ir para a Estratégia editorial →
      </Link>

      {/*
        O fuso do projeto. Não é enfeite: o social_media agenda NELE, e o fuso errado
        publica na madrugada do público. Até aqui só podia ser dito na criação, e todo
        projeto existente ficou preso no default.
      */}
      {project.data && (
        <div className="mt-8 max-w-md">
          <label
            htmlFor="timezone"
            className="text-label-sm text-on-surface-variant block uppercase"
          >
            Fuso em que esta marca publica
          </label>
          <select
            id="timezone"
            className="border-outline text-body-md text-on-surface mt-2 w-full rounded-md border bg-surface px-3 py-2"
            value={project.data.data.timezone}
            disabled={salvarFuso.isPending}
            onChange={(event) => salvarFuso.mutate(event.target.value)}
          >
            {[...new Set([project.data.data.timezone, ...FUSOS])].map((fuso) => (
              <option key={fuso} value={fuso}>
                {fuso.replace('_', ' ')}
              </option>
            ))}
          </select>
          <p className="text-body-sm text-on-surface-variant mt-2" role="status">
            {salvarFuso.isPending
              ? 'Salvando…'
              : salvarFuso.isSuccess
                ? 'Fuso atualizado. As próximas peças serão agendadas nele.'
                : 'O agendamento das peças usa este fuso.'}
          </p>
        </div>
      )}

      <div className="mt-12 flex gap-16">
        <div className="w-64 shrink-0">
          <Stepper
            steps={STEPS}
            currentId={currentId}
            completedIds={completedIds}
            optionalIds={optionalIds}
            onSelect={setCurrentId}
          />
        </div>

        {/* key: troca de passo remonta o formulario com os defaults do servidor */}
        <Card className="flex-1" key={step.id}>
          <span className="inline-flex items-center rounded-full bg-secondary-container px-3 py-1 text-label-sm font-display text-secondary">
            Estrategista de IA
          </span>

          <div className="mt-4">
            <CardTitle>{step.title}</CardTitle>
            <CardDescription>
              {step.description ?? 'Seja conciso; vamos expandir isso mais tarde.'}
            </CardDescription>
          </div>

          <form onSubmit={submit} className="mt-6 flex flex-col gap-6">
            {step.fields.map((field) => {
              const value = data[field.name]
              const props = {
                name: field.name,
                label: field.label,
                placeholder: field.placeholder,
                hint: field.hint,
                defaultValue:
                  field.kind === 'list' ? toLines(value as string[] | null) : (value ?? ''),
                error: error?.fieldError(field.name),
              }

              return field.kind === 'textarea' || field.kind === 'list' ? (
                <Textarea key={field.name} {...props} />
              ) : (
                <Input key={field.name} {...props} />
              )
            })}

            <div className="flex justify-end gap-3">
              {nextStep && (
                <Button type="button" variant="ghost" onClick={() => setCurrentId(nextStep.id)}>
                  Pular
                </Button>
              )}
              <Button type="submit" disabled={save.isPending}>
                {save.isPending
                  ? 'Salvando…'
                  : nextStep
                    ? `Próximo: ${nextStep.title}`
                    : 'Salvar'}
              </Button>
            </div>
          </form>
        </Card>
      </div>
    </Shell>
  )
}
