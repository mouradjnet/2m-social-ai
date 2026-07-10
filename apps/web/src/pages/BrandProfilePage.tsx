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
import type { BrandProfileResponse } from '@/lib/types'

/** So os campos que o wizard edita — `id` e `project_id` ficam de fora. */
type EditableField =
  | 'brand_name'
  | 'description'
  | 'audience'
  | 'persona'
  | 'tone_of_voice'
  | 'differentiators'
  | 'website'
  | 'instagram'
  | 'linkedin'

type Field = {
  name: EditableField
  label: string
  kind: 'text' | 'textarea'
  placeholder?: string
  hint?: string
}

const STEPS: Array<Step & { fields: Field[] }> = [
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
    id: 'social',
    label: 'Passo 4',
    title: 'Links Sociais',
    fields: [
      { name: 'website', label: 'Site', kind: 'text', hint: 'Precisa começar com https://' },
      { name: 'instagram', label: 'Instagram', kind: 'text' },
      { name: 'linkedin', label: 'LinkedIn', kind: 'text' },
    ],
  },
]

export function BrandProfilePage() {
  const { projectId } = useParams()
  const queryClient = useQueryClient()
  const [currentId, setCurrentId] = useState('identity')

  const queryKey = ['brand-profile', projectId]
  const profile = useQuery({
    queryKey,
    queryFn: () => api<BrandProfileResponse>(`/projects/${projectId}/brand-profile`),
  })

  const save = useMutation({
    mutationFn: (payload: Partial<Record<EditableField, string>>) =>
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

  const completedIds = STEPS.filter((s) => completion[s.id as keyof typeof completion]).map(
    (s) => s.id,
  )

  const error = save.error instanceof ApiError ? save.error : null

  function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)

    // Envia apenas os campos deste passo: o PATCH e um merge parcial.
    const payload = Object.fromEntries(
      step.fields.map((field) => [field.name, String(form.get(field.name) ?? '')]),
    ) as Partial<Record<EditableField, string>>

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

      <div className="mt-12 flex gap-16">
        <div className="w-64 shrink-0">
          <Stepper
            steps={STEPS}
            currentId={currentId}
            completedIds={completedIds}
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
              Seja conciso; vamos expandir isso mais tarde.
            </CardDescription>
          </div>

          <form onSubmit={submit} className="mt-6 flex flex-col gap-6">
            {step.fields.map((field) => {
              const props = {
                name: field.name,
                label: field.label,
                placeholder: field.placeholder,
                hint: field.hint,
                defaultValue: data[field.name] ?? '',
                error: error?.fieldError(field.name),
              }

              return field.kind === 'textarea' ? (
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
