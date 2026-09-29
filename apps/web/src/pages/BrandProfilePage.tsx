import { useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { Button } from '@/components/ui/Button'
import { Card, CardDescription, CardTitle } from '@/components/ui/Card'
import { Input } from '@/components/ui/Input'
import { Shell } from '@/components/ui/Shell'
import { Stepper, type Step } from '@/components/ui/Stepper'
import { Textarea } from '@/components/ui/Textarea'
import { ApiError, api } from '@/lib/api'
import {
  type Competitor,
  competitorsFromLines,
  competitorsToLines,
  fromLines,
  toLines,
} from '@/lib/listField'
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

type Payload = Partial<Record<EditableField, string | string[] | Competitor[]>>

type Field = {
  name: EditableField
  label: string
  /** `competitors`: lista de {name, url}, nao de strings (ver listField). */
  kind: 'text' | 'textarea' | 'list' | 'competitors'
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
      {
        name: 'competitors',
        label: 'Concorrentes',
        kind: 'competitors',
        hint: 'Um por linha. Link opcional depois do nome, ex: Clínica X https://clinicax.com.br',
      },
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

const CAMPOS_LINK = new Set<EditableField>(['website', 'instagram', 'linkedin'])
const HEX = /^#[0-9a-fA-F]{6}$/

/** As mesmas frases da API (UpdateBrandProfileRequest::messages). */
const MSG_LINK = (rotulo: string) =>
  `O campo ${rotulo} deve ser um link completo começando com https://.`
const MSG_COR = 'Cada cor precisa ser um código hexadecimal com #, ex: #b23a6f.'

function linkValido(texto: string): boolean {
  if (!texto.startsWith('https://')) return false
  try {
    new URL(texto)
    return true
  } catch {
    return false
  }
}

/** O que da para conferir sem o servidor: formato de cor e de link. Vazio e valido. */
function validar(fields: Field[], raws: Record<string, string>): Record<string, string> {
  const erros: Record<string, string> = {}

  for (const field of fields) {
    const raw = raws[field.name].trim()

    if (field.name === 'colors' && fromLines(raw).some((cor) => !HEX.test(cor))) {
      erros.colors = MSG_COR
    }

    if (CAMPOS_LINK.has(field.name) && raw !== '' && !linkValido(raw)) {
      erros[field.name] = MSG_LINK(field.label)
    }
  }

  return erros
}

export function BrandProfilePage() {
  const { projectId } = useParams()
  const queryClient = useQueryClient()
  const [currentId, setCurrentId] = useState('identity')
  const [alterado, setAlterado] = useState(false)
  const [destinoPendente, setDestinoPendente] = useState<string | null>(null)
  const [errosLocais, setErrosLocais] = useState<Record<string, string>>({})
  const formRef = useRef<HTMLFormElement>(null)

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
    mutationFn: (payload: Payload) =>
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

  /** Troca de passo de fato: o formulario remonta, e o que nao foi salvo se perde. */
  function irPara(id: string) {
    setCurrentId(id)
    setAlterado(false)
    setDestinoPendente(null)
    setErrosLocais({})
    save.reset()
  }

  /**
   * Pular e o Stepper passam por aqui. Com alteracao nao salva, pergunta antes:
   * trocar de passo remonta o formulario e o texto digitado sumia sem aviso.
   */
  function pedirPara(id: string) {
    if (id === currentId) return
    if (alterado) setDestinoPendente(id)
    else irPara(id)
  }

  function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    const raws = Object.fromEntries(
      step.fields.map((field) => [field.name, String(form.get(field.name) ?? '')]),
    )

    // O formato errado e recusado aqui, com a mesma frase da API: mandar para ver
    // voltar 422 so atrasava a resposta.
    const errosDaTela = validar(step.fields, raws)
    setErrosLocais(errosDaTela)
    if (Object.keys(errosDaTela).length > 0) return

    // Envia apenas os campos deste passo: o PATCH e um merge parcial.
    // Campos `list` viram array de strings, `competitors` array de {name, url};
    // o resto vai como texto.
    const payload = Object.fromEntries(
      step.fields.map((field) => {
        const raw = raws[field.name]
        const value =
          field.kind === 'list'
            ? fromLines(raw)
            : field.kind === 'competitors'
              ? competitorsFromLines(raw)
              : raw
        return [field.name, value]
      }),
    ) as Payload

    const destino = destinoPendente ?? nextStep?.id
    save.mutate(payload, {
      onSuccess: () => {
        if (destino) irPara(destino)
        else setAlterado(false)
      },
      // Falhou: fica no passo, com o que foi digitado e o erro a mostra.
      onError: () => setDestinoPendente(null),
    })
  }

  const naoSalvou = save.isError || Object.keys(errosLocais).length > 0

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
          {salvarFuso.isError ? (
            <p className="text-body-sm text-error mt-2" role="alert">
              Não foi possível salvar o fuso.{' '}
              {salvarFuso.error instanceof ApiError
                ? (salvarFuso.error.message422 ?? 'Tente novamente.')
                : 'Tente novamente.'}
            </p>
          ) : (
            <p className="text-body-sm text-on-surface-variant mt-2" role="status">
              {salvarFuso.isPending
                ? 'Salvando…'
                : salvarFuso.isSuccess
                  ? 'Fuso atualizado. As próximas peças serão agendadas nele.'
                  : 'O agendamento das peças usa este fuso.'}
            </p>
          )}
        </div>
      )}

      <div className="mt-12 flex gap-16">
        <div className="w-64 shrink-0">
          <Stepper
            steps={STEPS}
            currentId={currentId}
            completedIds={completedIds}
            optionalIds={optionalIds}
            onSelect={pedirPara}
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

          {destinoPendente && (
            <div
              role="alert"
              className="mt-6 rounded-control border border-outline-variant bg-surface-container-low p-4"
            >
              <p className="text-body-md text-on-surface">
                Você tem alterações não salvas neste passo.
              </p>
              <div className="mt-3 flex flex-wrap gap-3">
                <Button
                  type="button"
                  disabled={save.isPending}
                  onClick={() => formRef.current?.requestSubmit()}
                >
                  Salvar e continuar
                </Button>
                <Button type="button" variant="ghost" onClick={() => irPara(destinoPendente)}>
                  Descartar e pular
                </Button>
                <Button type="button" variant="ghost" onClick={() => setDestinoPendente(null)}>
                  Continuar editando
                </Button>
              </div>
            </div>
          )}

          {naoSalvou && (
            <p role="alert" className="text-body-sm text-error mt-6">
              Este passo não foi salvo.{' '}
              {Object.keys(errosLocais).length > 0
                ? 'Corrija os campos destacados.'
                : (error?.message422 ?? 'Tente novamente.')}
            </p>
          )}

          <form
            ref={formRef}
            onSubmit={submit}
            onChange={() => setAlterado(true)}
            className="mt-6 flex flex-col gap-6"
          >
            {step.fields.map((field) => {
              const value = data[field.name]
              const props = {
                name: field.name,
                label: field.label,
                placeholder: field.placeholder,
                hint: field.hint,
                defaultValue:
                  field.kind === 'list'
                    ? toLines(value as string[] | null)
                    : field.kind === 'competitors'
                      ? competitorsToLines(value as Competitor[] | null)
                      : ((value as string | null) ?? ''),
                error: errosLocais[field.name] ?? error?.fieldError(field.name),
              }

              return field.kind !== 'text' ? (
                <Textarea key={field.name} {...props} />
              ) : (
                <Input key={field.name} {...props} />
              )
            })}

            <div className="flex justify-end gap-3">
              {nextStep && (
                <Button type="button" variant="ghost" onClick={() => pedirPara(nextStep.id)}>
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
