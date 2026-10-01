<?php

namespace App\Http\Controllers\Api\V1;

use App\Ai\Budget;
use App\Domain\Editorial\Versioning;
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

        if ($recusa = Budget::refusal($project)) {
            return $recusa;
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
     *
     * CP-04D: como toda edicao, exige a versao que a pessoa viu (`expected_version`).
     * A sugestao foi feita sobre um texto; se a peca mudou depois, aplica-la apagaria
     * a edicao sem que ninguem visse — 409.
     */
    public function apply(Request $request, Content $content): JsonResponse
    {
        Gate::authorize('update', $content->project);

        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1']], [
            'expected_version.required' => 'Falta a versão da peça que você está vendo (expected_version).',
        ]);

        // Peca aprovada tem o texto congelado (ADR-13): o humano aprovou ESTE titulo e
        // estas hashtags. Mudar exige voltar para revisao — e aprovar de novo.
        if (in_array($content->status, ['approved', 'scheduled', 'published'], true)) {
            return response()->json([
                'message' => 'Peça aprovada não muda o texto. Devolva para revisão antes de aplicar o SEO.',
            ], 422);
        }

        $seo = $content->latestSeo()->first();

        if ($seo === null) {
            return response()->json([
                'message' => 'Esta peça não tem sugestão de SEO para aplicar.',
            ], 422);
        }

        DB::transaction(function () use ($content, $seo, $data) {
            Versioning::exigir($content, (int) $data['expected_version']);

            $de = ['title' => $content->title, 'hashtags' => $content->hashtags];

            Versioning::como('seo', request()->user()->id, fn () => $content->update(['title' => $seo->title, 'hashtags' => $seo->hashtags]));
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
