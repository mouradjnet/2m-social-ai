<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * No maximo UMA estrategia ativa por projeto.
     *
     * O dominio inteiro fala em "a estrategia ativa", no singular, e todo mundo le
     * `->where('status', 'active')->latest()->first()`. Mas aprovar uma estrategia
     * nao rebaixava a anterior: bastava um PATCH `status: active` numa segunda para
     * o projeto ficar com duas — e aconteceu em producao, onde a estrategia do mock
     * (pilares Educacao/Prova social/Bastidores) conviveu com a real. Passou
     * despercebido por SORTE: a real era a mais nova, e o `latest()` a escolhia.
     *
     * Se a ordem fosse outra, o copywriter escreveria pecas para pilares que nao
     * existem no plano da marca — e o guard de aderencia (6d900c9) miraria um pilar
     * fantasma. O controller passa a arquivar as outras ao aprovar; este indice e a
     * rede: o banco recusa o estado invalido mesmo que um caminho novo esqueca a
     * regra. Mesmo padrao do `ai_runs_one_active_per_project_agent`.
     */
    public function up(): void
    {
        // Um banco que ja tenha duas ativas faria o indice falhar no deploy. Mantem a
        // mais recente (que e a que o `latest()` ja vinha usando) e arquiva o resto:
        // a migration nao pode depender de alguem ter limpado os dados antes.
        DB::statement(<<<'SQL'
            UPDATE strategies s
               SET status = 'archived'
             WHERE s.status = 'active'
               AND s.id <> (
                   SELECT mais_nova.id
                     FROM strategies mais_nova
                    WHERE mais_nova.project_id = s.project_id
                      AND mais_nova.status = 'active'
                    ORDER BY mais_nova.created_at DESC, mais_nova.id DESC
                    LIMIT 1
               )
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX strategies_one_active_per_project
                ON strategies (project_id)
                WHERE status = 'active'
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS strategies_one_active_per_project');
    }
};
