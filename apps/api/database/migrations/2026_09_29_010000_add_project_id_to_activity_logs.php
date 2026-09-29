<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O registro de atividade existia desde a fundacao e ninguem escrevia nele. Passa a
 * guardar os gestos humanos que nao deixavam outro rastro (desconectar o Instagram,
 * decidir uma publicacao a mao, apagar uma imagem), e a tela os mostra por projeto.
 *
 * `project_id` nullable: um gesto de workspace (convite, papel) nao tem projeto. A
 * tabela esta vazia em todo ambiente, entao nao ha o que preencher.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('workspace_id')->constrained()->cascadeOnDelete();
            $table->index(['project_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
        });
    }
};
