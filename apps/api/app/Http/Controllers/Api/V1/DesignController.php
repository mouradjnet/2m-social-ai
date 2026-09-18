<?php

namespace App\Http\Controllers\Api\V1;

use App\Ai\Budget;
use App\Http\Controllers\Controller;
use App\Jobs\RunAgentJob;
use App\Models\AiRun;
use App\Models\Project;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DesignController extends Controller
{
    /**
     * Escreve o prompt de imagem das pecas em producao. Espelha o ReviewController:
     * 202 + polling (ADR-07), guardas em ordem — pre-condicao (422), concorrencia
     * (409), orcamento (402).
     */
    public function generate(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('update', $project);

        $ids = $project->contents()
            ->where('status', 'production')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return response()->json([
                'message' => 'Não há peça em produção para desenhar.',
            ], 422);
        }

        if ($this->designEmAndamento($project)) {
            return response()->json([
                'message' => 'Já existe uma geração de imagens em andamento para este projeto.',
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
                'agent' => 'designer',
                'provider' => config('ai.provider'),
                'model' => config('ai.agents.designer.model'),
                'status' => 'queued',
                // Intencao. O AgentContext rebusca o lote na execucao e filtra por
                // `production` de novo.
                'input' => [
                    'project_id' => $project->id,
                    'content_ids' => $ids,
                    'batch_status' => 'production',
                ],
                'created_by' => $request->user()->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json([
                'message' => 'Já existe uma geração de imagens em andamento para este projeto.',
            ], 409);
        }

        RunAgentJob::dispatch($run->id);

        return response()->json(['ai_run_id' => $run->id], 202);
    }

    /** So execucoes de designer contam; o indice por-agente permite as outras. */
    private function designEmAndamento(Project $project): bool
    {
        return AiRun::withoutGlobalScopes()
            ->where('project_id', $project->id)
            ->where('agent', 'designer')
            ->whereIn('status', ['queued', 'running'])
            ->exists();
    }
}
