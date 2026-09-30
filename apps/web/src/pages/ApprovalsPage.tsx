import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { StructurePreview } from '@/components/content/StructurePreview'
import { InstagramPreview } from '@/components/instagram/InstagramPreview'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Shell } from '@/components/ui/Shell'
import { Textarea } from '@/components/ui/Textarea'
import { ApiError, api } from '@/lib/api'
import type { Content, ContentFormat, ContentHistory, EditorialState, Project } from '@/lib/types'

/**
 * CP-04 — a Central de Aprovação. A regra: nada é agendado nem publicado sem uma
 * pessoa aprovar a VERSÃO exata que está vendo aqui. Toda ação vai ao servidor com
 * essa versão; se a peça mudou no meio tempo, o servidor recusa (409) e a tela
 * recarrega a versão nova. O estado exibido vem do servidor — a tela não decide.
 */

/** O que espera uma decisão humana. */
const AGUARDANDO: EditorialState[] = ['pending_approval', 'in_review', 'needs_revision']

const FORMATOS: Record<ContentFormat, string> = {
  post: 'Feed',
  carousel: 'Carrossel',
  reel: 'Reels',
  story: 'Stories',
  video: 'Vídeo',
  article: 'Artigo',
  thread: 'Thread',
}

const ESTADOS: Record<EditorialState, string> = {
  draft: 'Rascunho',
  needs_revision: 'Precisa de ajuste',
  in_review: 'Em revisão',
  pending_approval: 'Aguardando aprovação humana',
  approved: 'Aprovada',
  rejected: 'Rejeitada',
  scheduled: 'Agendada',
  publishing: 'Publicando',
  published: 'Publicada',
  failed: 'Falha na publicação',
  cancelled: 'Cancelada',
  archived: 'Arquivada',
}

const DECISOES = { approved: 'Aprovou', rejected: 'Rejeitou', changes_requested: 'Pediu ajustes' }

function quando(iso: string | null | undefined): string | null {
  if (!iso) return null
  const d = new Date(iso)

  return `${d.toLocaleDateString('pt-BR')} às ${d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })}`
}

export function ApprovalsPage() {
  const { projectId } = useParams()

  const project = useQuery({
    queryKey: ['project', projectId],
    queryFn: () => api<{ data: Project }>(`/projects/${projectId}`),
  })

  // A mesma chave do quadro: decidir aqui atualiza o quadro, e vice-versa.
  const contents = useQuery({
    queryKey: ['contents', projectId],
    queryFn: () => api<{ data: Content[] }>(`/projects/${projectId}/contents`),
  })

  const pendentes = (contents.data?.data ?? []).filter(
    (c) => (c.status === 'review' || c.status === 'approved') && AGUARDANDO.includes(c.editorial_state ?? 'draft'),
  )

  return (
    <Shell>
      <Link
        to={`/projects/${projectId}/content`}
        className="text-body-sm text-on-surface-variant hover:text-primary"
      >
        ← Conteúdo
      </Link>

      <h1 className="text-headline-lg font-display text-on-surface mt-4">Central de Aprovação</h1>
      <p className="text-body-md text-on-surface-variant mt-2">
        {project.data?.data.name ?? 'Projeto'} · nada vai ao ar sem uma pessoa aprovar a versão exata da
        peça. A revisão da IA ajuda, mas não aprova.
      </p>

      {contents.isLoading && <p className="text-body-md text-on-surface-variant mt-6">Carregando…</p>}

      {contents.isSuccess && pendentes.length === 0 && (
        <p className="text-body-md text-on-surface-variant mt-6">Nenhuma peça aguardando decisão.</p>
      )}

      <div className="mt-6 flex flex-col gap-6">
        {pendentes.map((peca) => (
          <PecaParaDecidir key={peca.id} peca={peca} marca={project.data?.data.name ?? ''} projectId={projectId!} />
        ))}
      </div>
    </Shell>
  )
}

function PecaParaDecidir({ peca, marca, projectId }: { peca: Content; marca: string; projectId: string }) {
  const queryClient = useQueryClient()
  const [acao, setAcao] = useState<'reject' | 'request-changes' | null>(null)
  const [motivo, setMotivo] = useState('')
  const [verHistorico, setVerHistorico] = useState(false)

  const decidir = useMutation({
    mutationFn: ({ rota, reason }: { rota: 'approve' | 'reject' | 'request-changes'; reason?: string }) =>
      api(`/contents/${peca.id}/${rota}`, {
        method: 'POST',
        body: JSON.stringify({ version: peca.version ?? 1, ...(reason ? { reason } : {}) }),
      }),
    onSettled: () => queryClient.invalidateQueries({ queryKey: ['contents', projectId] }),
    onSuccess: () => {
      setAcao(null)
      setMotivo('')
    },
  })

  const regerar = useMutation({
    mutationFn: () => api(`/contents/${peca.id}/rewrite:generate`, { method: 'POST' }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['contents', projectId] }),
  })

  const erro = decidir.error instanceof ApiError ? decidir.error : null
  const review = peca.latest_review
  const imagem = peca.format === 'carousel' ? (peca.slides?.[0]?.url ?? null) : (peca.image?.url ?? null)
  const data = quando(peca.scheduled_for) ?? quando(peca.planned_for)

  return (
    <Card aria-label={`Peça ${peca.title}`}>
      <div className="flex flex-wrap items-center gap-2">
        <span className="text-label-sm bg-surface-container text-on-surface-variant rounded-full px-2 py-0.5">
          {FORMATOS[peca.format]}
        </span>
        <span className="text-label-sm bg-surface-container text-on-surface-variant rounded-full px-2 py-0.5">
          {ESTADOS[peca.editorial_state ?? 'draft']}
        </span>
        <span className="text-label-sm text-on-surface-variant">Versão {peca.version ?? 1}</span>
        {marca && <span className="text-label-sm text-on-surface-variant">· {marca}</span>}
      </div>

      <div className="mt-4 grid gap-6 md:grid-cols-2">
        <div className="min-w-0">
          <h2 className="text-headline-md text-on-surface">{peca.title}</h2>
          {peca.caption && <p className="text-body-md text-on-surface mt-2 whitespace-pre-line">{peca.caption}</p>}
          {peca.cta && <p className="text-body-md text-on-surface mt-2">CTA: {peca.cta}</p>}
          {peca.hashtags.length > 0 && (
            <p className="text-body-sm text-primary mt-2 break-words">{peca.hashtags.join(' ')}</p>
          )}
          <p className="text-body-sm text-on-surface-variant mt-2">
            {data ? `Data sugerida: ${data}` : 'Sem data sugerida'}
          </p>

          {peca.structure && <StructurePreview format={peca.format} structure={peca.structure} />}

          <div className="mt-3">
            <p className="text-label-md text-on-surface">Revisão automática (IA)</p>
            {review ? (
              <>
                <p className="text-body-sm text-on-surface-variant mt-1">
                  {review.verdict === 'pass' ? '✓ Sem violações' : `⚠ ${review.violations.length} violação(ões)`} —{' '}
                  {review.summary}
                </p>
                <ul className="mt-1 flex flex-col gap-1">
                  {review.violations.map((v, i) => (
                    <li key={i} className="text-body-sm text-error">
                      {v.rule}: “{v.excerpt}” → {v.suggestion}
                    </li>
                  ))}
                </ul>
              </>
            ) : (
              <p className="text-body-sm text-on-surface-variant mt-1">A IA ainda não revisou esta peça.</p>
            )}
            <p className="text-body-sm text-on-surface-variant mt-1">
              A revisão da IA é uma recomendação: a decisão é sua.
            </p>
          </div>
        </div>

        <div className="min-w-0">
          <InstagramPreview
            content={peca}
            imageUrl={imagem}
            username={null}
            videoUrl={peca.format === 'reel' ? (peca.video?.url ?? null) : undefined}
          />
          {!imagem && peca.format !== 'reel' && (
            <p className="text-body-sm text-on-surface-variant mt-2">Sem mídia ainda: a peça não publica sem ela.</p>
          )}
        </div>
      </div>

      {erro && (
        <p role="alert" className="text-body-sm text-error mt-4">
          {erro.status === 409
            ? `${erro.message422 ?? 'A peça mudou.'} A tela foi atualizada com a versão atual; confira antes de decidir.`
            : (erro.fieldError('reason') ?? erro.message422 ?? 'Não foi possível registrar a decisão.')}
        </p>
      )}

      {acao && (
        <div className="mt-4">
          <Textarea
            label={acao === 'reject' ? 'Motivo da rejeição' : 'O que precisa mudar'}
            value={motivo}
            onChange={(e) => setMotivo(e.target.value)}
            rows={3}
          />
          <div className="mt-2 flex flex-wrap gap-2">
            <Button
              disabled={decidir.isPending || motivo.trim().length < 3}
              onClick={() => decidir.mutate({ rota: acao, reason: motivo.trim() })}
            >
              {acao === 'reject' ? 'Confirmar rejeição' : 'Enviar pedido de ajuste'}
            </Button>
            <Button variant="ghost" onClick={() => setAcao(null)}>
              Voltar
            </Button>
          </div>
        </div>
      )}

      {!acao && (
        <div className="mt-4 flex flex-wrap gap-2">
          <Button disabled={decidir.isPending} onClick={() => decidir.mutate({ rota: 'approve' })}>
            Aprovar versão {peca.version ?? 1}
          </Button>
          <Button variant="secondary" disabled={decidir.isPending} onClick={() => setAcao('request-changes')}>
            Solicitar ajustes
          </Button>
          <Button variant="secondary" disabled={decidir.isPending} onClick={() => setAcao('reject')}>
            Rejeitar
          </Button>
          <Link
            to={`/projects/${projectId}/content?editar=${peca.id}`}
            className="text-label-md text-primary inline-flex items-center px-3 py-2 hover:underline"
          >
            Editar
          </Link>
          {review?.verdict === 'fail' && (
            <Button variant="ghost" disabled={regerar.isPending} onClick={() => regerar.mutate()}>
              Nova geração (reescrever)
            </Button>
          )}
          <Button variant="ghost" onClick={() => setVerHistorico((v) => !v)}>
            {verHistorico ? 'Ocultar histórico' : 'Histórico'}
          </Button>
        </div>
      )}

      {verHistorico && <Historico contentId={peca.id} />}
    </Card>
  )
}

function Historico({ contentId }: { contentId: number }) {
  const historico = useQuery({
    queryKey: ['content-history', contentId],
    queryFn: () => api<{ data: ContentHistory }>(`/contents/${contentId}/history`),
  })

  if (historico.isLoading) return <p className="text-body-sm text-on-surface-variant mt-4">Carregando histórico…</p>

  const h = historico.data?.data
  if (!h) return null

  return (
    <div className="border-outline-variant mt-4 rounded border p-3" aria-label="Histórico da peça">
      <p className="text-label-md text-on-surface">
        Histórico · versão atual {h.version} ·{' '}
        {h.approval_valid ? 'aprovação válida para esta versão' : 'sem aprovação válida para esta versão'}
      </p>
      <ul className="mt-2 flex flex-col gap-1">
        {h.decisions.map((d) => (
          <li key={`d${d.id}`} className="text-body-sm text-on-surface">
            {quando(d.at)} · {d.user?.name ?? 'alguém'} · {DECISOES[d.decision]} a versão {d.version}
            {d.reason && <span className="text-on-surface-variant"> — “{d.reason}”</span>}
          </li>
        ))}
        {h.revisions.map((r, i) => (
          <li key={`r${i}`} className="text-body-sm text-on-surface-variant">
            {quando(r.at)} ·{' '}
            {r.type === 'change' ? `alterou ${r.fields.join(', ')}` : `moveu de ${r.from_status} para ${r.to_status}`}
          </li>
        ))}
      </ul>
    </div>
  )
}
