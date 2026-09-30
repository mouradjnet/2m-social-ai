import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Button } from '@/components/ui/Button'
import { api, errorMessage } from '@/lib/api'
import type {
  ContentHistory,
  ContentVersionList,
  EditorialEvent,
  MediaMeta,
  VersionComparison,
} from '@/lib/types'

/**
 * CP-04C — histórico editorial e versões de uma peça. Só leitura das versões
 * antigas: não há edição de versão histórica. Restaurar cria uma versão NOVA no
 * servidor, que volta a revisão e aprovação (a aprovação antiga nunca vale para ela).
 */

const EVENTOS: Record<EditorialEvent['type'], string> = {
  created: 'Criou a peça',
  manual_edit: 'Editou',
  ai_regeneration: 'IA gerou nova versão',
  seo_applied: 'Aplicou o SEO',
  restored: 'Restaurou',
  version_recorded: 'Versão registrada',
  changed: 'Alterou',
  approval_invalidated: 'A aprovação anterior deixou de valer',
  approved: 'Aprovou',
  rejected: 'Rejeitou',
  changes_requested: 'Pediu ajustes',
  sent_to_review: 'Enviou para revisão',
  scheduled: 'Agendou',
  cancelled: 'Cancelou o agendamento',
  archived: 'Arquivou',
  unarchived: 'Desarquivou',
  published: 'Publicou',
  status_changed: 'Mudou o status',
}

const HUMANAS: EditorialEvent['type'][] = ['approved', 'rejected', 'changes_requested']

const ORIGENS: Record<string, string> = {
  created: 'criação',
  manual_edit: 'edição manual',
  ai_generation: 'IA',
  ai_rewrite: 'reescrita da IA',
  ai_image: 'imagem da IA',
  seo: 'SEO',
  restore: 'restauração',
  backfill: 'registro inicial',
  system: 'sistema',
}

const CAMPOS: Record<string, string> = {
  title: 'Título',
  caption: 'Legenda',
  cta: 'CTA',
  hashtags: 'Hashtags',
  structure: 'Roteiro / estrutura',
  format: 'Formato',
  channel: 'Canal',
  published_caption: 'Legenda final (como vai ao ar)',
  image: 'Imagem',
  video: 'Vídeo',
  slides: 'Slides',
}

function quando(iso: string): string {
  const d = new Date(iso)
  return `${d.toLocaleDateString('pt-BR')} às ${d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })}`
}

function midia(m: MediaMeta | null | undefined): string {
  if (!m) return '—'
  if (m.missing) return `#${m.id} (removida da biblioteca)`
  const dim = m.width && m.height ? ` · ${m.width}×${m.height}` : ''
  const hash = m.checksum ? ` · checksum ${m.checksum.slice(0, 10)}…` : ' · sem checksum'
  return `#${m.id} ${m.original_name ?? ''}${dim}${hash}`
}

function valor(campo: string, v: unknown): string {
  if (v === null || v === undefined || v === '') return '—'
  if (campo === 'image' || campo === 'video') return midia(v as MediaMeta)
  if (campo === 'slides') return (v as MediaMeta[]).map((m, i) => `${i + 1}. ${midia(m)}`).join('\n') || '—'
  if (Array.isArray(v)) return v.join(' ')
  if (typeof v === 'object') return JSON.stringify(v, null, 2)
  return String(v)
}

interface Props {
  contentId: number
  /** Espelho da policy `update` (editor+). Quem decide de verdade é o servidor. */
  podeRestaurar: boolean
  projectId: string
}

export function VersionHistory({ contentId, podeRestaurar, projectId }: Props) {
  const queryClient = useQueryClient()
  const historico = useQuery({
    queryKey: ['content-history', contentId],
    queryFn: () => api<{ data: ContentHistory }>(`/contents/${contentId}/history`),
  })
  const versoes = useQuery({
    queryKey: ['content-versions', contentId],
    queryFn: () => api<{ data: ContentVersionList }>(`/contents/${contentId}/versions`),
  })

  const lista = versoes.data?.data
  const atual = lista?.current_version ?? 0
  const [de, setDe] = useState<number | null>(null)
  const [para, setPara] = useState<number | null>(null)
  const deEfetivo = de ?? Math.max(1, atual - 1)
  const paraEfetivo = para ?? atual
  const [confirmar, setConfirmar] = useState<number | null>(null)
  const [aviso, setAviso] = useState<{ tipo: 'ok' | 'erro'; texto: string } | null>(null)

  const comparacao = useQuery({
    queryKey: ['content-compare', contentId, deEfetivo, paraEfetivo],
    queryFn: () =>
      api<{ data: VersionComparison }>(`/contents/${contentId}/versions/compare?from=${deEfetivo}&to=${paraEfetivo}`),
    enabled: atual > 0 && deEfetivo !== paraEfetivo,
  })

  const restaurar = useMutation({
    mutationFn: (versao: number) =>
      api<{ message: string }>(`/contents/${contentId}/versions/${versao}/restore`, {
        method: 'POST',
        body: JSON.stringify({ expected_version: atual }),
      }),
    onSuccess: async (r) => {
      setAviso({ tipo: 'ok', texto: r.message })
      setConfirmar(null)
      setDe(null)
      setPara(null)
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['contents', projectId] }),
        queryClient.invalidateQueries({ queryKey: ['content-history', contentId] }),
        queryClient.invalidateQueries({ queryKey: ['content-versions', contentId] }),
      ])
    },
    onError: async (e) => {
      setAviso({ tipo: 'erro', texto: errorMessage(e, 'Não foi possível restaurar. Recarregue e tente de novo.') })
      setConfirmar(null)
      await queryClient.invalidateQueries({ queryKey: ['content-versions', contentId] })
    },
  })

  if (historico.isLoading || versoes.isLoading) {
    return <p className="text-body-sm text-on-surface-variant mt-4">Carregando histórico…</p>
  }

  const h = historico.data?.data
  if (!h || !lista) return null

  return (
    <div className="border-outline-variant mt-4 flex flex-col gap-4 rounded border p-3" aria-label="Histórico da peça">
      <p className="text-label-md text-on-surface">
        Versão atual {lista.current_version} ·{' '}
        {lista.approved_version
          ? `aprovada: versão ${lista.approved_version}`
          : 'sem aprovação válida para esta versão'}
      </p>

      {aviso && (
        <p role="status" className={`text-body-sm ${aviso.tipo === 'ok' ? 'text-primary' : 'text-error'}`}>
          {aviso.texto}
        </p>
      )}

      <section aria-label="Linha do tempo">
        <h4 className="text-label-md text-on-surface">Linha do tempo</h4>
        <ul className="mt-2 flex flex-col gap-1">
          {(h.events ?? []).map((e, i) => (
            <li
              key={i}
              className={`text-body-sm ${HUMANAS.includes(e.type) ? 'text-on-surface font-medium' : 'text-on-surface-variant'}`}
            >
              {quando(e.at)} · {e.user?.name ?? 'sistema'} · {EVENTOS[e.type]}
              {e.version !== undefined && ` · versão ${e.version}`}
              {e.restored_from_version ? ` (conteúdo da versão ${e.restored_from_version})` : ''}
              {HUMANAS.includes(e.type) && ' · decisão humana'}
              {e.reason && <span> — “{e.reason}”</span>}
            </li>
          ))}
        </ul>
      </section>

      <section aria-label="Versões">
        <h4 className="text-label-md text-on-surface">Versões</h4>
        <ul className="mt-2 flex flex-col gap-2">
          {[...lista.versions].reverse().map((v) => (
            <li key={v.version} className="border-outline-variant flex flex-wrap items-center gap-2 rounded border p-2">
              <span className="text-body-sm text-on-surface font-medium">Versão {v.version}</span>
              {v.version === lista.current_version && <span className="text-label-sm text-primary">atual</span>}
              {v.is_approved && <span className="text-label-sm text-primary">aprovada</span>}
              {/* No celular, a descrição ocupa a linha inteira (ao lado do botão, espremia). */}
              <span className="text-body-sm text-on-surface-variant w-full break-words sm:w-auto sm:min-w-0 sm:flex-1">
                {ORIGENS[v.origin] ?? v.origin} · {v.user?.name ?? 'sistema'} · {quando(v.at)}
                {v.restored_from_version ? ` · da versão ${v.restored_from_version}` : ''}
              </span>
              {podeRestaurar && v.version !== lista.current_version && confirmar !== v.version && (
                <Button variant="ghost" onClick={() => setConfirmar(v.version)}>
                  Restaurar
                </Button>
              )}
              {confirmar === v.version && (
                <span className="flex w-full flex-wrap items-center gap-2">
                  <span className="text-body-sm text-on-surface">
                    Restaurar o conteúdo da versão {v.version} como versão {lista.current_version + 1}? Ela volta para
                    revisão e aprovação.
                  </span>
                  <Button onClick={() => restaurar.mutate(v.version)} disabled={restaurar.isPending}>
                    Confirmar restauração
                  </Button>
                  <Button variant="ghost" onClick={() => setConfirmar(null)}>
                    Cancelar
                  </Button>
                </span>
              )}
            </li>
          ))}
        </ul>
      </section>

      {lista.versions.length > 1 && (
        <section aria-label="Comparar versões">
          <h4 className="text-label-md text-on-surface">Comparar</h4>
          <div className="mt-2 flex flex-wrap items-center gap-2">
            <label className="text-body-sm text-on-surface flex items-center gap-1">
              De
              <select
                aria-label="Versão de origem"
                className="border-outline-variant rounded border px-2 py-1"
                value={deEfetivo}
                onChange={(e) => setDe(Number(e.target.value))}
              >
                {lista.versions.map((v) => (
                  <option key={v.version} value={v.version}>
                    {v.version}
                  </option>
                ))}
              </select>
            </label>
            <label className="text-body-sm text-on-surface flex items-center gap-1">
              para
              <select
                aria-label="Versão de destino"
                className="border-outline-variant rounded border px-2 py-1"
                value={paraEfetivo}
                onChange={(e) => setPara(Number(e.target.value))}
              >
                {lista.versions.map((v) => (
                  <option key={v.version} value={v.version}>
                    {v.version}
                  </option>
                ))}
              </select>
            </label>
          </div>

          {deEfetivo === paraEfetivo && (
            <p className="text-body-sm text-on-surface-variant mt-2">Escolha duas versões diferentes.</p>
          )}

          {comparacao.data && (
            <dl className="mt-2 flex flex-col gap-2">
              {comparacao.data.data.fields
                .filter((f) => f.changed)
                .map((f) => (
                  <div key={f.field} className="border-outline-variant rounded border p-2">
                    <dt className="text-label-md text-on-surface">{CAMPOS[f.field] ?? f.field}</dt>
                    <dd className="mt-1 grid grid-cols-1 gap-2 sm:grid-cols-2">
                      <pre className="text-body-sm text-on-surface-variant bg-surface-container-low rounded p-2 break-words whitespace-pre-wrap">
                        <span className="sr-only">Antes: </span>
                        {valor(f.field, f.from)}
                      </pre>
                      <pre className="text-body-sm text-on-surface bg-surface-container-low rounded p-2 break-words whitespace-pre-wrap">
                        <span className="sr-only">Depois: </span>
                        {valor(f.field, f.to)}
                      </pre>
                    </dd>
                    {f.note && <p className="text-body-sm text-on-surface-variant mt-1">{f.note}</p>}
                  </div>
                ))}
              {comparacao.data.data.fields.every((f) => !f.changed) && (
                <p className="text-body-sm text-on-surface-variant">Nenhum campo mudou entre essas versões.</p>
              )}
            </dl>
          )}
        </section>
      )}
    </div>
  )
}
