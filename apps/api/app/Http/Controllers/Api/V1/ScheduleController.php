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

class ScheduleController extends Controller
{
    /**
     * Distribui as pecas aprovadas pelo calendario. Espelha o CopyController: 202 +
     * polling (ADR-07), com as guardas na mesma ordem — pre-condicao (422), depois
     * concorrencia (409), depois orcamento (402).
     */
    public function generate(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('update', $project);

        $data = $request->validate([
            'starts_on' => ['sometimes', 'date'],
            'days' => ['sometimes', 'integer', 'min:1', 'max:60'],
        ]);

        // Sem peca aprovada nao ha o que agendar — a pre-condicao mais barata.
        if (! $project->contents()->where('status', 'approved')->exists()) {
            return response()->json([
                'message' => 'Aprove pelo menos uma peca antes de agendar.',
            ], 422);
        }

        if ($this->agendamentoEmAndamento($project)) {
            return response()->json([
                'message' => 'Ja existe um agendamento em andamento para este projeto.',
            ], 409);
        }

        if (Budget::exceeded($project->workspace)) {
            return response()->json([
                'message' => 'Orcamento mensal de IA esgotado para este espaco de trabalho.',
                'spent_cents' => Budget::spentCentsThisMonth($project->workspace),
                'limit_cents' => Budget::limitCents(),
            ], 402);
        }

        try {
            $run = AiRun::create([
                'workspace_id' => $project->workspace_id,
                'project_id' => $project->id,
                'agent' => 'social_media',
                'provider' => config('ai.provider'),
                'model' => config('ai.agents.social_media.model'),
                'status' => 'queued',
                // A janela e a unica entrada que nao vem do projeto: o AgentContext a
                // le daqui. O lote de pecas, esse sim, e buscado na execucao.
                'input' => [
                    'project_id' => $project->id,
                    'starts_on' => $data['starts_on'] ?? now()->addDay()->toDateString(),
                    'days' => $data['days'] ?? config('ai.agents.social_media.default_days'),
                ],
                'created_by' => $request->user()->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Duas requisicoes passaram pela checagem ao mesmo tempo; o indice parcial
            // (project_id, agent) barrou a segunda.
            return response()->json([
                'message' => 'Ja existe um agendamento em andamento para este projeto.',
            ], 409);
        }

        RunAgentJob::dispatch($run->id);

        return response()->json(['ai_run_id' => $run->id], 202);
    }

    /** So execucoes de social_media contam; o indice por-agente permite as outras. */
    private function agendamentoEmAndamento(Project $project): bool
    {
        return AiRun::withoutGlobalScopes()
            ->where('project_id', $project->id)
            ->where('agent', 'social_media')
            ->whereIn('status', ['queued', 'running'])
            ->exists();
    }
}
