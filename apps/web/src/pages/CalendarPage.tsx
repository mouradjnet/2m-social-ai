import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Input } from '@/components/ui/Input'
import { Shell } from '@/components/ui/Shell'
import { api } from '@/lib/api'
import { buildMonth, sameLocalDay, shiftMonth } from '@/lib/calendarGrid'
import { cn } from '@/lib/cn'
import type { Content } from '@/lib/types'

const WEEKDAYS = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb']

/** Valor que o <input type="datetime-local"> entende: local, sem fuso, sem segundos. */
function toLocalInput(iso: string): string {
  const d = new Date(iso)
  const pad = (n: number) => String(n).padStart(2, '0')

  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

function hour(iso: string): string {
  return new Date(iso).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })
}

export function CalendarPage() {
  const { projectId } = useParams()
  const queryClient = useQueryClient()
  const [month, setMonth] = useState(() => shiftMonth(new Date(), 0))
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [when, setWhen] = useState('')

  const contents = useQuery({
    queryKey: ['contents', projectId],
    queryFn: () => api<{ data: Content[] }>(`/projects/${projectId}/contents`),
  })

  // A mesma query da ContentPage: remarcar aqui atualiza o board tambem.
  const patch = useMutation({
    mutationFn: ({ id, body }: { id: number; body: Record<string, string> }) =>
      api(`/contents/${id}`, { method: 'PATCH', body: JSON.stringify(body) }),
    onSuccess: () => {
      setSelectedId(null)
      return queryClient.invalidateQueries({ queryKey: ['contents', projectId] })
    },
  })

  if (contents.isPending) return <Shell>Carregando…</Shell>
  if (contents.isError) return <Shell>Projeto não encontrado.</Shell>

  const pieces = contents.data.data
  const weeks = buildMonth(month, pieces)
  const agendadas = pieces.filter((p) => p.scheduled_for !== null).length
  const selected = pieces.find((p) => p.id === selectedId) ?? null

  const select = (content: Content) => {
    setSelectedId(content.id)
    setWhen(toLocalInput(content.scheduled_for as string))
  }

  return (
    <Shell>
      <Link
        to={`/projects/${projectId}/content`}
        className="text-body-sm text-on-surface-variant hover:text-primary"
      >
        ← Conteúdo
      </Link>

      <div className="mt-4 flex items-center justify-between gap-4">
        <h1 className="text-display-lg text-on-surface">Calendário</h1>

        <div className="flex items-center gap-2">
          <Button size="sm" variant="secondary" onClick={() => setMonth(shiftMonth(month, -1))}>
            ‹ Mês anterior
          </Button>
          <span className="text-label-md text-on-surface w-44 text-center">
            {month.toLocaleDateString('pt-BR', { month: 'long', year: 'numeric' })}
          </span>
          <Button size="sm" variant="secondary" onClick={() => setMonth(shiftMonth(month, 1))}>
            Próximo mês ›
          </Button>
        </div>
      </div>

      {agendadas === 0 && (
        <p className="text-body-lg text-on-surface-variant mt-6">
          Nenhuma peça agendada ainda. Aprove peças e use “Agendar aprovadas” no Conteúdo.
        </p>
      )}

      <div className="mt-6 grid grid-cols-7 gap-px">
        {WEEKDAYS.map((d) => (
          <div key={d} className="text-label-sm text-on-surface-variant pb-2 text-center">
            {d}
          </div>
        ))}

        {weeks.flat().map((day) => (
          <div
            key={day.date.toISOString()}
            className={cn(
              'bg-surface-container-lowest border-outline-variant min-h-24 border p-1',
              !day.inMonth && 'opacity-40',
            )}
          >
            <span
              className={cn(
                'text-label-sm',
                sameLocalDay(day.date, new Date())
                  ? 'bg-primary text-on-primary rounded-full px-1.5'
                  : 'text-on-surface-variant',
              )}
            >
              {day.date.getDate()}
            </span>

            <div className="mt-1 flex flex-col gap-1">
              {day.contents.map((content) => (
                <button
                  key={content.id}
                  type="button"
                  onClick={() => select(content)}
                  className={cn(
                    'text-label-sm truncate rounded px-1 py-0.5 text-left',
                    content.id === selectedId
                      ? 'bg-primary text-on-primary'
                      : 'bg-secondary-container text-secondary',
                  )}
                >
                  {hour(content.scheduled_for as string)} {content.title}
                </button>
              ))}
            </div>
          </div>
        ))}
      </div>

      {selected && (
        <Card className="mt-6">
          <h2 className="text-label-md text-on-surface">{selected.title}</h2>
          <p className="text-body-sm text-on-surface-variant mt-1">
            {selected.format} · {selected.channel}
          </p>

          <div className="mt-4 flex flex-wrap items-end gap-3">
            <Input
              label="Nova data"
              type="datetime-local"
              value={when}
              onChange={(e) => setWhen(e.target.value)}
            />

            <Button
              disabled={patch.isPending || when === ''}
              onClick={() => patch.mutate({ id: selected.id, body: { scheduled_for: when } })}
            >
              Salvar
            </Button>

            <Button
              variant="secondary"
              disabled={patch.isPending}
              onClick={() => patch.mutate({ id: selected.id, body: { status: 'approved' } })}
            >
              Desagendar
            </Button>
          </div>
        </Card>
      )}
    </Shell>
  )
}
