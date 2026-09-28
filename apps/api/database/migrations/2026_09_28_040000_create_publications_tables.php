<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * O registro da automacao (ADR-13). `publications` e o que o sistema fez em nome de
 * quem aprovou; `publication_attempts` e cada conversa com a Meta no caminho.
 *
 * A publicacao guarda um SNAPSHOT do que foi aprovado — legenda, imagem, aprovador,
 * conta, horario — e nao so ponteiros: se a peca mudar depois, o historico continua
 * dizendo o que foi ao ar.
 */
return new class extends Migration
{
    public const STATUSES = ['pending', 'publishing', 'published', 'failed', 'unknown', 'cancelled'];

    public function up(): void
    {
        Schema::create('publications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('content_id')->constrained()->cascadeOnDelete();
            // Nulos quando a publicacao ja nasce recusada (sem conta, sem imagem).
            $table->foreignId('instagram_account_id')->nullable()->constrained();
            $table->foreignId('asset_id')->nullable()->constrained();

            // O snapshot do que o humano aprovou.
            $table->text('caption');
            $table->text('image_url')->nullable();
            $table->string('account_username', 100)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('scheduled_for');

            $table->enum('status', self::STATUSES);
            $table->string('container_id', 64)->nullable();
            $table->string('media_id', 64)->nullable();
            $table->text('permalink')->nullable();
            $table->timestampTz('published_at')->nullable();

            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('next_attempt_at')->nullable();
            $table->string('error_kind', 20)->nullable();
            $table->text('last_error')->nullable();
            $table->timestampsTz();

            $table->index(['project_id', 'scheduled_for']);
            $table->index(['status', 'next_attempt_at']);

            // Idempotencia do disparo: uma publicacao por peca POR HORARIO. O agendador
            // pode rodar duas vezes no mesmo minuto; o banco aceita so a primeira.
            // Remarcar a peca abre um horario novo — e e assim que se tenta de novo.
            $table->unique(['content_id', 'scheduled_for']);
        });

        // E, acima de tudo: nunca duas publicacoes vivas da mesma peca.
        DB::statement("CREATE UNIQUE INDEX publications_one_live_per_content ON publications (content_id) WHERE status IN ('pending', 'publishing', 'published', 'unknown')");

        Schema::create('publication_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('publication_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            // quota, container, status, publish, reconcile, gate
            $table->string('step', 20);
            // success, waiting, transient, permanent, auth, unknown, refused
            $table->string('outcome', 20);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->integer('meta_code')->nullable();
            $table->integer('meta_subcode')->nullable();
            $table->text('message')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['publication_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publication_attempts');
        Schema::dropIfExists('publications');
    }
};
