import type { WorkspaceSummary } from '@/lib/types'

export type Role = WorkspaceSummary['role']

/** Do mais alto ao mais baixo, a mesma ordem do WorkspaceRole do backend. */
export const PAPEIS: { value: Role; label: string }[] = [
  { value: 'owner', label: 'Dono' },
  { value: 'admin', label: 'Administrador' },
  { value: 'editor', label: 'Editor' },
  { value: 'reviewer', label: 'Revisor' },
  { value: 'viewer', label: 'Leitor' },
]

export const rotulo = (role: Role) => PAPEIS.find((p) => p.value === role)?.label ?? role

/**
 * Espelha os `workspace:{papel}` das rotas: a tela so oferece o que o servidor
 * aceitaria. A autorizacao de verdade continua sendo dele.
 */
export const podeConvidar = (role: Role) => role === 'owner' || role === 'admin'

export const podeCriarProjeto = (role: Role) =>
  role === 'owner' || role === 'admin' || role === 'editor'
