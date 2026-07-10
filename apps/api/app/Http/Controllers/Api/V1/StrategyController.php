<?php

namespace App\Http\Controllers\Api\V1;

use App\Ai\Budget;
use App\Http\Controllers\Controller;
use App\Jobs\RunAgentJob;
use App\Models\AiRun;
use App\Models\Project;
use App\Models\Strategy;
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

        // Antes de enfileirar, nao depois de gastar.
        if (Budget::exceeded($project->workspace)) {
            return response()->json([
                'message' => 'Orcamento mensal de IA esgotado para este espaco de trabalho.',
                'spent_cents' => Budget::spentCentsThisMonth($project->workspace),
                'limit_cents' => Budget::limitCents(),
            ], 402);
        }

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

        RunAgentJob::dispatch($run->id);

        return response()->json(['ai_run_id' => $run->id], 202);
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
