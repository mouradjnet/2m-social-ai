<?php

namespace App\Policies;

use App\Enums\WorkspaceRole;
use App\Models\Project;
use App\Models\User;

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

    private function roleFor(User $user, Project $project): ?WorkspaceRole
    {
        return $project->workspace->roleFor($user);
    }
}
