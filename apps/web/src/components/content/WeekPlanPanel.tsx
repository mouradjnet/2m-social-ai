import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Input } from '@/components/ui/Input'
import { api } from '@/lib/api'
import type { WeekPlan } from '@/lib/types'

interface Props {
  projectId: string
  /** Ha alguma geracao da pagina em andamento (o `?run=` e um so). */
  generating: boolean
  onPlan: (body: { starts_on: string; posts: number }) => void
  onWrite: (planId: number) => void
}

/** A chave mora sob ['contents', projectId]: o sucesso de qualquer geracao da pagina a invalida junto. */
const weekPlanKey = (projectId: string) => ['contents', projectId, 'week-plan']

const FORMATOS: Record<string, string> = {
  post: 'Post',
  carousel: 'Carrossel',
  reel: 'Reel',
  story: 'Story',
  video: 'Vídeo',
  article: 'Artigo',
  thread: 'Thread',
}

/** "2026-10-06" -> "ter 06/10", sem passar por Date (a data ja e local do projeto). */
function dia(data: string): string {
  const [a, m, d] = data.split('-').map(Number)
  const semana = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'][new Date(Date.UTC(a, m - 1, d)).getUTCDay()]

  return `${semana} ${String(d).padStart(2, '0')}/${String(m).padStart(2, '0')}`
}

function amanha(): string {
  const d = new Date()
  d.setDate(d.getDate() + 1)

  return d.toISOString().slice(0, 10)
}

/**
 * O plano da semana: o humano ve dia, hora, pilar e tema ANTES de pagar pelo texto.
 * Escrever as pecas e um segundo clique — e elas nascem como ideia, sem agenda.
 */
export function WeekPlanPanel({ projectId, generating, onPlan, onWrite }: Props) {
  const [inicio, setInicio] = useState(amanha)
  const [posts, setPosts] = useState(3)

  const plano = useQuery({
    queryKey: weekPlanKey(projectId),
    queryFn: () => api<{ data: WeekPlan | null }>(`/projects/${projectId}/week-plan`),
  })

  const p = plano.data?.data ?? null

  return (
    <Card className="mt-6" aria-label="Plano da semana">
      <h2 className="text-headline-md font-display text-on-surface">Plano da semana</h2>
      <p className="text-body-sm text-on-surface-variant mt-1">
        A IA propõe dia, hora, pilar e tema de cada peça. Você confere o plano antes de mandar escrever.
      </p>

      <div className="mt-4 flex flex-wrap items-end gap-3">
        <Input label="Semana começa em" type="date" value={inicio} onChange={(e) => setInicio(e.target.value)} />
        <Input
          label="Peças"
          type="number"
          min={1}
          max={7}
          value={posts}
          onChange={(e) => setPosts(Number(e.target.value))}
          className="w-24"
        />
        <Button variant="secondary" disabled={generating} onClick={() => onPlan({ starts_on: inicio, posts })}>
          Planejar semana com IA
        </Button>
      </div>

      {p && (
        <div className="mt-6">
          <p className="text-label-md text-on-surface">
            Semana de {dia(p.period_start)} a {dia(p.period_end)}
          </p>
          <p className="text-body-sm text-on-surface-variant mt-1">{p.distribution.summary}</p>

          <ul className="mt-3 flex flex-col gap-2">
            {p.distribution.slots.map((s) => (
              <li key={`${s.date} ${s.time}`} className="border-outline-variant rounded border p-3">
                <p className="text-label-md text-on-surface">
                  {dia(s.date)} · {s.time} · {FORMATOS[s.format] ?? s.format} · {s.pillar}
                </p>
                <p className="text-body-sm text-on-surface mt-1">{s.theme}</p>
                <p className="text-body-sm text-on-surface-variant mt-1">{s.rationale}</p>
              </li>
            ))}
          </ul>

          {p.contents_count > 0 ? (
            <p className="text-body-sm text-secondary mt-3">
              ✓ As {p.contents_count} peças deste plano já foram escritas — estão em Ideia.
            </p>
          ) : (
            <Button className="mt-3" disabled={generating} onClick={() => onWrite(p.id)}>
              Escrever as peças do plano ({p.distribution.slots.length})
            </Button>
          )}
        </div>
      )}
    </Card>
  )
}
