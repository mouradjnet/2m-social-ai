import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { api } from '@/lib/api'
import type { InstagramAccount } from '@/lib/types'

/** Abaixo disto a tela avisa: o token vence e a publicacao para. */
const AVISO_DIAS = 7

/**
 * O alerta de conexao que acompanha as telas de conteudo e calendario. Sem conta, um
 * convite discreto; com o token vencido ou perto de vencer, um aviso que nao da para
 * ignorar — e a diferenca entre o post das 8h sair ou nao.
 */
export function InstagramBanner({ projectId }: { projectId: string }) {
  const conta = useQuery({
    queryKey: ['instagram', projectId],
    queryFn: () => api<{ data: InstagramAccount | null }>(`/projects/${projectId}/instagram`),
  })

  if (!conta.isSuccess) return null

  const account = conta.data.data
  const link = `/projects/${projectId}/instagram`

  if (account === null) {
    return (
      <p className="text-body-sm text-on-surface-variant mt-4">
        Nenhuma conta do Instagram conectada — as peças agendadas não serão publicadas.{' '}
        <Link to={link} className="text-primary hover:underline">
          Conectar
        </Link>
      </p>
    )
  }

  const vencida = account.status === 'expired' || (account.expires_in_days ?? 0) < 0
  const vencendo = !vencida && account.expires_in_days !== null && account.expires_in_days < AVISO_DIAS

  if (!vencida && !vencendo) return null

  return (
    <div role="alert" className="bg-error-container text-error text-body-sm mt-4 rounded-card px-4 py-3">
      {vencida
        ? `A conexão com @${account.username} venceu. Nada será publicado até reconectar.`
        : `A conexão com @${account.username} vence em ${account.expires_in_days} ${account.expires_in_days === 1 ? 'dia' : 'dias'}.`}{' '}
      <Link to={link} className="font-display underline">
        Reconectar
      </Link>
    </div>
  )
}
