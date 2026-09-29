<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Results\Performance;
use App\Http\Controllers\Controller;
use App\Models\InstagramAccount;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Os resultados REAIS (Etapa 5): o que a Meta mediu nos posts do projeto. Leitura para
 * qualquer membro. Quando a conta nao autorizou metricas, a tela diz — e nao inventa.
 */
class ResultsController extends Controller
{
    public function show(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        $request->validate(['days' => ['nullable', Rule::in(['7', '30', '90'])]]);
        $dias = (int) ($request->query('days') ?? 30);

        $conta = InstagramAccount::where('project_id', $project->id)->where('status', '<>', 'disconnected')->first();

        return response()->json([
            'data' => Performance::for($project, $dias),
            'account' => $conta === null ? null : [
                'username' => $conta->username,
                'insights_enabled' => $conta->hasInsights(),
            ],
        ]);
    }
}
