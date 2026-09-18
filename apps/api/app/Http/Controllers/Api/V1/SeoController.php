<?php

namespace App\Http\Controllers\Api\V1;

use App\Ai\Budget;
use App\Http\Controllers\Controller;
use App\Jobs\RunAgentJob;
use App\Models\AiRun;
use App\Models\Content;
use App\Models\ContentRevision;
use App\Models\Project;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class SeoController extends Controller
{
    /**
     * Propoe titulo, keywords e hashtags para as pecas em producao. Espelha o
     * DesignController: 422 sem peca em producao, 409 concorrente, 402 orcamento,
     * senao 202 + polling (ADR-07).
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
                'message' => 'Não há peça em produção para otimizar.',
            ], 422);
        }

        if ($this->seoEmAndamento($project)) {
            return response()->json([
                'message' => 'Já existe uma otimização em andamento para este projeto.',
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
                'agent' => 'seo',
                'provider' => config('ai.provider'),
                'model' => config('ai.agents.seo.model'),
                'status' => 'queued',
                'input' => [
                    'project_id' => $project->id,
                    'content_ids' => $ids,
                    'batch_status' => 'production',
                ],
                'created_by' => $request->user()->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json([
                'message' => 'Já existe uma otimização em andamento para este projeto.',
            ], 409);
        }

        RunAgentJob::dispatch($run->id);

        return response()->json(['ai_run_id' => $run->id], 202);
    }

    /**
     * Aplica a ultima sugestao: a peca passa a ser o que o SEO propos.
     *
     * O texto antigo nao some sem rastro — a revisao sai SEM status e com `changes`
     * contando o de-para, o mesmo mecanismo da remarcacao. E `applied_at` diz qual
     * sugestao virou a peca.
     */
    public function apply(Content $content): JsonResponse
    {
        Gate::authorize('update', $content->project);

        $seo = $content->latestSeo()->first();

        if ($seo === null) {
            return response()->json([
                'message' => 'Esta peça não tem sugestão de SEO para aplicar.',
            ], 422);
        }

        DB::transaction(function () use ($content, $seo) {
            $de = ['title' => $content->title, 'hashtags' => $content->hashtags];

            $content->update(['title' => $seo->title, 'hashtags' => $seo->hashtags]);
            $seo->update(['applied_at' => now()]);

            ContentRevision::create([
                'content_id' => $content->id,
                'user_id' => request()->user()->id,
                'changes' => [
                    'title' => ['from' => $de['title'], 'to' => $seo->title],
                    'hashtags' => ['from' => $de['hashtags'], 'to' => $seo->hashtags],
                ],
            ]);
        });

        return response()->json(['data' => $content->refresh()->load('latestSeo')]);
    }

    /** So execucoes de seo contam; o indice por-agente permite as outras. */
    private function seoEmAndamento(Project $project): bool
    {
        return AiRun::withoutGlobalScopes()
            ->where('project_id', $project->id)
            ->where('agent', 'seo')
            ->whereIn('status', ['queued', 'running'])
            ->exists();
    }
}
