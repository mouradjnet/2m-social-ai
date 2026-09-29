<?php

namespace App\Http\Controllers\Api\V1;

use App\Ai\Budget;
use App\Http\Controllers\Controller;
use App\Jobs\RunAgentJob;
use App\Models\AiRun;
use App\Models\Content;
use App\Models\Project;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Reaproveita uma peca em outro formato ou canal (repurposer). Cria uma peca NOVA,
 * em `idea`; a original nao muda. Guardas: pre-condicao (422) → concorrencia
 * (409) → orcamento (402).
 */
class RepurposeController extends Controller
{
    private const FORMATS = ['post', 'carousel', 'reel', 'story', 'video', 'article', 'thread'];

    private const CHANNELS = ['instagram', 'facebook', 'linkedin', 'tiktok', 'youtube', 'blog'];

    public function generate(Request $request, Content $content): JsonResponse
    {
        $project = $content->project;
        Gate::authorize('update', $project);

        $data = $request->validate([
            'format' => ['required', Rule::in(self::FORMATS)],
            'channel' => ['required', Rule::in(self::CHANNELS)],
        ]);

        if (blank($content->caption)) {
            return response()->json(['message' => 'A peça ainda não tem texto para reaproveitar.'], 422);
        }

        if ($data['format'] === $content->format && $data['channel'] === $content->channel) {
            return response()->json([
                'message' => 'Escolha outro formato ou outro canal: reaproveitar é adaptar.',
            ], 422);
        }

        if ($this->emAndamento($project)) {
            return response()->json(['message' => 'Já existe um reaproveitamento em andamento para este projeto.'], 409);
        }

        if (Budget::exceeded($project->workspace)) {
            return response()->json([
                'message' => 'Orçamento mensal de IA esgotado para este espaço de trabalho.',
                'spent_cents' => Budget::spentCentsThisMonth($project->workspace),
                'limit_cents' => Budget::limitCents($project->workspace),
            ], 402);
        }

        try {
            $run = AiRun::create([
                'workspace_id' => $project->workspace_id,
                'project_id' => $project->id,
                'agent' => 'repurposer',
                'provider' => config('ai.provider'),
                'model' => config('ai.agents.repurposer.model'),
                'status' => 'queued',
                'input' => [
                    'repurpose_content_id' => $content->id,
                    'target_format' => $data['format'],
                    'target_channel' => $data['channel'],
                    // Nao repetir titulo existente nem erro ja reprovado.
                    'with_existing_contents' => true,
                    'with_past_violations' => true,
                ],
                'created_by' => $request->user()->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['message' => 'Já existe um reaproveitamento em andamento para este projeto.'], 409);
        }

        RunAgentJob::dispatch($run->id);

        return response()->json(['ai_run_id' => $run->id], 202);
    }

    private function emAndamento(Project $project): bool
    {
        return AiRun::withoutGlobalScopes()
            ->where('project_id', $project->id)
            ->where('agent', 'repurposer')
            ->whereIn('status', ['queued', 'running'])
            ->exists();
    }
}
