import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { api } from '@/lib/api'
import type { Me } from '@/lib/types'

const CHAVE = '2m.workspace'

/** O navegador pode negar o storage (aba anonima, dados bloqueados): a escolha so nao e lembrada. */
function lembrada(): number | null {
  try {
    const v = window.localStorage.getItem(CHAVE)
    return v === null ? null : Number(v)
  } catch {
    return null
  }
}

/**
 * O espaco de trabalho em uso. Quem participa de mais de um (a agencia e o cliente, o
 * proprio e o do convite) escolhe; a escolha fica neste navegador. Um id lembrado que
 * ja nao e da pessoa (saiu do workspace) cai no primeiro.
 *
 * Isolamento nao depende disto: a API confere o papel em toda rota (`workspace:{papel}`,
 * WorkspaceMemberScope). Isto e so qual espaco a tela mostra.
 */
export function useCurrentWorkspace() {
  const me = useQuery({ queryKey: ['me'], queryFn: () => api<Me>('/me') })
  const [escolhido, setEscolhido] = useState<number | null>(lembrada)

  const workspaces = me.data?.workspaces ?? []
  const workspace = workspaces.find((w) => w.id === escolhido) ?? workspaces[0]

  const select = (id: number) => {
    setEscolhido(id)
    try {
      window.localStorage.setItem(CHAVE, String(id))
    } catch {
      // Sem storage, a troca vale so para esta visita.
    }
  }

  return { me, workspace, workspaces, select }
}
