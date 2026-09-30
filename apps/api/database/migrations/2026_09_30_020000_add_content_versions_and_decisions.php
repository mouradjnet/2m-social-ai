<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CP-04 (aprovacao humana). So acrescimo:
 *
 * - contents.version: sobe a cada mudanca no que vai ao ar. A aprovacao vale para
 *   UMA versao. Pecas existentes comecam na 1.
 * - content_decisions: append-only (nada e editado nem apagado). Cada decisao humana
 *   — aprovar, rejeitar, pedir ajustes — com versao, justificativa, quem e quando.
 *   A aprovacao guarda o SNAPSHOT do que foi aprovado e o sha256 dele: e o que a
 *   porta de publicacao compara com a peca atual.
 * - publications.content_version / decision_id: a publicacao nasce presa a versao e
 *   a aprovacao exatas. Nulas nas publicacoes antigas.
 *
 * Pecas aprovadas antes daqui nao tem snapshot: por seguranca (fail-closed) precisam
 * ser aprovadas de novo antes de publicar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1)->after('status');
        });

        Schema::create('content_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('content_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->enum('decision', ['approved', 'rejected', 'changes_requested']);
            $table->text('reason')->nullable();
            $table->string('from_status', 20);
            $table->string('to_status', 20);
            $table->jsonb('snapshot')->nullable();
            $table->char('snapshot_hash', 64)->nullable();
            $table->foreignId('user_id')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['content_id', 'id']);
            $table->index(['project_id', 'created_at']);
        });

        Schema::table('publications', function (Blueprint $table) {
            $table->unsignedInteger('content_version')->nullable()->after('content_id');
            $table->foreignId('decision_id')->nullable()->after('content_version')->constrained('content_decisions');
        });
    }

    public function down(): void
    {
        Schema::table('publications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('decision_id');
            $table->dropColumn('content_version');
        });

        Schema::dropIfExists('content_decisions');

        Schema::table('contents', function (Blueprint $table) {
            $table->dropColumn('version');
        });
    }
};
