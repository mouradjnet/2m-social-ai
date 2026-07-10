<?php

namespace App\Http\Middleware;

use App\Enums\WorkspaceRole;
use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * O workspace vem sempre da rota, nunca da sessao (ADR-02).
 *
 * Nao-membro recebe 404, nao 403: um 403 confirmaria que o workspace existe.
 * O papel minimo exigido vem do parametro do middleware, ex.: 'workspace:editor'.
 */
class EnsureWorkspaceMember
{
    public function handle(Request $request, Closure $next, ?string $minimumRole = null): Response
    {
        $workspace = $request->route('workspace');

        if (! $workspace instanceof Workspace) {
            abort(404);
        }

        $role = $workspace->roleFor($request->user());

        if ($role === null) {
            abort(404);
        }

        if ($minimumRole !== null && ! $role->atLeast(WorkspaceRole::from($minimumRole))) {
            abort(403, 'Seu papel neste workspace nao permite esta acao.');
        }

        $request->attributes->set('workspace_role', $role);

        return $next($request);
    }
}
