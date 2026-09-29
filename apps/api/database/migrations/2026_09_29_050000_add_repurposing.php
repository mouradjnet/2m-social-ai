<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reaproveitamento de conteudo (Etapa 2d).
 *
 * - `repurposer`, o 10o agente: adapta uma peca existente a outro formato ou canal.
 * - `contents.repurposed_from_id`: a peca nova lembra de onde veio. Nulo se a
 *   original for apagada — a derivada continua valendo sozinha.
 */
return new class extends Migration
{
    private const AGENTS = [
        'strategist', 'copywriter', 'social_media',
        'designer', 'seo', 'analytics', 'reviewer', 'rewriter', 'image', 'planner', 'repurposer',
    ];

    public function up(): void
    {
        $this->check(self::AGENTS);

        Schema::table('contents', function (Blueprint $table) {
            $table->foreignId('repurposed_from_id')->nullable()->after('content_plan_id')->constrained('contents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('repurposed_from_id');
        });

        DB::statement("DELETE FROM ai_runs WHERE agent = 'repurposer'");
        $this->check(array_values(array_diff(self::AGENTS, ['repurposer'])));
    }

    private function check(array $agentes): void
    {
        $lista = collect($agentes)->map(fn (string $a) => "'{$a}'")->implode(', ');
        DB::statement('ALTER TABLE ai_runs DROP CONSTRAINT IF EXISTS ai_runs_agent_check');
        DB::statement("ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_agent_check CHECK (agent::text = ANY (ARRAY[{$lista}]::text[]))");
    }
};
