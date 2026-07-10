<?php

namespace App\Ai;

use App\Models\AiRun;
use App\Models\Workspace;

/**
 * Orcamento mensal por workspace, verificado ANTES de enfileirar o job — nao
 * depois de gastar. Estourou, a rota devolve 402.
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

    public static function limitCents(): int
    {
        return (int) config('ai.workspace_monthly_budget_cents');
    }

    public static function exceeded(Workspace $workspace): bool
    {
        return self::spentCentsThisMonth($workspace) >= self::limitCents();
    }
}
