<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Planejamento semanal assistido por IA (Etapa 2c).
 *
 * - `planner`, o 9o agente: propoe os horarios da semana (dia, hora, pilar, formato,
 *   tema) e grava em `content_plans`, tabela que existe desde a fundacao e nunca
 *   tinha sido escrita.
 * - `contents.content_plan_id` + `contents.planned_for`: a peca escrita a partir do
 *   plano lembra de qual horario saiu. `planned_for` e SUGESTAO — agendar continua
 *   sendo gesto humano (ou do social_media) sobre uma peca aprovada (ADR-13).
 */
return new class extends Migration
{
    private const AGENTS = [
        'strategist', 'copywriter', 'social_media',
        'designer', 'seo', 'analytics', 'reviewer', 'rewriter', 'image', 'planner',
    ];

    public function up(): void
    {
        $this->check(self::AGENTS);

        Schema::table('contents', function (Blueprint $table) {
            $table->foreignId('content_plan_id')->nullable()->after('origin_ai_run_id')->constrained()->nullOnDelete();
            $table->timestampTz('planned_for')->nullable()->after('scheduled_for');
            $table->index('content_plan_id');
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('content_plan_id');
            $table->dropColumn('planned_for');
        });

        DB::statement("DELETE FROM ai_runs WHERE agent = 'planner'");
        $this->check(array_values(array_diff(self::AGENTS, ['planner'])));
    }

    private function check(array $agentes): void
    {
        $lista = collect($agentes)->map(fn (string $a) => "'{$a}'")->implode(', ');
        DB::statement('ALTER TABLE ai_runs DROP CONSTRAINT IF EXISTS ai_runs_agent_check');
        DB::statement("ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_agent_check CHECK (agent::text = ANY (ARRAY[{$lista}]::text[]))");
    }
};
