import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Button } from '@/components/ui/Button'
import { Card, CardDescription, CardTitle } from '@/components/ui/Card'
import { Input } from '@/components/ui/Input'
import { Shell } from '@/components/ui/Shell'
import { ApiError, api } from '@/lib/api'
import { copyToClipboard } from '@/lib/copyToClipboard'
import type { Invitation, Me, WorkspaceSummary } from '@/lib/types'

type Role = WorkspaceSummary['role']

/** Do mais alto ao mais baixo, a mesma ordem do WorkspaceRole do backend. */
const PAPEIS: { value: Role; label: string }[] = [
  { value: 'owner', label: 'Dono' },
  { value: 'admin', label: 'Administrador' },
  { value: 'editor', label: 'Editor' },
  { value: 'reviewer', label: 'Revisor' },
  { value: 'viewer', label: 'Leitor' },
]

const rotulo = (role: Role) => PAPEIS.find((p) => p.value === role)?.label ?? role

const podeConvidar = (role: Role) => role === 'owner' || role === 'admin'

/** Nao ha mailer: o link e a entrega. Quem convida copia e manda por onde quiser. */
const linkDoConvite = (token: string) => `${window.location.origin}/convite/${token}`

function CopiarLink({ token }: { token: string }) {
  const [copiado, setCopiado] = useState(false)

  return (
    <Button
      variant="secondary"
      size="sm"
      className="shrink-0 whitespace-nowrap"
      onClick={async () => {
        if (await copyToClipboard(linkDoConvite(token))) {
          setCopiado(true)
          setTimeout(() => setCopiado(false), 2000)
        }
      }}
    >
      {copiado ? 'Copiado' : 'Copiar link'}
    </Button>
  )
}

export function TeamPage() {
  const queryClient = useQueryClient()
  const [email, setEmail] = useState('')
  const [role, setRole] = useState<Role>('editor')

  const me = useQuery({ queryKey: ['me'], queryFn: () => api<Me>('/me') })
  const workspace = me.data?.workspaces[0]
  const admin = workspace !== undefined && podeConvidar(workspace.role)

  const convites = useQuery({
    queryKey: ['invitations', workspace?.id],
    queryFn: () => api<{ data: Invitation[] }>(`/workspaces/${workspace!.id}/invitations`),
    enabled: admin,
  })

  const convidar = useMutation({
    mutationFn: (body: { email: string; role: Role }) =>
      api<{ data: Invitation }>(`/workspaces/${workspace!.id}/invitations`, {
        method: 'POST',
        body: JSON.stringify(body),
      }),
    onSuccess: () => {
      setEmail('')
      queryClient.invalidateQueries({ queryKey: ['invitations', workspace?.id] })
    },
  })

  if (me.isPending) return <Shell>Carregando…</Shell>

  if (!workspace || !admin) {
    return (
      <Shell>
        <p className="text-body-md text-on-surface-variant">
          Só administradores convidam pessoas para o espaço de trabalho.
        </p>
        <Link to="/" className="text-label-md text-primary mt-4 inline-block">
          Voltar aos projetos
        </Link>
      </Shell>
    )
  }

  // O backend recusa convidar acima do proprio papel; a tela nem oferece.
  const meu = PAPEIS.findIndex((p) => p.value === workspace.role)
  const oferecidos = PAPEIS.slice(meu)
  const erro = convidar.error instanceof ApiError ? convidar.error : null
  const novo = convidar.data?.data

  return (
    <Shell>
      <Link to="/" className="text-label-md text-on-surface-variant hover:text-primary">
        ← Projetos
      </Link>
      <h1 className="text-headline-lg text-on-surface mt-2">Equipe</h1>
      <p className="text-body-md text-on-surface-variant mt-1">{workspace.name}</p>

      <Card className="mt-8 max-w-2xl">
        <CardTitle>Convidar pessoa</CardTitle>
        <CardDescription>
          O convite é um link, válido por 7 dias. Mande por WhatsApp ou e-mail: só entra quem
          estiver logado com o e-mail convidado. Quem ainda não tem conta precisa ter o e-mail
          liberado para cadastro.
        </CardDescription>

        <form
          className="mt-6 flex flex-wrap items-start gap-4"
          onSubmit={(e) => {
            e.preventDefault()
            convidar.mutate({ email, role })
          }}
        >
          <div className="min-w-64 flex-1">
            <Input
              label="E-mail"
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              error={erro?.fieldError('email') ?? erro?.message422}
              required
            />
          </div>

          <div className="flex flex-col gap-2">
            <label htmlFor="papel" className="text-label-sm font-display text-on-surface uppercase">
              Papel
            </label>
            <select
              id="papel"
              value={role}
              onChange={(e) => setRole(e.target.value as Role)}
              className="rounded-control border-outline-variant bg-surface-container-lowest text-body-md focus:border-primary h-10 border px-3"
            >
              {oferecidos.map((p) => (
                <option key={p.value} value={p.value}>
                  {p.label}
                </option>
              ))}
            </select>
          </div>

          {/* Rotulo invisivel: mantem o botao na linha dos campos, que tem rotulo em cima. */}
          <div className="flex flex-col gap-2">
            <span aria-hidden="true" className="text-label-sm font-display invisible">
              &nbsp;
            </span>
            <Button type="submit" disabled={convidar.isPending}>
              {convidar.isPending ? 'Gerando…' : 'Gerar convite'}
            </Button>
          </div>
        </form>

        {novo && (
          <div className="bg-surface-container-low rounded-control mt-6 p-4">
            <p className="text-body-sm text-on-surface-variant">
              Convite para {novo.email} criado. Envie este link:
            </p>
            <div className="mt-2 flex items-center gap-3">
              <code className="text-body-sm break-all">{linkDoConvite(novo.token)}</code>
              <CopiarLink token={novo.token} />
            </div>
          </div>
        )}
      </Card>

      <h2 className="text-headline-md font-display mt-10">Convites pendentes</h2>

      {convites.data?.data.length === 0 && (
        <p className="text-body-md text-on-surface-variant mt-4">Nenhum convite pendente.</p>
      )}

      <ul className="mt-4 flex max-w-2xl flex-col gap-3">
        {convites.data?.data.map((c) => (
          <li key={c.id}>
            <Card className="flex flex-col gap-2">
              <div className="flex items-center justify-between gap-4">
                <span className="text-body-md">{c.email}</span>
                <span className="text-label-sm bg-surface-container text-on-surface-variant rounded-full px-2 py-0.5">
                  {rotulo(c.role)}
                </span>
              </div>
              <div className="flex items-center justify-between gap-4">
                <code className="text-body-sm text-on-surface-variant break-all">
                  {linkDoConvite(c.token)}
                </code>
                <CopiarLink token={c.token} />
              </div>
              <span className="text-body-sm text-on-surface-variant">
                Vence em {new Date(c.expires_at).toLocaleDateString('pt-BR')}
              </span>
            </Card>
          </li>
        ))}
      </ul>
    </Shell>
  )
}
