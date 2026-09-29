<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Leitura dos resultados REAIS por IA (Etapa 5c), o 11o agente: `results`.
 *
 * O relatorio mora em `analytics_reports`, mas separado por `kind`: `editorial` (o
 * do analytics, sobre o calendario) e `results` (sobre o que a Meta mediu). A tela
 * Insights so le o editorial; a Resultados, so o de resultados. Sem `score` no de
 * resultados: nota de desempenho com amostra pequena seria arbitraria.
 */
return new class extends Migration
{
    private const AGENTS = [
        'strategist', 'copywriter', 'social_media', 'designer', 'seo', 'analytics',
        'reviewer', 'rewriter', 'image', 'planner', 'repurposer', 'results',
    ];

    public function up(): void
    {
        $this->check(self::AGENTS);

        Schema::table('analytics_reports', function (Blueprint $table) {
            $table->string('kind', 20)->default('editorial')->after('ai_run_id');
            $table->unsignedSmallInteger('score')->nullable()->change();
            $table->index(['project_id', 'kind', 'id']);
        });
    }

    public function down(): void
    {
        DB::table('analytics_reports')->where('kind', 'results')->delete();

        Schema::table('analytics_reports', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'kind', 'id']);
            $table->dropColumn('kind');
            $table->unsignedSmallInteger('score')->nullable(false)->change();
        });

        DB::statement("DELETE FROM ai_runs WHERE agent = 'results'");
        $this->check(array_values(array_diff(self::AGENTS, ['results'])));
    }

    private function check(array $agentes): void
    {
        $lista = collect($agentes)->map(fn (string $a) => "'{$a}'")->implode(', ');
        DB::statement('ALTER TABLE ai_runs DROP CONSTRAINT IF EXISTS ai_runs_agent_check');
        DB::statement("ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_agent_check CHECK (agent::text = ANY (ARRAY[{$lista}]::text[]))");
    }
};
