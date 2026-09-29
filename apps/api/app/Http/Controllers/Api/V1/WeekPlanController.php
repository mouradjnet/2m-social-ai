<?php

namespace App\Http\Controllers\Api\V1;

use App\Ai\Budget;
use App\Domain\Results\Performance;
use App\Http\Controllers\Controller;
use App\Jobs\RunAgentJob;
use App\Models\AiRun;
use App\Models\ContentPlan;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * O plano da semana (planner). Mesmo contrato dos agentes: 202 + polling, guardas
 * na ordem pre-condicao (422) → concorrencia (409) → orcamento (402).
 */
class WeekPlanController extends Controller
{
    /** O plano mais recente do projeto, com quantas pecas ja foram escritas dele. */
    public function show(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        $plano = ContentPlan::query()
            ->whereIn('strategy_id', $project->strategies()->select('id'))
            ->withCount('contents')
            ->latest('id')
            ->first();

        return response()->json(['data' => $plano]);
    }

    public function generate(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('update', $project);

        $max = (int) config('ai.agents.planner.max_posts');
        $data = $request->validate([
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'posts' => ['nullable', 'integer', 'min:1', "max:{$max}"],
        ]);

        // "Hoje" no fuso da marca: uma semana que ja comecou nao e planejada.
        $hoje = CarbonImmutable::now($project->timezone)->toDateString();
        if ($data['starts_on'] < $hoje) {
            return response()->json([
                'message' => 'A semana precisa começar hoje ou depois.',
                'errors' => ['starts_on' => ['A semana precisa começar hoje ou depois.']],
            ], 422);
        }

        $strategy = $project->strategies()->where('status', 'active')->latest()->first();
        if ($strategy === null) {
            return response()->json(['message' => 'Aprove uma estratégia antes de planejar a semana.'], 422);
        }

        if ($this->emAndamento($project)) {
            return response()->json(['message' => 'Já existe um planejamento em andamento para este projeto.'], 409);
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
                'agent' => 'planner',
                'provider' => config('ai.provider'),
                'model' => config('ai.agents.planner.model'),
                'status' => 'queued',
                'input' => [
                    'strategy_id' => $strategy->id,
                    'week_starts_on' => $data['starts_on'],
                    'posts' => $data['posts'] ?? (int) config('ai.agents.planner.default_posts'),
                    // O plano nao repete o que existe e corrige o pilar atrasado.
                    'with_existing_contents' => true,
                    'with_pillar_adherence' => true,
                    // O que a Meta mediu, congelado como no agente results. Ausente sem amostra.
                    ...$this->resultados($project),
                ],
                'created_by' => $request->user()->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['message' => 'Já existe um planejamento em andamento para este projeto.'], 409);
        }

        RunAgentJob::dispatch($run->id);

        return response()->json(['ai_run_id' => $run->id], 202);
    }

    /**
     * O resumo dos resultados dos ultimos 30 dias para o planner: medias por pilar e
     * por formato e os melhores posts. Sem a lista post a post (o planner le o resumo,
     * nao o extrato). Abaixo do minimo do agente results, nada: seria palpite.
     */
    private function resultados(Project $project): array
    {
        $r = Performance::for($project, 30);

        if ($r['measured'] < (int) config('ai.agents.results.min_measured')) {
            return [];
        }

        return ['results' => [
            'days' => $r['days'],
            'measured' => $r['measured'],
            'engagement_rate' => $r['engagement_rate'],
            'by_pillar' => $r['by_pillar'],
            'by_format' => $r['by_format'],
            'top' => array_map(fn (array $p) => [
                'title' => $p['title'],
                'pillar' => $p['pillar'],
                'format' => $p['format'],
                'reach' => $p['metrics']['reach'] ?? null,
                'total_interactions' => $p['metrics']['total_interactions'] ?? null,
            ], $r['top']),
        ]];
    }

    private function emAndamento(Project $project): bool
    {
        return AiRun::withoutGlobalScopes()
            ->where('project_id', $project->id)
            ->where('agent', 'planner')
            ->whereIn('status', ['queued', 'running'])
            ->exists();
    }
}
