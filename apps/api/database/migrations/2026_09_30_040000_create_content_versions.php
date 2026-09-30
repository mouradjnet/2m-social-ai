<?php

use App\Domain\Editorial\Versioning;
use App\Models\Content;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CP-04C (historico e versoes). So acrescimo:
 *
 * - content_versions: uma linha por versao de cada peca, com o SNAPSHOT do que vai ao
 *   ar (mesmo formato e mesmo sha256 da aprovacao) e os metadados das midias. E o que
 *   permite ver, comparar e restaurar versoes antigas — antes so havia o numero.
 * - unico (content_id, version): duas gravacoes da mesma versao nao passam.
 * - trigger: UPDATE e recusado no banco (a versao e imutavel). DELETE fica para o
 *   cascade da peca, que nenhuma rota apaga.
 * - backfill: cada peca existente ganha a versao ATUAL (origin `backfill`). As versoes
 *   anteriores delas nunca foram gravadas e nao sao reconstruiveis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('content_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->jsonb('snapshot');
            $table->char('snapshot_hash', 64);
            $table->jsonb('media')->nullable();
            $table->string('origin', 30);
            $table->unsignedInteger('restored_from_version')->nullable();
            $table->boolean('invalidated_approval')->default(false);
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->foreignId('ai_run_id')->nullable()->constrained('ai_runs')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['content_id', 'version']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION content_versions_imutavel() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'content_versions e imutavel';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER content_versions_sem_update BEFORE UPDATE ON content_versions
                FOR EACH ROW EXECUTE FUNCTION content_versions_imutavel();
            SQL);

        Content::withoutGlobalScopes()->orderBy('id')->chunkById(200, function ($pecas) {
            foreach ($pecas as $peca) {
                Versioning::como('backfill', null, fn () => Versioning::record($peca));
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_versions');
        DB::unprepared('DROP FUNCTION IF EXISTS content_versions_imutavel()');
    }
};
