<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * O 8o agente: `rewriter`.
     *
     * Ate aqui o reviewer reprovava e o fluxo PARAVA: o humano arquivava a peca e
     * pedia um lote inteiro novo — jogando fora as outras quatro, que estavam boas, e
     * pagando 6 centavos para consertar uma. O reviewer era um juiz que so condena.
     *
     * O `enum()` do Laravel vira varchar + CHECK no Postgres; estender e trocar o
     * check, nao a coluna.
     */
    private const AGENTS = [
        'strategist', 'copywriter', 'social_media',
        'designer', 'seo', 'analytics', 'reviewer', 'rewriter',
    ];

    public function up(): void
    {
        $lista = collect(self::AGENTS)->map(fn (string $a) => "'{$a}'")->implode(', ');

        DB::statement('ALTER TABLE ai_runs DROP CONSTRAINT IF EXISTS ai_runs_agent_check');
        DB::statement("ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_agent_check CHECK (agent::text = ANY (ARRAY[{$lista}]::text[]))");
    }

    public function down(): void
    {
        $semRewriter = collect(self::AGENTS)
            ->reject(fn (string $a) => $a === 'rewriter')
            ->map(fn (string $a) => "'{$a}'")
            ->implode(', ');

        // Um run de `rewriter` gravado impediria o check antigo de voltar.
        DB::statement("DELETE FROM ai_runs WHERE agent = 'rewriter'");
        DB::statement('ALTER TABLE ai_runs DROP CONSTRAINT IF EXISTS ai_runs_agent_check');
        DB::statement("ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_agent_check CHECK (agent::text = ANY (ARRAY[{$semRewriter}]::text[]))");
    }
};
