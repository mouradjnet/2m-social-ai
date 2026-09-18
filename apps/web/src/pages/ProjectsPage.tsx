import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate } from 'react-router-dom'
import { Button } from '@/components/ui/Button'
import { Card, CardDescription, CardTitle } from '@/components/ui/Card'
import { Input } from '@/components/ui/Input'
import { Shell } from '@/components/ui/Shell'
import { api, clearToken } from '@/lib/api'
import { podeConvidar, podeCriarProjeto, rotulo } from '@/lib/roles'
import type { Me, Project } from '@/lib/types'

export function ProjectsPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [name, setName] = useState('')

  const me = useQuery({ queryKey: ['me'], queryFn: () => api<Me>('/me') })
  const workspace = me.data?.workspaces[0]

  const projects = useQuery({
    queryKey: ['projects', workspace?.id],
    queryFn: () => api<{ data: Project[] }>(`/workspaces/${workspace!.id}/projects`),
    enabled: workspace !== undefined,
  })

  const createWorkspace = useMutation({
    mutationFn: (name: string) =>
      api('/workspaces', { method: 'POST', body: JSON.stringify({ name }) }),
    onSuccess: () => {
      setName('')
      queryClient.invalidateQueries({ queryKey: ['me'] })
    },
  })

  const createProject = useMutation({
    mutationFn: (name: string) =>
      api<{ data: Project }>(`/workspaces/${workspace!.id}/projects`, {
        method: 'POST',
        body: JSON.stringify({ name }),
      }),
    onSuccess: ({ data }) => navigate(`/projects/${data.id}/brand-profile`),
  })

  if (me.isPending) return <Shell>Carregando…</Shell>

  // Usuário recém-registrado ainda não tem onde criar projeto.
  if (!workspace) {
    return (
      <Shell>
        <Card className="max-w-lg">
          <CardTitle>Crie seu espaço de trabalho</CardTitle>
          <CardDescription>
            Um espaço de trabalho agrupa os projetos de uma agência ou de você
            mesmo. Você pode convidar sua equipe depois.
          </CardDescription>

          <form
            className="mt-6 flex flex-col gap-6"
            onSubmit={(e) => {
              e.preventDefault()
              createWorkspace.mutate(name)
            }}
          >
            <Input
              label="Nome do espaço"
              placeholder="ex: 2M Negócios"
              value={name}
              onChange={(e) => setName(e.target.value)}
              required
            />
            <Button type="submit" disabled={createWorkspace.isPending}>
              {createWorkspace.isPending ? 'Criando…' : 'Criar espaço de trabalho'}
            </Button>
          </form>
        </Card>
      </Shell>
    )
  }

  return (
    <Shell>
      <div className="flex items-start justify-between">
        <div>
          <h1 className="text-headline-lg text-on-surface">Projetos</h1>
          <p className="text-body-md text-on-surface-variant mt-1">
            {workspace.name} · você é {rotulo(workspace.role)}
          </p>
        </div>

        <div className="flex items-center gap-2">
          {podeConvidar(workspace.role) && (
            <Link to="/equipe">
              <Button variant="secondary">Equipe</Button>
            </Link>
          )}
          <Button
            variant="secondary"
            onClick={() => {
              clearToken()
              navigate('/login')
            }}
          >
            Sair
          </Button>
        </div>
      </div>

      {/* A rota de criar exige editor: revisor e leitor nao veem o que o servidor recusaria. */}
      {podeCriarProjeto(workspace.role) && (
        <Card className="mt-8 max-w-lg">
          <CardTitle>Novo projeto</CardTitle>
          <CardDescription>Cada projeto representa uma empresa, cliente ou marca.</CardDescription>

          <form
            className="mt-6 flex items-end gap-4"
            onSubmit={(e) => {
              e.preventDefault()
              createProject.mutate(name)
            }}
          >
            <Input
              label="Nome do projeto"
              placeholder="ex: Clínica Vida"
              value={name}
              onChange={(e) => setName(e.target.value)}
              className="flex-1"
              required
            />
            <Button type="submit" disabled={createProject.isPending}>
              {createProject.isPending ? 'Criando…' : 'Criar'}
            </Button>
          </form>
        </Card>
      )}

      <div className="mt-8 grid gap-4 md:grid-cols-2 lg:grid-cols-3">
        {projects.data?.data.map((project) => (
          <Link key={project.id} to={`/projects/${project.id}/brand-profile`}>
            <Card interactive className="h-full">
              <CardTitle>{project.name}</CardTitle>
              <CardDescription>{project.segment ?? 'Sem segmento definido'}</CardDescription>
            </Card>
          </Link>
        ))}
      </div>

      {projects.data?.data.length === 0 && (
        <p className="text-body-md text-on-surface-variant mt-8">
          {podeCriarProjeto(workspace.role)
            ? 'Nenhum projeto ainda. Crie o primeiro acima.'
            : 'Nenhum projeto ainda.'}
        </p>
      )}
    </Shell>
  )
}
