<?php

namespace App\Policies;

use App\Enums\WorkspaceRole;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Rotas aninhadas em /workspaces/{workspace} sao autorizadas pelo middleware
 * EnsureWorkspaceMember. Rotas que recebem so o projeto (/projects/{project})
 * usam esta policy: o WorkspaceMemberScope ja garantiu que o projeto pertence
 * a um workspace do usuario, aqui checamos apenas o papel.
 */
class ProjectPolicy
{
    public function view(User $user, Project $project): bool
    {
        return $this->roleFor($user, $project) !== null;
    }

    public function update(User $user, Project $project): bool
    {
        return $this->roleFor($user, $project)?->atLeast(WorkspaceRole::Editor) ?? false;
    }

    /**
     * CP-04A: a permissao de APROVAR uma peca (decisao editorial humana). Deny-by-
     * default: so revisor, admin e dono do workspace da peca. Editor escreve, nao
     * aprova o proprio texto; viewer so le.
     */
    public function approve(User $user, Project $project): Response
    {
        return ($this->roleFor($user, $project)?->atLeast(WorkspaceRole::Reviewer) ?? false)
            ? Response::allow()
            : Response::deny('Só quem revisa pode decidir sobre uma peça.');
    }

    private function roleFor(User $user, Project $project): ?WorkspaceRole
    {
        return $project->workspace->roleFor($user);
    }
}
