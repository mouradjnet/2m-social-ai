<?php

namespace App\Http\Controllers\Api\V1;

use App\Ai\Budget;
use App\Ai\Images\ImageProvider;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateImageJob;
use App\Models\AiRun;
use App\Models\Content;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Gera a imagem de uma peca a partir do prompt do diretor de arte (ADR-15). Mesmo
 * contrato dos agentes: 202 + polling em /ai-runs/{id}, guardas na ordem
 * pre-condicao (422) → concorrencia (409) → orcamento (402).
 */
class ImageGenerationController extends Controller
{
    private const EDITAVEIS = ['idea', 'production', 'review'];

    public function generate(Request $request, Content $content, ImageProvider $provider): JsonResponse
    {
        $project = $content->project;
        Gate::authorize('update', $project);

        if (blank($content->image_prompt)) {
            return response()->json([
                'message' => 'A peça ainda não tem prompt de imagem. Rode o diretor de arte ou escreva o prompt.',
            ], 422);
        }

        if (! in_array($content->status, self::EDITAVEIS, true)) {
            return response()->json([
                'message' => 'Peça aprovada não troca a imagem. Devolva para revisão antes.',
            ], 422);
        }

        if ($this->emAndamento($project->id)) {
            return response()->json([
                'message' => 'Já existe uma imagem sendo gerada neste projeto. Aguarde terminar.',
            ], 409);
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
                'agent' => 'image',
                'provider' => $provider->name(),
                'model' => $provider->model(),
                'status' => 'queued',
                'input' => ['content_id' => $content->id],
                'created_by' => $request->user()->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Corrida entre dois cliques: o indice parcial por projeto+agente decide.
            return response()->json([
                'message' => 'Já existe uma imagem sendo gerada neste projeto. Aguarde terminar.',
            ], 409);
        }

        GenerateImageJob::dispatch($run->id);

        return response()->json(['ai_run_id' => $run->id], 202);
    }

    private function emAndamento(int $projectId): bool
    {
        return AiRun::where('project_id', $projectId)
            ->where('agent', 'image')
            ->whereIn('status', ['queued', 'running'])
            ->exists();
    }
}
