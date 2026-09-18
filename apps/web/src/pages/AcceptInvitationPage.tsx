import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Navigate, useNavigate, useParams } from 'react-router-dom'
import { Button } from '@/components/ui/Button'
import { Card, CardDescription, CardTitle } from '@/components/ui/Card'
import { ApiError, api, clearToken, getToken } from '@/lib/api'

/**
 * Destino do link de convite. Fica FORA do RequireAuth de proposito: quem chega sem
 * login precisa ir ao login e VOLTAR aqui — o RequireAuth perderia o token do convite.
 */
export function AcceptInvitationPage() {
  const { token = '' } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const aqui = `/login?next=${encodeURIComponent(`/convite/${token}`)}`

  const aceitar = useMutation({
    mutationFn: () => api(`/invitations/${token}:accept`, { method: 'POST' }),
    onSuccess: async () => {
      // O workspace novo so aparece depois que o /me for relido.
      await queryClient.invalidateQueries({ queryKey: ['me'] })
      navigate('/')
    },
  })

  if (!getToken()) return <Navigate to={aqui} replace />

  const erro = aceitar.error instanceof ApiError ? aceitar.error : null

  return (
    <main className="mx-auto flex min-h-svh max-w-md items-center px-6">
      <Card className="w-full">
        <CardTitle>Convite para a equipe</CardTitle>
        <CardDescription>
          Você foi convidado para um espaço de trabalho do 2M Social AI.
        </CardDescription>

        {erro && (
          <p role="alert" className="text-body-sm text-error mt-6">
            {erro.message422 ?? 'Não foi possível aceitar o convite.'}
          </p>
        )}

        <div className="mt-6 flex items-center justify-between gap-4">
          {/* Logado com a conta errada: sair e entrar com a convidada, voltando aqui. */}
          {erro?.status === 403 ? (
            <Button
              variant="ghost"
              onClick={() => {
                clearToken()
                navigate(aqui)
              }}
            >
              Entrar com outra conta
            </Button>
          ) : (
            <span />
          )}

          <Button onClick={() => aceitar.mutate()} disabled={aceitar.isPending}>
            {aceitar.isPending ? 'Aceitando…' : 'Aceitar convite'}
          </Button>
        </div>
      </Card>
    </main>
  )
}
