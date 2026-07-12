<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O fuso e do PROJETO, nao do workspace: uma agencia atende clientes em fusos
 * diferentes, e quem publica e a marca, no horario do publico dela.
 *
 * Sem isso o social_media escolhia "08:30" pensando no horario local e o valor
 * era gravado como UTC — toda peca ia ao ar 3h mais cedo (uma caiu em 05:30 da
 * manha). Ver SocialMediaAgent::persist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('timezone', 64)->default('America/Sao_Paulo')->after('segment');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
