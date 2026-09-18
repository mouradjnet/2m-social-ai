import { useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { Button } from '@/components/ui/Button'
import { Card, CardDescription, CardTitle } from '@/components/ui/Card'
import { Input } from '@/components/ui/Input'
import { ApiError, api, setToken } from '@/lib/api'

/**
 * Para onde voltar depois de entrar (ex: o link de convite). So caminho interno:
 * `//site.com` e `https://...` levariam o usuario para fora do app com cara de login
 * legitimo.
 */
function destinoSeguro(next: string | null): string {
  return next?.startsWith('/') && !next.startsWith('//') ? next : '/'
}

export function LoginPage() {
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const destino = destinoSeguro(params.get('next'))
  const veioDeConvite = destino.startsWith('/convite/')
  const [mode, setMode] = useState<'login' | 'register'>('register')
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<ApiError | null>(null)
  const [pending, setPending] = useState(false)

  async function submit(event: React.FormEvent) {
    event.preventDefault()
    setError(null)
    setPending(true)

    try {
      const payload = mode === 'register' ? { name, email, password } : { email, password }
      const { token } = await api<{ token: string }>(`/auth/${mode}`, {
        method: 'POST',
        body: JSON.stringify(payload),
      })
      setToken(token)
      navigate(destino)
    } catch (err) {
      if (err instanceof ApiError) setError(err)
      else throw err
    } finally {
      setPending(false)
    }
  }

  return (
    <main className="mx-auto flex min-h-svh max-w-md items-center px-6">
      <Card className="w-full">
        <CardTitle>{mode === 'register' ? 'Criar conta' : 'Entrar'}</CardTitle>
        <CardDescription>
          {mode === 'register'
            ? 'Comece organizando o marketing de conteúdo do seu primeiro cliente.'
            : 'Bem-vindo de volta.'}
        </CardDescription>

        {veioDeConvite && (
          <p className="text-body-sm bg-surface-container-low rounded-control mt-4 p-3">
            Você recebeu um convite. Entre ou crie sua conta com o e-mail que foi convidado — em
            seguida você volta para aceitá-lo.
          </p>
        )}

        <form onSubmit={submit} className="mt-6 flex flex-col gap-6">
          {mode === 'register' && (
            <Input
              label="Nome"
              value={name}
              onChange={(e) => setName(e.target.value)}
              error={error?.fieldError('name')}
              autoComplete="name"
              required
            />
          )}

          <Input
            label="E-mail"
            type="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            error={error?.fieldError('email')}
            autoComplete="email"
            required
          />

          <Input
            label="Senha"
            type="password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            error={error?.fieldError('password')}
            autoComplete={mode === 'register' ? 'new-password' : 'current-password'}
            hint={mode === 'register' ? 'Mínimo de 8 caracteres.' : undefined}
            required
          />

          <div className="flex items-center justify-between">
            <Button
              type="button"
              variant="ghost"
              onClick={() => {
                setMode(mode === 'register' ? 'login' : 'register')
                setError(null)
              }}
            >
              {mode === 'register' ? 'Já tenho conta' : 'Criar conta'}
            </Button>

            <Button type="submit" disabled={pending}>
              {pending ? 'Enviando…' : mode === 'register' ? 'Criar conta' : 'Entrar'}
            </Button>
          </div>
        </form>
      </Card>
    </main>
  )
}
