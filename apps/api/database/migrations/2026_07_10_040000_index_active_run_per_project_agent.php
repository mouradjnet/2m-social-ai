<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * No maximo uma execucao ativa por projeto E POR AGENTE.
     *
     * Antes o indice era so (project_id): uma geracao de estrategia bloqueava uma
     * de copy, e vice-versa — acoplamento invisivel. Agora cada agente corre
     * independente; dois runs de agentes diferentes coexistem no mesmo projeto.
     */
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS ai_runs_one_active_per_project');
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX ai_runs_one_active_per_project_agent
                ON ai_runs (project_id, agent)
                WHERE status IN ('queued', 'running')
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ai_runs_one_active_per_project_agent');
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX ai_runs_one_active_per_project
                ON ai_runs (project_id)
                WHERE status IN ('queued', 'running')
        SQL);
    }
};
