<?php

namespace App\Http\Controllers\Api\V1;

use App\Ai\Budget;
use App\Http\Controllers\Controller;
use App\Jobs\RunAgentJob;
use App\Models\AiRun;
use App\Models\Project;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class CopyController extends Controller
{
    /**
     * Gera um lote de pecas de conteudo a partir da estrategia ativa. Nao bloqueia:
     * 202 + polling em GET /ai-runs/{run} (ADR-07). Guardas em ordem: sem estrategia
     * (422) antes de tudo, depois concorrencia (409), depois orcamento (402).
     */
    public function generate(Project $project): JsonResponse
    {
        Gate::authorize('update', $project);

        // Sem estrategia ativa nao ha o que escrever — a pre-condicao mais barata.
        $strategy = $project->strategies()->where('status', 'active')->latest()->first();
        if ($strategy === null) {
            return response()->json([
                'message' => 'Aprove uma estrategia antes de gerar conteudo.',
            ], 422);
        }

        // Pilar alvo (opcional): o lote inteiro sai dele. Serve para cobrir um pilar
        // que a distribuicao por peso nunca sorteia — 15% de 5 pecas da 0,75, zero.
        $pillar = request()->input('pillar');
        if ($pillar !== null) {
            $pilares = array_column($strategy->pillars ?? [], 'name');

            if (! in_array($pillar, $pilares, true)) {
                return response()->json([
                    'message' => 'Esse pilar nao existe na estrategia ativa.',
                    'pillars' => $pilares,
                ], 422);
            }
        }

        // Uma geracao de copy em andamento por projeto. Filtra por agente: uma
        // estrategia rodando nao bloqueia copy.
        if ($this->copyEmAndamento($project)) {
            return response()->json([
                'message' => 'Ja existe uma geracao de conteudo em andamento para este projeto.',
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
                'agent' => 'copywriter',
                'provider' => config('ai.provider'),
                'model' => config('ai.agents.copywriter.model'),
                'status' => 'queued',
                // strategy_id e registro de intencao; o AgentContext busca a active
                // no momento da execucao (a fonte da verdade). `with_existing_contents`
                // manda o contexto carregar o inventario da marca — sem ele o copywriter
                // reescreve o que ja existe.
                'input' => array_filter([
                    'project_id' => $project->id,
                    'strategy_id' => $strategy->id,
                    'with_existing_contents' => true,
                    'pillar' => $pillar,
                ], fn ($v) => $v !== null),
                'created_by' => request()->user()->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Duas requisicoes passaram pela checagem ao mesmo tempo; o indice
            // parcial (project_id, agent) barrou a segunda.
            return response()->json([
                'message' => 'Ja existe uma geracao de conteudo em andamento para este projeto.',
            ], 409);
        }

        RunAgentJob::dispatch($run->id);

        return response()->json(['ai_run_id' => $run->id], 202);
    }

    /** So execucoes de copywriter contam; o indice por-agente permite as outras. */
    private function copyEmAndamento(Project $project): bool
    {
        return AiRun::withoutGlobalScopes()
            ->where('project_id', $project->id)
            ->where('agent', 'copywriter')
            ->whereIn('status', ['queued', 'running'])
            ->exists();
    }
}
