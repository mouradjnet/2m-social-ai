<?php

namespace App\Http\Controllers\Api\V1;

use App\Ai\Budget;
use App\Domain\Analytics\Metrics;
use App\Http\Controllers\Controller;
use App\Jobs\RunAgentJob;
use App\Models\AiRun;
use App\Models\AnalyticsReport;
use App\Models\Project;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AnalyticsController extends Controller
{
    /** O relatorio mais recente, ou null — a tela nasce vazia e explica isso. */
    public function show(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        return response()->json([
            'data' => AnalyticsReport::where('project_id', $project->id)->latest('id')->first(),
            // Os numeros de agora, mesmo sem relatorio: a tela mostra o calendario
            // antes de a IA opinar sobre ele.
            'metrics' => Metrics::for($project),
        ]);
    }

    /**
     * Le os numeros e escreve a leitura. As metricas sao calculadas AQUI e congeladas
     * no `input` da execucao: o agente precisa citar exatamente os numeros que leu, e
     * recalcula-los na execucao daria outro resultado.
     */
    public function generate(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('update', $project);

        if (! $project->contents()->exists()) {
            return response()->json([
                'message' => 'Não há conteúdo para analisar.',
            ], 422);
        }

        if ($this->analiseEmAndamento($project)) {
            return response()->json([
                'message' => 'Já existe uma análise em andamento para este projeto.',
            ], 409);
        }

        if (Budget::exceeded($project->workspace)) {
            return response()->json([
                'message' => 'Orçamento mensal de IA esgotado para este espaço de trabalho.',
                'spent_cents' => Budget::spentCentsThisMonth($project->workspace),
                'limit_cents' => Budget::limitCents(),
            ], 402);
        }

        try {
            $run = AiRun::create([
                'workspace_id' => $project->workspace_id,
                'project_id' => $project->id,
                'agent' => 'analytics',
                'provider' => config('ai.provider'),
                'model' => config('ai.agents.analytics.model'),
                'status' => 'queued',
                'input' => [
                    'project_id' => $project->id,
                    'metrics' => Metrics::for($project),
                ],
                'created_by' => $request->user()->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json([
                'message' => 'Já existe uma análise em andamento para este projeto.',
            ], 409);
        }

        RunAgentJob::dispatch($run->id);

        return response()->json(['ai_run_id' => $run->id], 202);
    }

    /** So execucoes de analytics contam; o indice por-agente permite as outras. */
    private function analiseEmAndamento(Project $project): bool
    {
        return AiRun::withoutGlobalScopes()
            ->where('project_id', $project->id)
            ->where('agent', 'analytics')
            ->whereIn('status', ['queued', 'running'])
            ->exists();
    }
}
