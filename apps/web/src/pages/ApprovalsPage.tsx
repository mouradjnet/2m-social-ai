import { type QueryClient, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { StructurePreview } from '@/components/content/StructurePreview'
import { VersionHistory } from '@/components/content/VersionHistory'
import { InstagramPreview } from '@/components/instagram/InstagramPreview'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Shell } from '@/components/ui/Shell'
import { Textarea } from '@/components/ui/Textarea'
import { ApiError, api } from '@/lib/api'
import { podeAprovar, podeEditar } from '@/lib/roles'
import type { Content, ContentFormat, EditorialState, Me, Project } from '@/lib/types'

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

  // O papel neste workspace, so para mostrar ou esconder os botoes de decisao. A
  // autorizacao de verdade e a policy `approve` no servidor.
  const me = useQuery({ queryKey: ['me'], queryFn: () => api<Me>('/me') })
  const papel = me.data?.workspaces.find((w) => w.id === project.data?.data.workspace_id)?.role
  const [aviso, setAviso] = useState<string | null>(null)

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

      {aviso && (
        <p role="status" className="text-body-md bg-secondary-container text-secondary mt-4 rounded p-3">
          {aviso}
        </p>
      )}

      {contents.isLoading && <p className="text-body-md text-on-surface-variant mt-6">Carregando…</p>}

      {contents.isSuccess && pendentes.length === 0 && (
        <p className="text-body-md text-on-surface-variant mt-6">Nenhuma peça aguardando decisão.</p>
      )}

      <div className="mt-6 flex flex-col gap-6">
        {pendentes.map((peca) => (
          <PecaParaDecidir
            key={peca.id}
            peca={peca}
            marca={project.data?.data.name ?? ''}
            projectId={projectId!}
            podeDecidir={podeAprovar(papel)}
            podeRestaurar={podeEditar(papel)}
            onAviso={setAviso}
          />
        ))}
      </div>
    </Shell>
  )
}

interface Props {
  peca: Content
  marca: string
  projectId: string
  /** O papel do usuário permite decidir (espelho da policy `approve`; o servidor confere). */
  podeDecidir: boolean
  /** CP-04C: restaurar versão (espelho da policy `update`, editor+). */
  podeRestaurar: boolean
  onAviso: (texto: string) => void
}

interface RespostaAprovacao {
  decision: { version: number; user: { id: number; name: string } | null }
  replayed: boolean
}

function PecaParaDecidir({ peca, marca, projectId, podeDecidir, podeRestaurar, onAviso }: Props) {
  const queryClient = useQueryClient()
  const [acao, setAcao] = useState<'reject' | 'request-changes' | null>(null)
  // CP-04B: a mesma idempotencia da aprovacao — uma chave por intencao de decidir.
  const [chaveAcao, setChaveAcao] = useState<string | null>(null)
  const abrir = (qual: 'reject' | 'request-changes') => {
    setAcao(qual)
    setChaveAcao(crypto.randomUUID())
  }
  const [motivo, setMotivo] = useState('')
  const [verHistorico, setVerHistorico] = useState(false)
  // CP-04A: a chave nasce quando a pessoa abre a confirmação e vale para ESTA
  // intenção. Nova tentativa depois de falha de rede reusa a mesma (o servidor devolve
  // o resultado original); depois de um 409 a peça mudou, e a chave é descartada.
  const [chave, setChave] = useState<string | null>(null)

  const versao = peca.version ?? 1

  const aprovar = useMutation({
    mutationFn: (requestKey: string) =>
      api<RespostaAprovacao>(`/contents/${peca.id}/approve`, {
        method: 'POST',
        body: JSON.stringify({ expected_version: versao, request_key: requestKey }),
      }),
    onSuccess: (r) => {
      setChave(null)
      onAviso(
        r.replayed
          ? `A versão ${r.decision.version} de “${peca.title}” já estava aprovada por ${r.decision.user?.name ?? 'alguém'} — resultado original, nada novo foi gravado.`
          : `Versão ${r.decision.version} de “${peca.title}” aprovada por ${r.decision.user?.name ?? 'você'}.`,
      )
    },
    onError: (e) => {
      if (e instanceof ApiError && (e.status === 409 || e.status === 422)) setChave(null)
    },
    // Atualiza só depois da resposta: nada de aprovação otimista.
    onSettled: () => queryClient.invalidateQueries({ queryKey: ['contents', projectId] }),
  })

  const decidir = useMutation({
    mutationFn: ({ rota, reason, requestKey }: { rota: 'reject' | 'request-changes'; reason: string; requestKey: string }) =>
      api<RespostaAprovacao>(`/contents/${peca.id}/${rota}`, {
        method: 'POST',
        body: JSON.stringify({ expected_version: versao, reason, request_key: requestKey }),
      }),
    // Atualiza so depois da resposta do servidor.
    onSettled: () => queryClient.invalidateQueries({ queryKey: ['contents', projectId] }),
    onSuccess: (r, v) => {
      setAcao(null)
      setChaveAcao(null)
      setMotivo('')
      const quem = r.decision.user?.name ?? 'você'
      onAviso(
        v.rota === 'reject'
          ? `“${peca.title}” rejeitada por ${quem} (versão ${r.decision.version}). Para reaproveitá-la, ela recomeça em produção.`
          : `Ajustes pedidos por ${quem} em “${peca.title}” (versão ${r.decision.version}). A peça voltou para produção.`,
      )
    },
    onError: (e) => {
      // A peca mudou (409) ou a chave nao serve mais (422): nova intencao, nova chave.
      if (e instanceof ApiError && (e.status === 409 || e.status === 422)) setChaveAcao(crypto.randomUUID())
    },
  })

  const erroDe = (e: unknown) => (e instanceof ApiError ? e : null)
  const erro = erroDe(aprovar.error) ?? erroDe(decidir.error)
  const review = peca.latest_review
  const imagem = peca.format === 'carousel' ? (peca.slides?.[0]?.url ?? null) : (peca.image?.url ?? null)
  const data = quando(peca.scheduled_for) ?? quando(peca.planned_for)
  const ocupado = aprovar.isPending || decidir.isPending

  return (
    <Card aria-label={`Peça ${peca.title}`}>
      <div className="flex flex-wrap items-center gap-2">
        <span className="text-label-sm bg-surface-container text-on-surface-variant rounded-full px-2 py-0.5">
          {FORMATOS[peca.format]}
        </span>
        <span className="text-label-sm bg-surface-container text-on-surface-variant rounded-full px-2 py-0.5">
          {ESTADOS[peca.editorial_state ?? 'draft']}
        </span>
        <span className="text-label-sm text-on-surface-variant">Versão {versao}</span>
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

          {/* O carrossel inteiro, na ordem em que vai ao ar. */}
          {peca.format === 'carousel' && (peca.slides?.length ?? 0) > 0 && (
            <ol className="mt-3 flex gap-2 overflow-x-auto" aria-label="Slides do carrossel">
              {peca.slides!.map((slide, i) => (
                <li key={slide.id} className="shrink-0">
                  <img src={slide.url} alt={`Slide ${i + 1}`} className="h-20 w-20 rounded object-cover" />
                  <span className="text-label-sm text-on-surface-variant">{i + 1}</span>
                </li>
              ))}
            </ol>
          )}

          {!imagem && peca.format !== 'reel' && (
            <p className="text-body-sm text-on-surface-variant mt-2">Sem mídia ainda: a peça não publica sem ela.</p>
          )}
        </div>
      </div>

      {erro && (
        <p role="alert" className="text-body-sm text-error mt-4">
          {erro.status === 409
            ? `${erro.message422 ?? 'A peça mudou.'} A tela foi atualizada; confira antes de decidir.`
            : (erro.fieldError('reason') ?? erro.fieldError('request_key') ?? erro.message422 ?? 'Não foi possível registrar a decisão.')}
        </p>
      )}

      {!podeDecidir && (
        <p className="text-body-sm text-on-surface-variant mt-4">
          Aguardando um revisor: você pode editar a peça, mas aprovar e rejeitar é de quem revisa.
        </p>
      )}

      {podeDecidir && chave && (
        <div role="dialog" aria-label="Confirmar aprovação" className="border-outline-variant mt-4 rounded border p-3">
          <p className="text-body-md text-on-surface">
            Aprovar a versão {versao} de “{peca.title}”? A aprovação vale só para esta versão: qualquer mudança
            depois a derruba.
          </p>
          <div className="mt-3 flex flex-wrap gap-2">
            <Button disabled={ocupado} onClick={() => aprovar.mutate(chave)}>
              {aprovar.isPending ? 'Aprovando…' : 'Confirmar aprovação'}
            </Button>
            <Button variant="ghost" disabled={ocupado} onClick={() => setChave(null)}>
              Cancelar
            </Button>
          </div>
        </div>
      )}

      {podeDecidir && acao && (
        <div className="mt-4">
          {acao === 'reject' && (
            <p className="text-body-sm text-error mb-2">
              Rejeitar tira a versão {versao} do fluxo. Ela não volta a ser aprovada: para reaproveitá-la, recomeça em
              produção e passa de novo por revisão e aprovação.
            </p>
          )}
          <Textarea
            label={acao === 'reject' ? 'Motivo da rejeição' : 'O que precisa mudar'}
            value={motivo}
            onChange={(e) => setMotivo(e.target.value)}
            rows={3}
          />
          <div className="mt-2 flex flex-wrap gap-2">
            <Button
              disabled={ocupado || motivo.trim().length < 3 || !chaveAcao}
              onClick={() => chaveAcao && decidir.mutate({ rota: acao, reason: motivo.trim(), requestKey: chaveAcao })}
            >
              {acao === 'reject' ? 'Confirmar rejeição' : 'Enviar pedido de ajuste'}
            </Button>
            <Button
              variant="ghost"
              onClick={() => {
                setAcao(null)
                setChaveAcao(null)
              }}
            >
              Voltar
            </Button>
          </div>
        </div>
      )}

      {!acao && !chave && (
        <div className="mt-4 flex flex-wrap gap-2">
          {podeDecidir && (
            <>
              <Button
                disabled={ocupado || peca.editorial_state !== 'pending_approval'}
                onClick={() => setChave(crypto.randomUUID())}
              >
                Aprovar versão {versao}
              </Button>
              <Button variant="secondary" disabled={ocupado} onClick={() => abrir('request-changes')}>
                Solicitar ajustes
              </Button>
              <Button variant="secondary" disabled={ocupado} onClick={() => abrir('reject')}>
                Rejeitar
              </Button>
            </>
          )}
          <Link
            to={`/projects/${projectId}/content?editar=${peca.id}`}
            className="text-label-md text-primary inline-flex items-center px-3 py-2 hover:underline"
          >
            Editar
          </Link>
          {review?.verdict === 'fail' && (
            <Button variant="ghost" onClick={() => regenerar(queryClient, peca.id, peca.version, projectId)}>
              Nova geração (reescrever)
            </Button>
          )}
          <Button variant="ghost" onClick={() => setVerHistorico((v) => !v)}>
            {verHistorico ? 'Ocultar histórico' : 'Histórico'}
          </Button>
        </div>
      )}

      {podeDecidir && peca.editorial_state !== 'pending_approval' && !chave && !acao && (
        <p className="text-body-sm text-on-surface-variant mt-2">
          Para aprovar, a IA precisa revisar e aprovar esta versão (a peça está em “
          {ESTADOS[peca.editorial_state ?? 'draft']}”).
        </p>
      )}

      {verHistorico && <VersionHistory contentId={peca.id} projectId={projectId} podeRestaurar={podeRestaurar} />}
    </Card>
  )
}

async function regenerar(queryClient: QueryClient, id: number, version: number | undefined, projectId: string) {
  await api(`/contents/${id}/rewrite:generate`, {
    method: 'POST',
    body: JSON.stringify({ expected_version: version }),
  })
  await queryClient.invalidateQueries({ queryKey: ['contents', projectId] })
}
