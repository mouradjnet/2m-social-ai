<?php

namespace App\Http\Controllers\Api\V1;

use App\Ai\Budget;
use App\Http\Controllers\Controller;
use App\Jobs\RunAgentJob;
use App\Models\AiRun;
use App\Models\Project;
use App\Models\Strategy;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class StrategyController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        return response()->json([
            'data' => $project->strategies()->latest()->get(),
        ]);
    }

    /**
     * Nao bloqueia: devolve 202 e o id da execucao. O frontend faz polling em
     * GET /ai-runs/{run} (ADR-07).
     */
    public function generate(Project $project): JsonResponse
    {
        Gate::authorize('update', $project);

        // Antes do orcamento: uma execucao em andamento ainda nao gravou
        // `cost_cents`, entao o Budget nao a enxerga. Duas geracoes concorrentes
        // no mesmo projeto cobrariam duas vezes.
        if ($this->emAndamento($project)) {
            return response()->json([
                'message' => 'Ja existe uma geracao em andamento para este projeto.',
            ], 409);
        }

        // Antes de enfileirar, nao depois de gastar.
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
                'agent' => 'strategist',
                'provider' => config('ai.provider'),
                'model' => config('ai.agents.strategist.model'),
                'status' => 'queued',
                'input' => ['project_id' => $project->id],
                'created_by' => request()->user()->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Duas requisicoes passaram pela checagem acima ao mesmo tempo. O
            // indice parcial `ai_runs_one_active_per_project` barrou a segunda.
            //
            // Este e o ultimo recurso, nao o caminho normal. No Postgres, um
            // insert que viola constraint DENTRO de uma transacao aborta a
            // transacao inteira (SQLSTATE 25P02) e tudo depois falha. Como este
            // metodo nao abre transacao, o catch funciona — mas quem vier envolve-lo
            // numa vai precisar de um SAVEPOINT. Por isso a checagem explicita
            // acima existe: ela e o caminho que roda de fato.
            return response()->json([
                'message' => 'Ja existe uma geracao em andamento para este projeto.',
            ], 409);
        }

        RunAgentJob::dispatch($run->id);

        return response()->json(['ai_run_id' => $run->id], 202);
    }

    /** O projeto ja foi autorizado; a busca nao precisa do escopo de workspace. */
    private function emAndamento(Project $project): bool
    {
        return AiRun::withoutGlobalScopes()
            ->where('project_id', $project->id)
            ->whereIn('status', ['queued', 'running'])
            ->exists();
    }

    public function update(Request $request, Strategy $strategy): JsonResponse
    {
        Gate::authorize('update', $strategy->project);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:200'],
            'summary' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'editorial_line' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'status' => ['sometimes', 'in:draft,active,archived'],
        ]);

        $strategy->update($data);

        return response()->json(['data' => $strategy->refresh()]);
    }
}
