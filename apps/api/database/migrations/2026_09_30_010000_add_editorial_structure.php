<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CP-03 (producao editorial). Duas colunas jsonb OPCIONAIS, so acrescimo: nada e
 * apagado nem convertido, e o que ja existe fica com null (a tela mostra como antes).
 *
 * - strategies.guidelines: objetivos, temas, formatos, frequencia semanal e a
 *   mistura educativo/institucional/comercial que a estrategia recomenda.
 * - contents.structure: o ROTEIRO por formato (slides do carrossel, telas do
 *   Stories, cenas do Reels, proposta visual do post). E texto, nao midia: a midia
 *   de verdade continua em assets/content_assets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('strategies', function (Blueprint $table) {
            $table->jsonb('guidelines')->nullable()->after('pillars');
        });

        Schema::table('contents', function (Blueprint $table) {
            $table->jsonb('structure')->nullable()->after('image_prompt');
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->dropColumn('structure');
        });

        Schema::table('strategies', function (Blueprint $table) {
            $table->dropColumn('guidelines');
        });
    }
};
