<?php

namespace App\Ai;

use App\Models\AiRun;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

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
