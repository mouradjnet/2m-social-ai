<?php

namespace App\Http\Controllers\Api\V1;

use App\Ai\Budget;
use App\Http\Controllers\Controller;
use App\Jobs\RunAgentJob;
use App\Models\AiRun;
use App\Models\Content;
use App\Models\ContentReview;
use App\Models\Project;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class RewriteController extends Controller
{
    /**
     * Conserta UMA peca reprovada, no lugar.
     *
     * Fecha o ciclo do revisor: ate aqui ele reprovava e o fluxo parava — o humano
     * arquivava a peca e pedia um LOTE novo, jogando fora as quatro boas e pagando 6
     * centavos para consertar uma.
     *
     * Guardas, na ordem: peca sem reprovacao (422 — nao ha o que corrigir), reescrita
     * concorrente (409), orcamento (402).
     */
    public function generate(Request $request, Content $content): JsonResponse
    {
        $project = $content->project;

        Gate::authorize('update', $project);

        $review = ContentReview::query()
            ->where('content_id', $content->id)
            ->latest('id')
            ->first();

        // So peca REPROVADA se reescreve. Um `pass` nao tem o que corrigir, e uma peca
        // nunca revisada nao tem veredito nenhum: reescrever seria adivinhar.
        if ($review?->verdict !== 'fail') {
            return response()->json([
                'message' => 'So uma peca reprovada pelo revisor pode ser reescrita.',
            ], 422);
        }

        if ($this->reescritaEmAndamento($project)) {
            return response()->json([
                'message' => 'Ja existe uma reescrita em andamento para este projeto.',
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
                'agent' => 'rewriter',
                'provider' => config('ai.provider'),
                'model' => config('ai.agents.rewriter.model'),
                'status' => 'queued',
                'input' => [
                    'project_id' => $project->id,
                    // A peca e o veredito dela vao no contexto; o AgentContext rebusca
                    // ambos na execucao (o input e registro de intencao).
                    'rewrite_content_id' => $content->id,
                    // O que o revisor JA reprovou no projeto: consertar este erro sem
                    // cair em outro ja conhecido.
                    'with_past_violations' => true,
                ],
                'created_by' => $request->user()->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json([
                'message' => 'Ja existe uma reescrita em andamento para este projeto.',
            ], 409);
        }

        RunAgentJob::dispatch($run->id);

        return response()->json(['ai_run_id' => $run->id], 202);
    }

    /** So execucoes de rewriter contam; o indice por-agente permite as outras. */
    private function reescritaEmAndamento(Project $project): bool
    {
        return AiRun::withoutGlobalScopes()
            ->where('project_id', $project->id)
            ->where('agent', 'rewriter')
            ->whereIn('status', ['queued', 'running'])
            ->exists();
    }
}
