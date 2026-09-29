<?php

namespace App\Ai;

use App\Models\AiRun;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orcamento mensal por workspace, verificado ANTES de enfileirar o job — nao
 * depois de gastar. Estourou, a rota devolve 402.
 *
 * O teto e `workspaces.monthly_budget_cents` quando o operador o fixou
 * (`php artisan workspace:budget`), e o padrao do `config/ai.php` quando nao. Nao
 * ha rota que o mude: e a trava que protege a chave de quem paga a IA, e o dono de
 * um workspace cliente nao pode subir o proprio teto.
 */
class Budget
{
    public static function spentCentsThisMonth(Workspace $workspace): int
    {
        return (int) AiRun::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('cost_cents');
    }

    public static function limitCents(Workspace $workspace): int
    {
        return $workspace->monthly_budget_cents ?? (int) config('ai.workspace_monthly_budget_cents');
    }

    public static function exceeded(Workspace $workspace): bool
    {
        return self::spentCentsThisMonth($workspace) >= self::limitCents($workspace);
    }

    public static function projectSpentCentsThisMonth(int $projectId): int
    {
        return (int) AiRun::withoutGlobalScopes()
            ->where('project_id', $projectId)
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('cost_cents');
    }

    /** O teto por marca (config `ai.project_monthly_budget_cents`); null = so o do workspace. */
    public static function projectLimitCents(): ?int
    {
        return config('ai.project_monthly_budget_cents');
    }

    /**
     * A porta de toda rota que enfileira IA, antes de enfileirar. Devolve o 402
     * pronto, ou null para seguir. O teto do workspace vem primeiro: e a trava da
     * chave; o do projeto so impede uma marca de gastar o mes das outras.
     */
    public static function refusal(Project $project): ?JsonResponse
    {
        $workspace = $project->workspace;

        if (self::exceeded($workspace)) {
            return response()->json([
                'message' => 'Orçamento mensal de IA esgotado para este espaço de trabalho.',
                'spent_cents' => self::spentCentsThisMonth($workspace),
                'limit_cents' => self::limitCents($workspace),
            ], 402);
        }

        $limite = self::projectLimitCents();
        if ($limite !== null && self::projectSpentCentsThisMonth($project->id) >= $limite) {
            return response()->json([
                'message' => 'Orçamento mensal de IA esgotado para este projeto.',
                'spent_cents' => self::projectSpentCentsThisMonth($project->id),
                'limit_cents' => $limite,
            ], 402);
        }

        return null;
    }

    /**
     * Depois de gravar o custo: avisa no log o que foge do normal. Nao bloqueia
     * nada (quem bloqueia e o refusal(), antes da proxima chamada) e nao expoe
     * dado da marca, so ids e numeros.
     */
    public static function alertIfAbnormal(AiRun $run): void
    {
        $custo = (int) $run->cost_cents;

        if ($custo >= (int) config('ai.alert_run_cost_cents')) {
            Log::warning('IA: execucao com custo acima do alerta.', [
                'ai_run_id' => $run->id, 'agent' => $run->agent, 'cost_cents' => $custo,
                'alert_cents' => (int) config('ai.alert_run_cost_cents'),
            ]);
        }

        if ($custo === 0) {
            return;
        }

        $fracao = (float) config('ai.alert_budget_fraction');
        $workspace = Workspace::find($run->workspace_id);
        $gasto = self::spentCentsThisMonth($workspace);
        $limite = self::limitCents($workspace);

        // Avisa so na execucao que CRUZOU a fracao, nao em todas depois dela.
        if ($limite > 0 && $gasto >= $limite * $fracao && $gasto - $custo < $limite * $fracao) {
            Log::warning('IA: workspace passou do alerta de consumo do mes.', [
                'workspace_id' => $workspace->id, 'spent_cents' => $gasto, 'limit_cents' => $limite,
            ]);
        }

        $limiteProjeto = self::projectLimitCents();
        if ($limiteProjeto !== null && $run->project_id !== null) {
            $gastoProjeto = self::projectSpentCentsThisMonth($run->project_id);

            if ($gastoProjeto >= $limiteProjeto * $fracao && $gastoProjeto - $custo < $limiteProjeto * $fracao) {
                Log::warning('IA: projeto passou do alerta de consumo do mes.', [
                    'project_id' => $run->project_id, 'spent_cents' => $gastoProjeto, 'limit_cents' => $limiteProjeto,
                ]);
            }
        }
    }

    /**
     * O consumo do mes, por agente e por projeto. Execucoes sem custo (o mock, as
     * que falharam antes de chamar a API) contam como execucao, com 0 centavo.
     *
     * @return array{month: string, spent_cents: int, limit_cents: int, limit_source: string, by_agent: list<array{agent: string, runs: int, cost_cents: int}>, by_project: list<array{project_id: int|null, name: string|null, runs: int, cost_cents: int}>}
     */
    public static function usage(Workspace $workspace): array
    {
        $doMes = fn () => AiRun::withoutGlobalScopes()
            ->where('ai_runs.workspace_id', $workspace->id)
            ->where('ai_runs.created_at', '>=', now()->startOfMonth());

        $porAgente = $doMes()
            ->select('agent', DB::raw('count(*) as runs'), DB::raw('coalesce(sum(cost_cents), 0) as cost_cents'))
            ->groupBy('agent')
            ->orderByDesc('cost_cents')
            ->get()
            ->map(fn ($l) => ['agent' => $l->agent, 'runs' => (int) $l->runs, 'cost_cents' => (int) $l->cost_cents])
            ->all();

        $porProjeto = $doMes()
            ->leftJoin('projects', 'projects.id', '=', 'ai_runs.project_id')
            ->select('ai_runs.project_id', 'projects.name', DB::raw('count(*) as runs'), DB::raw('coalesce(sum(ai_runs.cost_cents), 0) as cost_cents'))
            ->groupBy('ai_runs.project_id', 'projects.name')
            ->orderByDesc('cost_cents')
            ->get()
            ->map(fn ($l) => [
                'project_id' => $l->project_id,
                'name' => $l->name,
                'runs' => (int) $l->runs,
                'cost_cents' => (int) $l->cost_cents,
            ])
            ->all();

        return [
            'month' => now()->format('Y-m'),
            'spent_cents' => self::spentCentsThisMonth($workspace),
            'limit_cents' => self::limitCents($workspace),
            'limit_source' => $workspace->monthly_budget_cents === null ? 'default' : 'workspace',
            'by_agent' => $porAgente,
            'by_project' => $porProjeto,
        ];
    }
}
