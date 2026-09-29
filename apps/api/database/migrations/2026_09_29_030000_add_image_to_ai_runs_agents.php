<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `image`: a geracao de imagem por IA (ADR-15) grava em `ai_runs` como os agentes
 * de texto. Nao e um agente de LLM — e o que a faz entrar no MESMO orcamento
 * mensal, no mesmo 409 de concorrencia e na mesma tela de consumo.
 *
 * O `enum()` do Laravel vira varchar + CHECK no Postgres; estender e trocar o check.
 */
return new class extends Migration
{
    private const AGENTS = [
        'strategist', 'copywriter', 'social_media',
        'designer', 'seo', 'analytics', 'reviewer', 'rewriter', 'image',
    ];

    public function up(): void
    {
        $this->check(self::AGENTS);
    }

    public function down(): void
    {
        DB::statement("DELETE FROM ai_runs WHERE agent = 'image'");
        $this->check(array_values(array_diff(self::AGENTS, ['image'])));
    }

    private function check(array $agentes): void
    {
        $lista = collect($agentes)->map(fn (string $a) => "'{$a}'")->implode(', ');
        DB::statement('ALTER TABLE ai_runs DROP CONSTRAINT IF EXISTS ai_runs_agent_check');
        DB::statement("ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_agent_check CHECK (agent::text = ANY (ARRAY[{$lista}]::text[]))");
    }
};
