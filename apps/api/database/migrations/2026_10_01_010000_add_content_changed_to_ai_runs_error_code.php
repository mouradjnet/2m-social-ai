<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * CP-04D: `content_changed` — a peca mudou (edicao humana) enquanto a IA a
     * reescrevia; o resultado foi descartado para nao sobrescrever a edicao. Nao e
     * retentavel as cegas: a pessoa precisa ver a versao nova antes de pedir de novo.
     *
     * O `enum` do Laravel no Postgres e varchar + CHECK; troca-se so o CHECK.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE ai_runs DROP CONSTRAINT ai_runs_error_code_check');
        DB::statement("ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_error_code_check CHECK (error_code IN ('refused', 'rejected_output', 'provider_failed', 'content_changed'))");
    }

    public function down(): void
    {
        DB::statement("UPDATE ai_runs SET error_code = 'provider_failed' WHERE error_code = 'content_changed'");
        DB::statement('ALTER TABLE ai_runs DROP CONSTRAINT ai_runs_error_code_check');
        DB::statement("ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_error_code_check CHECK (error_code IN ('refused', 'rejected_output', 'provider_failed'))");
    }
};
