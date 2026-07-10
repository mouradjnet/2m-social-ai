<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * No maximo uma execucao ativa por projeto.
     *
     * O 409 no controller e uma checagem antes do insert: duas requisicoes
     * simultaneas passariam as duas. Este indice parcial e a garantia real, no
     * unico lugar que consegue dar essa garantia. Sem ele, dois `curl` disparados
     * junto criam duas execucoes e cobram duas vezes do orcamento.
     *
     * `project_id` e nullable e NULLs sao distintos no Postgres, entao execucoes
     * sem projeto nao colidem entre si.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX ai_runs_one_active_per_project
                ON ai_runs (project_id)
                WHERE status IN ('queued', 'running')
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ai_runs_one_active_per_project');
    }
};
