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

class ReviewController extends Controller
{
    /**
     * Revisa o lote da coluna Revisao. Espelha o ScheduleController: 202 + polling
     * (ADR-07), guardas em ordem — pre-condicao (422), concorrencia (409), orcamento
     * (402).
     */
    public function generate(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('update', $project);

        $ids = $project->contents()
            ->where('status', 'review')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return response()->json([
                'message' => 'Não há peça em revisão para revisar.',
            ], 422);
        }

        if ($this->revisaoEmAndamento($project)) {
            return response()->json([
                'message' => 'Já existe uma revisão em andamento para este projeto.',
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
                'agent' => 'reviewer',
                'provider' => config('ai.provider'),
                'model' => config('ai.agents.reviewer.model'),
                'status' => 'queued',
                // Registro de intencao. O AgentContext rebusca as pecas na execucao e
                // filtra por `review` de novo: quem saiu da coluna nesse meio-tempo
                // nao e julgado.
                'input' => [
                    'project_id' => $project->id,
                    'content_ids' => $ids,
                    'batch_status' => 'review',
                ],
                'created_by' => $request->user()->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json([
                'message' => 'Já existe uma revisão em andamento para este projeto.',
            ], 409);
        }

        RunAgentJob::dispatch($run->id);

        return response()->json(['ai_run_id' => $run->id], 202);
    }

    /** So execucoes de reviewer contam; o indice por-agente permite as outras. */
    private function revisaoEmAndamento(Project $project): bool
    {
        return AiRun::withoutGlobalScopes()
            ->where('project_id', $project->id)
            ->where('agent', 'reviewer')
            ->whereIn('status', ['queued', 'running'])
            ->exists();
    }
}
