<?php

namespace App\Http\Controllers\Api\V1;

use App\Ai\Budget;
use App\Domain\Results\Performance;
use App\Http\Controllers\Controller;
use App\Jobs\RunAgentJob;
use App\Models\AiRun;
use App\Models\AnalyticsReport;
use App\Models\InstagramAccount;
use App\Models\Project;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Os resultados REAIS (Etapa 5): o que a Meta mediu nos posts do projeto, e a
 * leitura deles por IA (agente `results`). Quando a conta nao autorizou metricas, a
 * tela diz — e nao inventa.
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
            // A leitura mais recente, com os numeros em que ela se baseou.
            'report' => AnalyticsReport::where('project_id', $project->id)->where('kind', 'results')->latest('id')->first(),
        ]);
    }

    /**
     * A leitura por IA dos ultimos 30 dias. Os numeros sao calculados AQUI e congelados
     * no input: o agente cita exatamente o que leu. Guardas: amostra minima (422) →
     * concorrencia (409) → orcamento (402).
     */
    public function generate(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('update', $project);

        $resultados = Performance::for($project, 30);
        $minimo = (int) config('ai.agents.results.min_measured');

        if ($resultados['measured'] < $minimo) {
            return response()->json([
                'message' => "Só {$resultados['measured']} posts têm números da Meta nos últimos 30 dias. Com menos de {$minimo}, a leitura seria palpite.",
            ], 422);
        }

        if ($this->emAndamento($project)) {
            return response()->json(['message' => 'Já existe uma leitura de resultados em andamento para este projeto.'], 409);
        }

        if ($recusa = Budget::refusal($project)) {
            return $recusa;
        }

        try {
            $run = AiRun::create([
                'workspace_id' => $project->workspace_id,
                'project_id' => $project->id,
                'agent' => 'results',
                'provider' => config('ai.provider'),
                'model' => config('ai.agents.results.model'),
                'status' => 'queued',
                'input' => ['project_id' => $project->id, 'metrics' => $resultados],
                'created_by' => $request->user()->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['message' => 'Já existe uma leitura de resultados em andamento para este projeto.'], 409);
        }

        RunAgentJob::dispatch($run->id);

        return response()->json(['ai_run_id' => $run->id], 202);
    }

    private function emAndamento(Project $project): bool
    {
        return AiRun::withoutGlobalScopes()
            ->where('project_id', $project->id)
            ->where('agent', 'results')
            ->whereIn('status', ['queued', 'running'])
            ->exists();
    }
}
