<?php

namespace App\Models\Scopes;

use App\Models\WorkspaceMember;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Defesa em profundidade contra IDOR (ADR-02).
 *
 * O middleware ja autoriza o workspace da rota. Este escopo garante que, mesmo
 * que alguem esqueca um `where workspace_id = ?`, a query nao devolva linhas de
 * outro tenant. E por isso que acessar um projeto de outro workspace resulta em
 * 404 (o model binding nao encontra a linha) e nao em 403 (que confirmaria que
 * o recurso existe).
 *
 * Sem usuario autenticado o escopo nao se aplica: seeders, migrations, jobs de
 * fila e comandos de console precisam enxergar todos os workspaces. Codigo que
 * roda fora de uma requisicao HTTP e responsavel pelo proprio escopo.
 */
class WorkspaceMemberScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        if ($user === null) {
            return;
        }

        $builder->whereIn(
            $model->qualifyColumn('workspace_id'),
            WorkspaceMember::query()
                ->select('workspace_id')
                ->where('user_id', $user->getAuthIdentifier())
        );
    }
}
