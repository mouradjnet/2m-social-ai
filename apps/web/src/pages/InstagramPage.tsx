import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { PublicationBadge } from '@/components/instagram/PublicationBadge'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Shell } from '@/components/ui/Shell'
import { api, errorMessage } from '@/lib/api'
import { describeActivity, formatDateTime } from '@/lib/instagram'
import { navegar } from '@/lib/navegar'
import type { ActivityEntry, InstagramAccount, Publication } from '@/lib/types'

/**
 * A conexao do projeto com o Instagram e o historico do que o sistema publicou.
 *
 * Conectar sai do app: a Meta mostra o consentimento e devolve o navegador ao
 * callback, que redireciona para ca com `?instagram=conectado|erro&motivo=...`.
 */
export function InstagramPage() {
  const { projectId } = useParams()
  const queryClient = useQueryClient()
  const [params, setParams] = useSearchParams()
  const [desconectando, setDesconectando] = useState(false)

  const conta = useQuery({
    queryKey: ['instagram', projectId],
    queryFn: () => api<{ data: InstagramAccount | null }>(`/projects/${projectId}/instagram`),
  })

  const historico = useQuery({
    queryKey: ['publications', projectId],
    queryFn: () => api<{ data: Publication[] }>(`/projects/${projectId}/publications`),
  })

  const atividade = useQuery({
    queryKey: ['activity', projectId],
    queryFn: () => api<{ data: ActivityEntry[] }>(`/projects/${projectId}/activity`),
  })

  const conectar = useMutation({
    mutationFn: () =>
      api<{ authorize_url: string }>(`/projects/${projectId}/instagram:connect`, { method: 'POST' }),
    onSuccess: ({ authorize_url }) => navegar.para(authorize_url),
  })

  const desconectar = useMutation({
    mutationFn: () => api(`/projects/${projectId}/instagram`, { method: 'DELETE' }),
    onSuccess: () => {
      setDesconectando(false)
      return Promise.all([
        queryClient.invalidateQueries({ queryKey: ['instagram', projectId] }),
        queryClient.invalidateQueries({ queryKey: ['activity', projectId] }),
      ])
    },
  })

  const refresh = () =>
    Promise.all([
      queryClient.invalidateQueries({ queryKey: ['publications', projectId] }),
      queryClient.invalidateQueries({ queryKey: ['contents', projectId] }),
      queryClient.invalidateQueries({ queryKey: ['activity', projectId] }),
    ])

  const retry = useMutation({
    mutationFn: (id: number) => api(`/publications/${id}/retry`, { method: 'POST' }),
    onSuccess: refresh,
  })

  const resolve = useMutation({
    mutationFn: ({ id, outcome }: { id: number; outcome: 'published' | 'failed' }) =>
      api(`/publications/${id}/resolve`, { method: 'POST', body: JSON.stringify({ outcome }) }),
    onSuccess: refresh,
  })

  if (conta.isPending) return <Shell>Carregando…</Shell>
  if (conta.isError) return <Shell>Projeto não encontrado.</Shell>

  const account = conta.data.data
  const resultado = params.get('instagram')
  const motivo = params.get('motivo')
  const publicacoes = historico.data?.data ?? []
  // A mais recente de cada peca: so ela pode ser tentada de novo.
  const maisRecente = new Map<number, number>()
  for (const p of publicacoes) {
    if (!maisRecente.has(p.content_id) || p.id > (maisRecente.get(p.content_id) ?? 0)) {
      maisRecente.set(p.content_id, p.id)
    }
  }
  const contar = (status: Publication['status']) => publicacoes.filter((p) => p.status === status).length
  const erroDeAcao = conectar.error ?? desconectar.error ?? retry.error ?? resolve.error

  return (
    <Shell>
      <Link to={`/projects/${projectId}/content`} className="text-body-sm text-on-surface-variant hover:text-primary">
        ← Conteúdo
      </Link>

      <h1 className="text-display-lg text-on-surface mt-4">Instagram</h1>

      {resultado && (
        <div
          role="status"
          className={
            resultado === 'conectado'
              ? 'bg-secondary-container text-secondary text-body-sm mt-4 rounded-card px-4 py-3'
              : 'bg-error-container text-error text-body-sm mt-4 rounded-card px-4 py-3'
          }
        >
          {resultado === 'conectado' ? `Conta ${motivo ?? ''} conectada.` : motivo ?? 'Não foi possível conectar.'}{' '}
          <button type="button" className="underline" onClick={() => setParams({})}>
            Fechar
          </button>
        </div>
      )}

      {erroDeAcao && (
        <p role="alert" className="text-body-sm text-error mt-4">
          {errorMessage(erroDeAcao)}
        </p>
      )}

      <Card className="mt-6">
        {account === null ? (
          <>
            <h2 className="text-headline-md font-display text-on-surface">Nenhuma conta conectada</h2>
            <p className="text-body-sm text-on-surface-variant mt-2">
              Conecte a conta profissional (Empresa ou Criador de conteúdo) para onde este projeto publica. A
              autorização é feita no próprio Instagram — a senha nunca passa por aqui.
            </p>
            <Button className="mt-4" disabled={conectar.isPending} onClick={() => conectar.mutate()}>
              Conectar Instagram
            </Button>
          </>
        ) : (
          <>
            <div className="flex flex-wrap items-center justify-between gap-4">
              <div>
                <h2 className="text-headline-md font-display text-on-surface">@{account.username}</h2>
                <p className="text-body-sm text-on-surface-variant mt-1">
                  {account.status === 'active' ? 'Conectada' : 'Conexão vencida'}
                  {account.connector && ` por ${account.connector.name}`} em {formatDateTime(account.connected_at)}
                </p>
                {account.status === 'active' && account.expires_in_days !== null && (
                  <p
                    className={
                      account.expires_in_days < 7
                        ? 'text-body-sm text-error mt-1'
                        : 'text-body-sm text-on-surface-variant mt-1'
                    }
                  >
                    A autorização é renovada sozinha; vence em {account.expires_in_days}{' '}
                    {account.expires_in_days === 1 ? 'dia' : 'dias'} se a renovação falhar.
                  </p>
                )}
                {account.last_error && <p className="text-body-sm text-error mt-1">{account.last_error}</p>}
                {account.status === 'active' && account.insights_enabled === false && (
                  <p className="text-body-sm text-on-surface-variant mt-1">
                    Métricas desligadas: a permissão de insights não foi autorizada. Reconecte para ver os Resultados.
                  </p>
                )}
              </div>

              <div className="flex gap-2">
                {(account.status !== 'active' || account.insights_enabled === false) && (
                  <Button disabled={conectar.isPending} onClick={() => conectar.mutate()}>
                    {account.status !== 'active' ? 'Reconectar' : 'Reconectar com métricas'}
                  </Button>
                )}

                {desconectando ? (
                  <>
                    <Button variant="secondary" disabled={desconectar.isPending} onClick={() => desconectar.mutate()}>
                      Confirmar desconexão
                    </Button>
                    <Button variant="ghost" onClick={() => setDesconectando(false)}>
                      Cancelar
                    </Button>
                  </>
                ) : (
                  <Button variant="ghost" onClick={() => setDesconectando(true)}>
                    Desconectar
                  </Button>
                )}
              </div>
            </div>
          </>
        )}
      </Card>

      <div className="mt-10 flex flex-wrap items-baseline justify-between gap-4">
        <h2 className="text-headline-md font-display text-on-surface">Publicações</h2>
        <p className="text-body-sm text-on-surface-variant" aria-label="Indicadores">
          {contar('published')} publicadas · {contar('failed')} com falha · {contar('pending') + contar('publishing')}{' '}
          na fila · {contar('unknown')} aguardando confirmação
        </p>
      </div>

      {historico.isPending ? (
        <p className="text-body-sm text-on-surface-variant mt-4">Carregando histórico…</p>
      ) : publicacoes.length === 0 ? (
        <p className="text-body-sm text-on-surface-variant mt-4">
          Nada publicado ainda. Peças aprovadas e agendadas para o Instagram saem sozinhas na hora marcada.
        </p>
      ) : (
        <ul className="mt-4 flex flex-col gap-3">
          {publicacoes.map((p) => (
            <li key={p.id}>
              <Card className="p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                  <div>
                    <p className="text-label-md text-on-surface">{p.content?.title ?? `Peça ${p.content_id}`}</p>
                    <p className="text-body-sm text-on-surface-variant mt-1">
                      {formatDateTime(p.scheduled_for)}
                      {p.account_username && ` · @${p.account_username}`}
                      {p.approver && ` · aprovada por ${p.approver.name}`}
                      {p.media_id && ` · mídia ${p.media_id}`}
                    </p>
                  </div>
                  <PublicationBadge publication={p} />
                </div>

                {p.last_error && <p className="text-body-sm text-error mt-2">{p.last_error}</p>}

                {(p.status === 'failed' || p.status === 'cancelled') && maisRecente.get(p.content_id) === p.id && (
                  <Button size="sm" variant="secondary" className="mt-3" disabled={retry.isPending} onClick={() => retry.mutate(p.id)}>
                    Tentar de novo agora
                  </Button>
                )}

                {p.status === 'unknown' && p.next_attempt_at === null && (
                  <div className="mt-3 flex flex-wrap items-center gap-2">
                    <span className="text-body-sm text-on-surface-variant">Confira o perfil no Instagram:</span>
                    <Button size="sm" variant="secondary" disabled={resolve.isPending} onClick={() => resolve.mutate({ id: p.id, outcome: 'published' })}>
                      Está no ar
                    </Button>
                    <Button size="sm" variant="ghost" disabled={resolve.isPending} onClick={() => resolve.mutate({ id: p.id, outcome: 'failed' })}>
                      Não saiu
                    </Button>
                  </div>
                )}
              </Card>
            </li>
          ))}
        </ul>
      )}

      {(atividade.data?.data.length ?? 0) > 0 && (
        <section className="mt-10" aria-labelledby="atividade">
          <h2 id="atividade" className="text-headline-md font-display text-on-surface">
            Atividade
          </h2>
          <p className="text-body-sm text-on-surface-variant mt-1">
            Quem conectou, desconectou, decidiu publicações ou removeu imagens neste projeto.
          </p>
          <ul className="mt-4 flex flex-col gap-2">
            {atividade.data?.data.map((a) => (
              <li key={a.id} className="text-body-sm text-on-surface">
                {describeActivity(a)}{' '}
                <span className="text-on-surface-variant">· {formatDateTime(a.created_at)}</span>
              </li>
            ))}
          </ul>
        </section>
      )}
    </Shell>
  )
}
